<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Support\ComissaoAdminSql;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Calcula os cards de resumo do dashboard (estabelecimentos, faturamento
 * e comissões do período selecionado). O cache é gerenciado por DashboardService.
 */
class DashboardResumoService
{
    public function __construct(
        private readonly ComissaoPagService $comissaoPag,
    ) {}

    /**
     * @return array{totalEstabelecimentos: int, faturamentoMes: float, royaltiesMes: float}
     */
    public function resumo(?Authenticatable $usuario, int $periodo = 0): array
    {
        return array_merge(
            $this->calcularRapido($usuario, $periodo),
            ['royaltiesMes' => $this->calcularComissaoMes($usuario, $periodo)],
        );
    }

    /**
     * @return array{totalEstabelecimentos: int, faturamentoMes: float}
     */
    public function calcularRapido(?Authenticatable $usuario, int $periodo = 0): array
    {
        return [
            'totalEstabelecimentos' => Estabelecimento::count(),
            'faturamentoMes' => $this->faturamentoPeriodo($periodo),
        ];
    }

    public function calcularComissaoMes(?Authenticatable $usuario, int $periodo = 0): float
    {
        $usuarioResolvido = $usuario;

        if ($usuarioResolvido instanceof SubUsuario) {
            $usuarioResolvido = $usuarioResolvido->dono;
        }

        return $this->comissaoPeriodo($usuarioResolvido, $periodo);
    }

    /**
     * Faturamento EDI na mesma janela da apuração (mês atual ou últimos N dias).
     */
    private function faturamentoPeriodo(int $periodo): float
    {
        [$inicio, $fim] = $this->intervalo($periodo);

        return (float) DB::table('edi_movimentos as em')
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
            ->sum('em.valor_total_transacao');
    }

    /**
     * Mesma fórmula do admin (ComissaoAdminSql), escopada à carteira.
     * Marketplace/revenda: desconta percentual_retencao_pai sobre essa base.
     */
    private function comissaoPeriodo(mixed $usuario, int $periodo): float
    {
        [$inicio, $fim] = $this->intervalo($periodo);

        $bruta = (float) ComissaoAdminSql::queryMovimentosComComissaoAdmin(function ($query) use ($inicio, $fim) {
            $query->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
                ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
                ->whereNotNull('e.plano_id');
        })->sum(DB::raw(ComissaoAdminSql::valor()));

        if ($usuario instanceof Usuario && $usuario->tipo !== 'admin') {
            return $this->comissaoPag->comissaoLiquidaMarketplace($bruta, $usuario)['liquida'];
        }

        return round($bruta, 2);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function intervalo(int $periodo): array
    {
        if ($periodo === 0) {
            return [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ];
        }

        return [
            now()->subDays($periodo)->toDateString(),
            now()->toDateString(),
        ];
    }
}
