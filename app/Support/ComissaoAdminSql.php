<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class ComissaoAdminSql
{
    public static function percentual(): string
    {
        return 'COALESCE(pt.comissao_percentual, ptr_admin.percentual)';
    }

    public static function valor(): string
    {
        return "em.valor_total_transacao * (".self::percentual().') / 100';
    }

    public static function joinPlanoTaxa(JoinClause $join): void
    {
        $join->on('pt.plano_id', '=', 'e.plano_id')
            ->on('pt.arranjo_ur', '=', 'em.arranjo_ur')
            ->on('pt.parcelas', '=', DB::raw('COALESCE(NULLIF(em.quantidade_parcela, 0), 1)'))
            ->where('pt.ativo', true);
    }

    public static function joinRoyaltyAdmin(Builder $query): Builder
    {
        return $query->leftJoin('plano_taxa_royalties as ptr_admin', function (JoinClause $join) {
            $join->on('ptr_admin.plano_taxa_id', '=', 'pt.id')
                ->where('ptr_admin.nivel', '=', 'admin');
        });
    }

    public static function whereTemComissao(Builder $query): Builder
    {
        return $query->whereNotNull(DB::raw(self::percentual()));
    }

    /**
     * @param  callable(JoinClause): void|null  $antes
     */
    public static function queryMovimentosComComissaoAdmin(?callable $antes = null): Builder
    {
        $query = DB::table('edi_movimentos as em')
            ->join('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->join('plano_taxas as pt', function (JoinClause $join) {
                self::joinPlanoTaxa($join);
            });

        if ($antes) {
            $antes($query);
        }

        return self::whereTemComissao(self::joinRoyaltyAdmin($query));
    }
}
