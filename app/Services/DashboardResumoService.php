<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Support\ComissaoAdminSql;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Calcula os cards de resumo do dashboard (estabelecimentos, faturamento do mês
 * e comissões do mês). O cache é gerenciado por DashboardService.
 */
class DashboardResumoService
{
    /**
     * @return array{totalEstabelecimentos: int, faturamentoMes: float, royaltiesMes: float}
     */
    public function resumo(?Authenticatable $usuario): array
    {
        return array_merge(
            $this->calcularRapido($usuario),
            ['royaltiesMes' => $this->calcularComissaoMes($usuario)],
        );
    }

    /**
     * @return array{totalEstabelecimentos: int, faturamentoMes: float}
     */
    public function calcularRapido(?Authenticatable $usuario): array
    {
        return [
            'totalEstabelecimentos' => Estabelecimento::count(),
            'faturamentoMes' => $this->faturamentoMes(),
        ];
    }

    public function calcularComissaoMes(?Authenticatable $usuario): float
    {
        $usuarioResolvido = $usuario;

        if ($usuarioResolvido instanceof SubUsuario) {
            $usuarioResolvido = $usuarioResolvido->dono;
        }

        return $this->royaltiesMes($usuarioResolvido);
    }

    /**
     * Faturamento do mês calendário, somado direto do EDI (mesma fonte da apuração).
     * aggregated_revenue pode ficar defasada até o job diário rodar.
     */
    private function faturamentoMes(): float
    {
        $inicio = now()->startOfMonth()->toDateString();
        $fim = now()->endOfMonth()->toDateString();

        return (float) DB::table('edi_movimentos as em')
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
            ->sum('em.valor_total_transacao');
    }

    private function royaltiesMes(mixed $usuario): float
    {
        $inicio = now()->startOfMonth()->toDateString();
        $fim = now()->endOfMonth()->toDateString();

        if ($usuario instanceof Usuario && $usuario->tipo !== 'admin') {
            return (float) DB::table('transacao_royalties')
                ->join('edi_movimentos', 'edi_movimentos.id', '=', 'transacao_royalties.edi_movimento_id')
                ->whereBetween('edi_movimentos.data_inicial_transacao', [$inicio, $fim])
                ->where('transacao_royalties.usuario_id', $usuario->id)
                ->sum('transacao_royalties.valor_royalty');
        }

        return (float) ComissaoAdminSql::queryMovimentosComComissaoAdmin(function ($query) use ($inicio, $fim) {
            $query->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
                ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
                ->whereNotNull('e.plano_id');
        })->sum(DB::raw(ComissaoAdminSql::valor()));
    }
}
