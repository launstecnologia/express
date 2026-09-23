<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstabelecimentoPendenciasRelatorioService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstabelecimentoPendenciasController extends Controller
{
    public function index(Request $request, EstabelecimentoPendenciasRelatorioService $relatorio)
    {
        $filtros = $this->filtros($request);
        $linhas = $relatorio->paginar($filtros);
        $contagens = $relatorio->contagens($filtros);

        return view('admin.relatorios.estabelecimento-pendencias', [
            'filtros' => $filtros,
            'linhas' => $linhas,
            'contagens' => $contagens,
            'marketplaces' => $relatorio->marketplaces(),
            'relatorio' => $relatorio,
        ]);
    }

    public function excel(Request $request, EstabelecimentoPendenciasRelatorioService $relatorio): Response
    {
        $filtros = $this->filtros($request);
        $caminho = $relatorio->gerarExcel($filtros);

        return response()->download($caminho, $relatorio->nomeArquivo(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{pendencia: ?string, busca: string, marketplace_id: ?int, status: ?string}
     */
    private function filtros(Request $request): array
    {
        $pendencia = $request->string('pendencia')->toString();
        if (! in_array($pendencia, EstabelecimentoPendenciasRelatorioService::PENDENCIAS, true)) {
            $pendencia = null;
        }

        $status = $request->string('status')->toString();
        if (! in_array($status, ['pendente', 'aprovado', 'negado'], true)) {
            $status = null;
        }

        $marketplaceId = $request->integer('marketplace_id');

        return [
            'pendencia' => $pendencia,
            'busca' => trim((string) $request->input('busca')),
            'marketplace_id' => $marketplaceId > 0 ? $marketplaceId : null,
            'status' => $status,
        ];
    }
}
