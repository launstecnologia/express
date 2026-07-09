<?php

namespace App\Support;

use App\Models\Estabelecimento;
use Illuminate\Database\Eloquent\Builder;

class EstabelecimentoEtapaListagem
{
    public const PENDENTE = 'pendente';

    public const APROVADO = 'aprovado';

    public const NEGADO = 'negado';

    public static function statusEstabelecimento(Estabelecimento $estabelecimento): string
    {
        return self::normalizarStatus($estabelecimento->status);
    }

    public static function normalizarStatus(?string $status): string
    {
        return match ($status) {
            self::APROVADO, 'habilitado' => self::APROVADO,
            self::NEGADO, 'desabilitado', 'inativo_sistema' => self::NEGADO,
            default => self::PENDENTE,
        };
    }

    public static function statusPagBank(Estabelecimento $estabelecimento): string
    {
        if (
            EstabelecimentoSchema::temPagbankStatusManual()
            && filled($estabelecimento->pagbank_status_manual)
        ) {
            return self::normalizarStatus($estabelecimento->pagbank_status_manual);
        }

        return self::statusPagBankAutomatico($estabelecimento);
    }

    public static function statusPagBankAutomatico(Estabelecimento $estabelecimento): string
    {
        if (in_array($estabelecimento->fv_status, ['erro', 'timeout'], true)) {
            return self::NEGADO;
        }

        if ($estabelecimento->fv_status === 'concluido' || filled($estabelecimento->pagbank_account_id)) {
            return self::APROVADO;
        }

        if ($estabelecimento->fv_status === 'erro_email' && filled($estabelecimento->pagbank_account_id)) {
            return self::APROVADO;
        }

        return self::PENDENTE;
    }

    public static function rotulo(string $etapa): string
    {
        return match (self::normalizarStatus($etapa)) {
            self::APROVADO => 'APROVADO',
            self::NEGADO => 'NEGADO',
            default => 'PENDENTE',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function badge(string $etapa): array
    {
        return match (self::normalizarStatus($etapa)) {
            self::APROVADO => ['bg-emerald-100 text-emerald-800', self::rotulo($etapa)],
            self::NEGADO => ['bg-red-100 text-red-800', self::rotulo($etapa)],
            default => ['bg-amber-100 text-amber-800', self::rotulo($etapa)],
        };
    }

    public static function aplicarFiltroStatus(Builder $query, string $etapa): void
    {
        $etapa = self::normalizarStatus($etapa);

        if ($etapa === self::PENDENTE) {
            $excluir = array_merge(
                self::valoresStatusBanco(self::APROVADO),
                self::valoresStatusBanco(self::NEGADO),
            );

            $query->where(function (Builder $q) use ($excluir) {
                $q->whereNotIn('status', $excluir)->orWhereNull('status');
            });

            return;
        }

        $query->whereIn('status', self::valoresStatusBanco($etapa));
    }

    /**
     * Valores possíveis da coluna `status` que correspondem à etapa normalizada.
     *
     * @return array<int, string>
     */
    public static function valoresStatusBanco(string $etapa): array
    {
        $etapa = self::normalizarStatus($etapa);

        return match ($etapa) {
            self::APROVADO => ['aprovado', 'habilitado'],
            self::NEGADO => ['negado', 'desabilitado', 'inativo_sistema'],
            default => ['pendente', 'em_analise', 'qualidade', 'em_cadastro'],
        };
    }

    public static function aplicarFiltroPagBank(Builder $query, string $etapa): void
    {
        $etapa = self::normalizarStatus($etapa);

        if (! EstabelecimentoSchema::temPagbankStatusManual()) {
            self::aplicarFiltroPagBankAutomatico($query, $etapa);

            return;
        }

        $query->where(function (Builder $q) use ($etapa) {
            $q->where('pagbank_status_manual', $etapa)
                ->orWhere(function (Builder $auto) use ($etapa) {
                    $auto->whereNull('pagbank_status_manual');
                    self::aplicarFiltroPagBankAutomatico($auto, $etapa);
                });
        });
    }

    private static function aplicarFiltroPagBankAutomatico(Builder $query, string $etapa): void
    {
        match ($etapa) {
            self::APROVADO => $query->where(function (Builder $q) {
                $q->where('fv_status', 'concluido')
                    ->orWhereNotNull('pagbank_account_id')
                    ->orWhere(function (Builder $email) {
                        $email->where('fv_status', 'erro_email')
                            ->whereNotNull('pagbank_account_id');
                    });
            }),
            self::NEGADO => $query->whereIn('fv_status', ['erro', 'timeout']),
            default => $query->where(function (Builder $q) {
                $q->where(function (Builder $pendente) {
                    $pendente->whereNull('fv_status')
                        ->orWhereIn('fv_status', ['pendente', 'em_andamento']);
                })->whereNull('pagbank_account_id');
            }),
        };
    }
}
