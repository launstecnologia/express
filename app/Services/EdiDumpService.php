<?php

namespace App\Services;

use App\Models\EdiDump;
use App\Models\EdiDumpDia;
use App\Models\EdiDumpLinha;
use App\Models\Estabelecimento;
use App\Support\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EdiDumpService
{
    /**
     * Percorre todos os dias da competência, baixa cada página do EDI e grava as linhas cruas.
     */
    public function executar(EdiDump $dump): void
    {
        if (! PlatformSettings::ediConfigurado()) {
            $dump->update([
                'status' => 'erro',
                'erro' => 'Credenciais EDI não configuradas.',
                'finalizado_em' => now(),
            ]);

            return;
        }

        $inicio = $dump->competencia->copy()->startOfMonth();
        $fim = $dump->competencia->copy()->endOfMonth();
        $tokens = $this->mapaEstabelecimentosPorToken();

        $dump->update([
            'status' => 'processando',
            'iniciado_em' => $dump->iniciado_em ?? now(),
            'total_dias' => $inicio->diffInDays($fim) + 1,
            'erro' => null,
        ]);

        for ($data = $inicio->copy(); $data->lte($fim); $data->addDay()) {
            $this->importarDia($dump, $data, $tokens);
            $this->atualizarTotais($dump);
        }

        $this->atualizarTotais($dump);

        $dump->update([
            'status' => ((int) $dump->dias_erro) > 0 ? 'erro' : 'concluido',
            'finalizado_em' => now(),
        ]);
    }

    /**
     * @return array{quantidade: int, valor_total: float, valor_liquido: float, estabelecimento_id: ?int, estabelecimento: ?string, nome: ?string}
     */
    public function somarPorId(EdiDump $dump, string $id): array
    {
        $id = trim($id);
        $query = EdiDumpLinha::query()->where('dump_id', $dump->id);

        if (ctype_digit($id)) {
            $query->where(function ($q) use ($id) {
                $q->where('estabelecimento_id', (int) $id)
                    ->orWhere('estabelecimento', $id);
            });
        } else {
            $query->where('estabelecimento', $id);
        }

        $totais = (clone $query)->selectRaw('
            COUNT(*) as quantidade,
            COALESCE(SUM(valor_total_transacao), 0) as valor_total,
            COALESCE(SUM(valor_liquido_transacao), 0) as valor_liquido
        ')->first();

        $estabelecimento = null;
        if (ctype_digit($id)) {
            $estabelecimento = Estabelecimento::withoutGlobalScopes()->find((int) $id);
        }
        if (! $estabelecimento) {
            $estabelecimento = Estabelecimento::withoutGlobalScopes()
                ->where('token_pagseguro', $id)
                ->first();
        }

        return [
            'quantidade' => (int) ($totais->quantidade ?? 0),
            'valor_total' => (float) ($totais->valor_total ?? 0),
            'valor_liquido' => (float) ($totais->valor_liquido ?? 0),
            'estabelecimento_id' => $estabelecimento?->id,
            'estabelecimento' => $estabelecimento?->token_pagseguro ?: (ctype_digit($id) ? null : $id),
            'nome' => $estabelecimento
                ? ($estabelecimento->nome_fantasia ?: $estabelecimento->razao_social ?: $estabelecimento->nome_completo)
                : null,
        ];
    }

    /**
     * @param  array<string, int>  $tokens
     */
    private function importarDia(EdiDump $dump, CarbonInterface $data, array $tokens): void
    {
        $diaStr = $data->format('Y-m-d');

        $dia = EdiDumpDia::query()->updateOrCreate(
            ['dump_id' => $dump->id, 'data' => $diaStr],
            ['status' => 'pendente', 'paginas' => 0, 'total_itens_api' => 0, 'linhas' => 0, 'motivo' => null],
        );

        try {
            $response = $this->cliente()->get("/movement/v3.00/transactional/{$diaStr}", $this->query(1));
        } catch (\Throwable $e) {
            $dia->update([
                'status' => 'erro',
                'motivo' => mb_substr($e->getMessage(), 0, 255),
            ]);
            Log::error('EDI dump: falha HTTP no dia', ['dump_id' => $dump->id, 'data' => $diaStr, 'erro' => $e->getMessage()]);

            return;
        }

        if ($response->failed()) {
            $dia->update([
                'status' => 'erro',
                'motivo' => 'http_'.$response->status(),
            ]);

            return;
        }

        if (! $this->ediValidado($response)) {
            $dia->update([
                'status' => 'nao_validado',
                'paginas' => 0,
                'motivo' => 'Arquivo ainda não validado pelo PagBank',
            ]);

            return;
        }

        $pagina = 1;
        $linhasDia = 0;
        $payload = $response->json() ?? [];
        $meta = $this->paginacao($payload, 1, 0);

        while (true) {
            if ($pagina > 1) {
                $payload = $this->baixarPagina($diaStr, $pagina);
                $metaPagina = $this->paginacao($payload, $pagina, 0);
                if ($metaPagina['total_itens'] > 0) {
                    $meta['total_itens'] = $metaPagina['total_itens'];
                }
                if ($metaPagina['total_paginas'] > 0) {
                    $meta['total_paginas'] = $metaPagina['total_paginas'];
                }
            }

            $registros = $this->extrairRegistros($payload);
            $linhasDia += $this->gravarLinhas($dump, $dia, $diaStr, $pagina, $registros, $tokens);

            $quantidade = count($registros);

            if ($meta['total_paginas'] === 0) {
                $meta = $this->paginacao($payload, $pagina, $quantidade);
            }

            if (! $this->temProximaPagina($payload, $pagina, $quantidade, $meta['total_paginas'])) {
                break;
            }

            $pagina++;
        }

        $paginasPercorridas = $pagina;
        if ($meta['total_paginas'] === 0) {
            $meta['total_paginas'] = $paginasPercorridas;
        }

        $dia->update([
            'status' => $linhasDia === 0 ? 'vazio' : 'ok',
            'paginas' => $paginasPercorridas,
            'total_itens_api' => $meta['total_itens'],
            'linhas' => $linhasDia,
            'motivo' => null,
        ]);
    }

    /**
     * @param  array<string, int>  $tokens
     * @param  list<array<string, mixed>>  $registros
     */
    private function gravarLinhas(EdiDump $dump, EdiDumpDia $dia, string $data, int $pagina, array $registros, array $tokens): int
    {
        if ($registros === []) {
            return 0;
        }

        $agora = now();
        $lote = [];

        foreach ($registros as $registro) {
            $codigoPagbank = trim((string) Arr::get($registro, 'estabelecimento', ''));

            $dataTx = Arr::get($registro, 'data_inicial_transacao');

            $lote[] = [
                'dump_id' => $dump->id,
                'dia_id' => $dia->id,
                'data_referencia' => $data,
                'pagina' => $pagina,
                'estabelecimento' => $codigoPagbank !== '' ? $codigoPagbank : null,
                'estabelecimento_id' => $codigoPagbank !== '' ? ($tokens[$codigoPagbank] ?? null) : null,
                'movimento_api_codigo' => Arr::get($registro, 'movimento_api_codigo') ?: null,
                'data_inicial_transacao' => filled($dataTx) ? $dataTx : null,
                'tipo_transacao' => Arr::get($registro, 'tipo_transacao') ?: null,
                'status_pagamento' => Arr::get($registro, 'status_pagamento') ?: null,
                'valor_total_transacao' => Arr::get($registro, 'valor_total_transacao'),
                'valor_liquido_transacao' => Arr::get($registro, 'valor_liquido_transacao'),
                'nsu' => Arr::get($registro, 'nsu') ?: null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        foreach (array_chunk($lote, 250) as $chunk) {
            EdiDumpLinha::query()->insert($chunk);
        }

        return count($lote);
    }

    private function atualizarTotais(EdiDump $dump): void
    {
        $resumo = EdiDumpDia::query()
            ->where('dump_id', $dump->id)
            ->selectRaw("
                COUNT(*) as total_dias,
                SUM(CASE WHEN status IN ('ok', 'vazio') THEN 1 ELSE 0 END) as dias_ok,
                SUM(CASE WHEN status = 'nao_validado' THEN 1 ELSE 0 END) as dias_nao_validados,
                SUM(CASE WHEN status = 'erro' THEN 1 ELSE 0 END) as dias_erro,
                COALESCE(SUM(paginas), 0) as total_paginas,
                COALESCE(SUM(total_itens_api), 0) as total_itens_api,
                COALESCE(SUM(linhas), 0) as total_linhas
            ")
            ->first();

        $dump->update([
            'total_dias' => (int) ($resumo->total_dias ?? 0),
            'dias_ok' => (int) ($resumo->dias_ok ?? 0),
            'dias_nao_validados' => (int) ($resumo->dias_nao_validados ?? 0),
            'dias_erro' => (int) ($resumo->dias_erro ?? 0),
            'total_paginas' => (int) ($resumo->total_paginas ?? 0),
            'total_itens_api' => (int) ($resumo->total_itens_api ?? 0),
            'total_linhas' => (int) ($resumo->total_linhas ?? 0),
        ]);
        $dump->refresh();
    }

    /**
     * @return array<string, int>
     */
    private function mapaEstabelecimentosPorToken(): array
    {
        return Estabelecimento::withoutGlobalScopes()
            ->whereNotNull('token_pagseguro')
            ->where('token_pagseguro', '!=', '')
            ->pluck('id', 'token_pagseguro')
            ->mapWithKeys(fn ($id, $token) => [(string) $token => (int) $id])
            ->all();
    }

    private function baixarPagina(string $data, int $pagina): array
    {
        $response = $this->cliente()->get("/movement/v3.00/transactional/{$data}", $this->query($pagina));
        $response->throw();

        return $response->json() ?? [];
    }

    private function cliente(): PendingRequest
    {
        return Http::baseUrl(PlatformSettings::ediUrl())
            ->withBasicAuth(
                (string) PlatformSettings::ediUser(),
                (string) PlatformSettings::ediToken(),
            )
            ->acceptJson()
            ->timeout(60);
    }

    private function ediValidado(Response $response): bool
    {
        $validado = $response->header('VALIDADO') ?? $response->header('validado');

        return strtoupper((string) $validado) === 'TRUE';
    }

    /**
     * @return array<string, int>
     */
    private function query(int $pagina): array
    {
        return [
            'pageNumber' => $pagina,
            'pageSize' => (int) config('pagseguro.pagina_limite', 1000),
        ];
    }

    /**
     * @return array{total_paginas: int, total_itens: int}
     */
    private function paginacao(array $payload, int $pagina, int $quantidade): array
    {
        $page = Arr::get($payload, 'pagination') ?? Arr::get($payload, 'page') ?? [];
        if (! is_array($page)) {
            $page = [];
        }

        $totalPaginas = (int) ($page['totalPages'] ?? $page['total_pages'] ?? $page['totalPage'] ?? Arr::get($payload, 'totalPages') ?? 0);
        $totalItens = (int) ($page['totalElements'] ?? $page['total_elements'] ?? $page['total'] ?? Arr::get($payload, 'totalElements') ?? 0);

        if ($totalPaginas === 0 && $quantidade > 0 && $quantidade < (int) config('pagseguro.pagina_limite', 1000)) {
            $totalPaginas = $pagina;
        }

        return [
            'total_paginas' => $totalPaginas,
            'total_itens' => $totalItens,
        ];
    }

    private function temProximaPagina(array $payload, int $pagina, int $quantidade, int $totalPaginas): bool
    {
        if ($totalPaginas > 0) {
            return $pagina < $totalPaginas;
        }

        $page = Arr::get($payload, 'pagination') ?? Arr::get($payload, 'page');
        if (is_array($page)) {
            $informado = (int) ($page['totalPages'] ?? $page['total_pages'] ?? 0);
            if ($informado > 0) {
                return $pagina < $informado;
            }
        }

        return $quantidade >= (int) config('pagseguro.pagina_limite', 1000);
    }

    private function extrairRegistros(array $payload): array
    {
        $registros = Arr::get($payload, 'detalhes')
            ?? Arr::get($payload, 'movimentos')
            ?? Arr::get($payload, 'content')
            ?? Arr::get($payload, 'data')
            ?? (array_is_list($payload) ? $payload : []);

        return is_array($registros) ? $registros : [];
    }
}
