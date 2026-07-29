<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Models\Estabelecimento;
use App\Support\ConciliacaoDimensao;
use App\Support\XlsxReader;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ConciliacaoImportService
{
    private const ABA = 'Validação V2';

    public function __construct(
        private readonly ConciliacaoConfrontoService $confronto,
    ) {}

    public function importarArquivo(UploadedFile|string $arquivo, ?int $usuarioId = null, bool $confrontar = true): Conciliacao
    {
        $caminhoTemp = $arquivo instanceof UploadedFile
            ? $arquivo->getRealPath()
            : $arquivo;

        if (! is_string($caminhoTemp) || ! is_file($caminhoTemp)) {
            throw new RuntimeException('Arquivo de conciliação inválido.');
        }

        $reader = XlsxReader::load($caminhoTemp, self::ABA);
        $rows = $reader->rows();

        $meta = $this->extrairMetadados($rows);
        $referenciaMes = $meta['referencia_mes'] ?? now()->startOfMonth();

        if (Conciliacao::query()->whereDate('referencia_mes', $referenciaMes)->exists()) {
            throw new RuntimeException(
                'Já existe conciliação para '.$referenciaMes->translatedFormat('F/Y').'. Remova antes de reimportar.'
            );
        }

        $estabelecimentos = Estabelecimento::withoutGlobalScopes()
            ->whereNotNull('token_pagseguro')
            ->where('token_pagseguro', '!=', '')
            ->pluck('id', 'token_pagseguro');

        $nomeArquivo = $arquivo instanceof UploadedFile
            ? $arquivo->getClientOriginalName()
            : basename($caminhoTemp);

        $pathSalvo = null;

        if ($arquivo instanceof UploadedFile) {
            $pasta = 'conciliacoes/'.$referenciaMes->format('Y-m');
            Storage::disk('local')->makeDirectory($pasta);
            $pathSalvo = $arquivo->storeAs($pasta, $nomeArquivo, 'local');
        }

        $conciliacao = DB::transaction(function () use ($rows, $meta, $referenciaMes, $estabelecimentos, $usuarioId, $nomeArquivo, $pathSalvo) {
            $conciliacao = Conciliacao::query()->create([
                'referencia_mes' => $referenciaMes,
                'data_referencia' => $meta['data_referencia'],
                'parceiro' => $meta['parceiro'],
                'arquivo_nome' => $nomeArquivo,
                'arquivo_path' => $pathSalvo,
                'importado_por_id' => $usuarioId,
                'status' => 'importado',
            ]);

            $headerIndex = $this->indiceCabecalho($rows);
            $linhas = [];
            $clientes = [];
            $totalTpv = 0;
            $totalComissao = 0;
            $totalChargeback = 0;
            $agora = now();

            foreach (array_slice($rows, $headerIndex + 1) as $row) {
                $dados = $this->mapearLinha($row);

                if ($dados === null) {
                    continue;
                }

                $idCliente = $dados['id_cliente'];
                $estabelecimentoId = $estabelecimentos[$idCliente] ?? null;
                $clientes[$idCliente] = true;

                $linhas[] = [
                    'conciliacao_id' => $conciliacao->id,
                    ...$dados,
                    'estabelecimento_id' => $estabelecimentoId,
                    'sem_estabelecimento' => $estabelecimentoId === null,
                    'status' => $estabelecimentoId === null ? 'sem_estabelecimento' : 'pendente',
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];

                $totalTpv += (float) $dados['tpv'];
                $totalComissao += (float) $dados['ms_comissao'];
                $totalChargeback += (float) $dados['ms_chargeback'];
            }

            foreach (array_chunk($linhas, 500) as $lote) {
                ConciliacaoLinha::query()->insert($lote);
            }

            $semEstabelecimento = collect($linhas)->where('sem_estabelecimento', true)->count();

            $conciliacao->update([
                'total_linhas' => count($linhas),
                'total_clientes' => count($clientes),
                'total_tpv' => round($totalTpv, 2),
                'total_comissao' => round($totalComissao, 4),
                'total_chargeback' => round($totalChargeback, 4),
                'linhas_sem_estabelecimento' => $semEstabelecimento,
            ]);

            return $conciliacao->fresh();
        });

        // Fora da transação: se o confronto falhar/estourar tempo, a importação já fica salva.
        if ($confrontar) {
            $this->confronto->confrontar($conciliacao);
        }

        return $conciliacao->fresh();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{parceiro: ?string, data_referencia: ?Carbon, referencia_mes: ?Carbon}
     */
    private function extrairMetadados(array $rows): array
    {
        $parceiro = null;
        $dataReferencia = null;

        foreach (array_slice($rows, 0, 12) as $row) {
            $label = strtolower(trim((string) ($row[1] ?? '')));

            if ($label === 'parceiro') {
                $parceiro = trim((string) ($row[2] ?? ''));
            }

            if ($label === 'data_referencia') {
                $dataReferencia = $this->parseData((string) ($row[2] ?? ''));
            }
        }

        $referenciaMes = $dataReferencia?->copy()->startOfMonth();

        return [
            'parceiro' => $parceiro,
            'data_referencia' => $dataReferencia,
            'referencia_mes' => $referenciaMes,
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapearLinha(array $row): ?array
    {
        $idCliente = trim((string) ($row[2] ?? ''));

        if ($idCliente === '' || ! ctype_digit($idCliente)) {
            return null;
        }

        $meio = strtolower(trim((string) ($row[3] ?? '')));
        $parcelamento = strtolower(trim((string) ($row[4] ?? '')));
        $bandeira = strtolower(trim((string) ($row[5] ?? '')));
        $escrow = trim((string) ($row[6] ?? ''));
        $mcc = trim((string) ($row[7] ?? ''));
        $solucao = strtolower(trim((string) ($row[8] ?? '')));

        return [
            'chave' => trim((string) ($row[0] ?? '')),
            'link' => trim((string) ($row[1] ?? '')),
            'id_cliente' => $idCliente,
            'meio_pagamento' => $meio,
            'parcelamento_agrupado' => $parcelamento,
            'bandeira' => $bandeira,
            'escrow' => $escrow,
            'mcc' => $mcc,
            'solucao' => $solucao,
            'tpv' => round((float) ($row[9] ?? 0), 2),
            'ms_comissao' => round((float) ($row[10] ?? 0), 4),
            'ms_chargeback' => round((float) ($row[11] ?? 0), 4),
            'rebate_real' => $this->decimalOpcional($row[13] ?? null),
            'rebate_contrato' => $this->decimalOpcional($row[14] ?? null),
            'check1_sem_antec' => $this->boolOpcional($row[15] ?? null),
            'chave_confronto' => ConciliacaoDimensao::chaveConfronto(
                $idCliente,
                $meio,
                $parcelamento,
                $bandeira,
                $escrow,
                $mcc,
                $solucao,
            ),
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function indiceCabecalho(array $rows): int
    {
        foreach ($rows as $index => $row) {
            if (strtolower(trim((string) ($row[0] ?? ''))) === 'chave') {
                return $index;
            }
        }

        throw new RuntimeException('Cabeçalho da aba Validação V2 não encontrado.');
    }

    private function parseData(string $valor): ?Carbon
    {
        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        try {
            if (str_contains($valor, '/')) {
                return Carbon::createFromFormat('d/m/Y', $valor)->startOfDay();
            }

            return Carbon::parse($valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimalOpcional(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return round((float) $valor, 6);
    }

    private function boolOpcional(mixed $valor): ?bool
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_bool($valor)) {
            return $valor;
        }

        $texto = strtolower(trim((string) $valor));

        return match ($texto) {
            '1', 'true', 'sim', 'ok' => true,
            '0', 'false', 'nao', 'não' => false,
            default => null,
        };
    }
}
