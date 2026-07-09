<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AggregatedRevenue;
use App\Models\Estabelecimento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Services\DashboardApuracaoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 300;

    public function __invoke(Request $request, DashboardApuracaoService $apuracaoService)
    {
        $periodo = $apuracaoService->periodoValido($request->integer('periodo', 30));
        $apuracao = $apuracaoService->apurar($periodo, $request->user());

        $usuario = $request->user();
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $cacheKey = $this->cacheKeyResumo($usuario);

        $resumoCards = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($usuario) {
            return [
                'totalEstabelecimentos' => Estabelecimento::count(),
                'faturamentoMes' => (float) AggregatedRevenue::query()
                    ->where('ano', now()->year)
                    ->where('mes', now()->month)
                    ->sum('total_valor'),
                'royaltiesMes' => $this->royaltiesMes($usuario),
            ];
        });

        return view('admin.dashboard', [
            'periodo' => $periodo,
            'totalEstabelecimentos' => $resumoCards['totalEstabelecimentos'],
            'faturamentoMes' => $resumoCards['faturamentoMes'],
            'royaltiesMes' => $resumoCards['royaltiesMes'],
            'planosResumo' => $apuracao['planos'],
            'resumoPlanos' => $apuracao['resumo'],
            'transacoesStatus' => $apuracao['transacoes_status'],
            'faturamentoBandeiras' => $apuracao['faturamento_bandeiras'],
        ]);
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

        return (float) DB::table('edi_movimentos as em')
            ->join('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->join('plano_taxas as pt', function ($join) {
                $join->on('pt.plano_id', '=', 'e.plano_id')
                    ->on('pt.arranjo_ur', '=', 'em.arranjo_ur')
                    ->on('pt.parcelas', '=', DB::raw('COALESCE(NULLIF(em.quantidade_parcela, 0), 1)'))
                    ->where('pt.ativo', true);
            })
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->whereIn('em.estabelecimento_id', Estabelecimento::query()->select('id'))
            ->whereNotNull('e.plano_id')
            ->whereNotNull('pt.comissao_percentual')
            ->sum(DB::raw('em.valor_total_transacao * pt.comissao_percentual / 100'));
    }

    private function cacheKeyResumo(mixed $usuario): string
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
