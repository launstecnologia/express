<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboardService)
    {
        $periodo = $this->resolverPeriodo($request);
        $usuario = $request->user();

        $resumo = $dashboardService->resumoRapido($usuario, $periodo);
        $comissao = $dashboardService->comissaoMes($usuario, $periodo);
        $apuracao = $dashboardService->apuracao($periodo, $usuario);

        return view('admin.dashboard', [
            'periodo' => $periodo,
            'totalEstabelecimentos' => $resumo['totalEstabelecimentos'],
            'faturamentoMes' => $resumo['faturamentoMes'],
            'royaltiesMes' => $comissao['royaltiesMes'],
            'planosResumo' => $apuracao['planosResumo'],
            'resumoPlanos' => $apuracao['resumoPlanos'],
            'transacoesStatus' => $apuracao['transacoesStatus'],
            'faturamentoBandeiras' => $apuracao['faturamentoBandeiras'],
        ]);
    }

    public function comissao(Request $request, DashboardService $dashboardService)
    {
        $periodo = $this->resolverPeriodo($request);
        $dados = $dashboardService->comissaoMes($request->user(), $periodo);

        return response()->json([
            'royaltiesMes' => $dados['royaltiesMes'],
            'formatado' => 'R$ '.number_format($dados['royaltiesMes'], 2, ',', '.'),
        ]);
    }

    public function apuracao(Request $request, DashboardService $dashboardService)
    {
        $periodo = $this->resolverPeriodo($request);
        $dados = $dashboardService->apuracao($periodo, $request->user());

        return view('admin.dashboard-apuracao', $dados);
    }

    private function resolverPeriodo(Request $request): int
    {
        $periodo = (int) $request->integer('periodo', 0);

        return in_array($periodo, [0, 7, 30, 90], true) ? $periodo : 0;
    }
}
