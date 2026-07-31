<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardApuracaoService;
use App\Services\DashboardResumoService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardApuracaoService $apuracaoService,
        DashboardResumoService $resumoService,
    ) {
        $periodo = $apuracaoService->periodoValido($request->integer('periodo', 30));
        $apuracao = $apuracaoService->apurar($periodo, $request->user());

        $resumoCards = $resumoService->resumo($request->user());

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
}
