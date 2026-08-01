<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboardService)
    {
        $periodo = (int) $request->integer('periodo', 30);
        $resumo = $dashboardService->resumoRapido($request->user());

        return view('admin.dashboard', [
            'periodo' => in_array($periodo, [7, 30, 90], true) ? $periodo : 30,
            'totalEstabelecimentos' => $resumo['totalEstabelecimentos'],
            'faturamentoMes' => $resumo['faturamentoMes'],
        ]);
    }

    public function comissao(Request $request, DashboardService $dashboardService)
    {
        $dados = $dashboardService->comissaoMes($request->user());

        return response()->json([
            'royaltiesMes' => $dados['royaltiesMes'],
            'formatado' => 'R$ '.number_format($dados['royaltiesMes'], 2, ',', '.'),
        ]);
    }

    public function apuracao(Request $request, DashboardService $dashboardService)
    {
        $periodo = (int) $request->integer('periodo', 30);
        $dados = $dashboardService->apuracao($periodo, $request->user());

        return view('admin.dashboard-apuracao', $dados);
    }
}
