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
    public function resumo(?Authenticatable $usuario, int $dias = 30): array
    {
        return array_merge(
            $this->calcularRapido($usuario, $dias),
            ['royaltiesMes' => $this->calcularComissaoMes($usuario, $dias)],
        );
    }

    /**
     * @return array{totalEstabelecimentos: int, faturamentoMes: float}
     */
    public function calcularRapido(?Authenticatable $usuario, int $dias = 30): array
    {
        return [
            'totalEstabelecimentos' => Estabelecimento::count(),
            'faturamentoMes' => $this->faturamentoPeriodo($dias),
        ];
    }

    public function calcularComissaoMes(?Authenticatable $usuario, int $dias = 30): float
    {
        $usuarioResolvido = $usuario;

        if ($usuarioResolvido instanceof SubUsuario) {
            $usuarioResolvido = $usuarioResolvido->dono;
        }

        return $this->comissaoPeriodo($usuarioResolvido, $dias);
    }

    /**
     * Faturamento do período rolling, somado direto do EDI (mesma janela da apuração).
     */
    private function faturamentoPeriodo(int $dias): float
    {
        $desde = now()->subDays($dias)->toDateString();

        return (float) DB::table('edi_movimentos as em')
            ->where('em.data_inicial_transacao', '>=', $desde)
            ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
            ->sum('em.valor_total_transacao');
    }

    private function comissaoPeriodo(mixed $usuario, int $dias): float
    {
        $desde = now()->subDays($dias)->toDateString();

        if ($usuario instanceof Usuario && $usuario->tipo !== 'admin') {
            $bruta = (float) DB::table('transacao_royalties')
                ->join('edi_movimentos', 'edi_movimentos.id', '=', 'transacao_royalties.edi_movimento_id')
                ->where('edi_movimentos.data_inicial_transacao', '>=', $desde)
                ->where('transacao_royalties.usuario_id', $usuario->id)
                ->whereIn('edi_movimentos.estabelecimento_id', Estabelecimento::query()->select('id'))
                ->sum('transacao_royalties.valor_royalty');

            return $this->comissaoPag->comissaoLiquidaMarketplace($bruta, $usuario)['liquida'];
        }

        return (float) ComissaoAdminSql::queryMovimentosComComissaoAdmin(function ($query) use ($desde) {
            $query->where('em.data_inicial_transacao', '>=', $desde)
                ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
                ->whereNotNull('e.plano_id');
        })->sum(DB::raw(ComissaoAdminSql::valor()));
    }
}
