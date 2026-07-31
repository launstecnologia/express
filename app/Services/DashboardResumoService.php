<?php

namespace App\Services;

use App\Models\AggregatedRevenue;
use App\Models\Estabelecimento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Support\ComissaoAdminSql;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calcula e cacheia os cards de resumo do dashboard (estabelecimentos, faturamento
 * do mês e comissões do mês).
 *
 * Extraído do DashboardController para que o cache possa ser aquecido em background
 * pelo comando `dashboard:warm-cache`, evitando que o usuário pague o custo da
 * consulta na hora em que abre o dashboard (cache miss = 30s+ de espera).
 */
class DashboardResumoService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array{totalEstabelecimentos: int, faturamentoMes: float, royaltiesMes: float}
     */
    public function resumo(?Authenticatable $usuario): array
    {
        $usuarioResolvido = $usuario;

        if ($usuarioResolvido instanceof SubUsuario) {
            $usuarioResolvido = $usuarioResolvido->dono;
        }

        return Cache::remember(
            $this->cacheKey($usuarioResolvido),
            self::CACHE_TTL_SECONDS,
            fn () => [
                'totalEstabelecimentos' => Estabelecimento::count(),
                'faturamentoMes' => (float) AggregatedRevenue::query()
                    ->where('ano', now()->year)
                    ->where('mes', now()->month)
                    ->sum('total_valor'),
                'royaltiesMes' => $this->royaltiesMes($usuarioResolvido),
            ],
        );
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

    public function cacheKey(mixed $usuario): string
    {
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $tipo = $usuario instanceof Usuario ? $usuario->tipo : 'guest';
        $id = $usuario?->id ?? 0;
        $mes = now()->format('Y-m');

        return "dashboard.resumo.v2.{$tipo}.{$id}.{$mes}";
    }
}
