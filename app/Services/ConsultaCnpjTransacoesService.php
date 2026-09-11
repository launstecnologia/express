<?php

namespace App\Services;

use App\Models\EdiMovimento;
use App\Models\Estabelecimento;
use App\Support\DocumentoBrasil;
use App\Support\EdiStatusPagamento;
use App\Support\InstituicaoFinanceira;
use App\Support\SimpleXlsxWriter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ConsultaCnpjTransacoesService
{
    public function buscarEstabelecimentos(string $documento): Collection
    {
        $digitos = DocumentoBrasil::apenasDigitos($documento);

        if (! in_array(strlen($digitos), [11, 14], true)) {
            return collect();
        }

        return Estabelecimento::withoutGlobalScopes()
            ->with(['marketplace', 'revenda'])
            ->when(strlen($digitos) === 14, function (Builder $query) use ($digitos) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '/', ''), '-', '') = ?",
                    [$digitos],
                );
            }, function (Builder $query) use ($digitos) {
                $query->whereRaw(
                    "REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', '') = ?",
                    [$digitos],
                );
            })
            ->orderByDesc('id')
            ->get();
    }

    public function movimentosQuery(Collection $estabelecimentos, string $inicio, string $fim): Builder
    {
        $ids = $estabelecimentos->pluck('id')->filter()->all();
        $tokens = $estabelecimentos
            ->pluck('token_pagseguro')
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = EdiMovimento::query()
            ->whereBetween('data_inicial_transacao', [$inicio, $fim]);

        if ($ids === [] && $tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($ids, $tokens) {
            if ($ids !== []) {
                $query->whereIn('estabelecimento_id', $ids);
            }

            if ($tokens !== []) {
                $query->orWhereIn('estabelecimento', $tokens);
            }
        });
    }

    public function totais(Builder $query): object
    {
        return (clone $query)
            ->selectRaw('
                COUNT(*) as total_transacoes,
                COALESCE(SUM(valor_total_transacao), 0) as valor_total,
                COALESCE(SUM(valor_liquido_transacao), 0) as valor_liquido
            ')
            ->first();
    }

    public function paginar(Builder $query, int $porPagina = 100): LengthAwarePaginator
    {
        return (clone $query)
            ->orderByDesc('data_inicial_transacao')
            ->orderByDesc('hora_inicial_transacao')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function gerarExcel(Collection $estabelecimentos, string $inicio, string $fim): string
    {
        @set_time_limit(900);

        $linhas = $this->iterarLinhasExcel($estabelecimentos, $inicio, $fim);

        return SimpleXlsxWriter::file($this->cabecalhosExcel(), $linhas, 'Transacoes');
    }

    public function nomeArquivo(Collection $estabelecimentos, Carbon $mes): string
    {
        $primeiro = $estabelecimentos->first();
        $digitos = DocumentoBrasil::apenasDigitos((string) ($primeiro?->cnpj ?: $primeiro?->cpf ?: 'documento'));
        $competencia = $mes->format('Y-m');

        return "transacoes-{$digitos}-{$competencia}.xlsx";
    }

    public function statusLabel(?string $status): string
    {
        if (EdiStatusPagamento::cancelado($status)) {
            return 'Cancelado';
        }

        return match ((string) $status) {
            '03', '3' => 'Concluído',
            '01', '1' => 'Novo',
            '02', '2' => 'Agendado',
            '04', '4' => 'Cancelado',
            '', 'sem' => 'Sem status',
            default => 'Status '.$status,
        };
    }

    /**
     * @return list<string>
     */
    private function cabecalhosExcel(): array
    {
        return [
            'Data',
            'Hora',
            'Tipo',
            'Status',
            'Instituição',
            'Meio pagamento',
            'NSU',
            'TX ID',
            'Código autorização',
            'Código transação',
            'Código venda',
            'Parcela',
            'Quantidade parcelas',
            'Valor total',
            'Valor líquido',
            'Valor original',
            'Taxa intermediação',
            'Tarifa intermediação',
            'Token EDI',
            'Estabelecimento ID',
            'Estabelecimento',
            'Documento',
            'Marketplace',
            'Revenda',
        ];
    }

    /**
     * @return \Generator<int, list<string|int|float|null>>
     */
    private function iterarLinhasExcel(Collection $estabelecimentos, string $inicio, string $fim): \Generator
    {
        $porId = $estabelecimentos->keyBy('id');
        $porToken = $estabelecimentos
            ->filter(fn (Estabelecimento $ec) => filled($ec->token_pagseguro))
            ->keyBy(fn (Estabelecimento $ec) => (string) $ec->token_pagseguro);

        foreach ($this->movimentosQuery($estabelecimentos, $inicio, $fim)->orderBy('id')->cursor() as $tx) {
            $ec = $porId->get($tx->estabelecimento_id)
                ?? $porToken->get((string) $tx->estabelecimento);

            yield [
                $tx->data_inicial_transacao?->format('d/m/Y') ?: '',
                (string) ($tx->hora_inicial_transacao ?: ''),
                (string) ($tx->tipo_transacao ?: ''),
                $this->statusLabel($tx->status_pagamento),
                InstituicaoFinanceira::nome($tx->instituicao_financeira),
                (string) ($tx->meio_pagamento ?: ''),
                (string) ($tx->nsu ?: ''),
                (string) ($tx->tx_id ?: ''),
                (string) ($tx->codigo_autorizacao ?: ''),
                (string) ($tx->codigo_transacao ?: ''),
                (string) ($tx->codigo_venda ?: ''),
                $tx->parcela,
                $tx->quantidade_parcela,
                (float) $tx->valor_total_transacao,
                (float) $tx->valor_liquido_transacao,
                (float) $tx->valor_original_transacao,
                (float) $tx->taxa_intermediacao,
                (float) $tx->tarifa_intermediacao,
                (string) ($tx->estabelecimento ?: $ec?->token_pagseguro ?: ''),
                $ec?->id ?: $tx->estabelecimento_id,
                $this->nomeEstabelecimento($ec),
                $ec?->cnpj ?: $ec?->cpf ?: '',
                $ec?->marketplace?->nomeExibicao() ?: '',
                $ec?->revenda?->nomeExibicao() ?: '',
            ];
        }
    }

    public function nomeEstabelecimento(?Estabelecimento $estabelecimento): string
    {
        if (! $estabelecimento) {
            return '—';
        }

        return $estabelecimento->nome_fantasia
            ?: $estabelecimento->razao_social
            ?: $estabelecimento->nome_completo
            ?: 'Estabelecimento #'.$estabelecimento->id;
    }
}
