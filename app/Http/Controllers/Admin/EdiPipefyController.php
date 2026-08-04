<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EdiPipefySolicitacao;
use App\Services\DirectAdminService;
use App\Services\EdiPipefySolicitacaoService;
use Illuminate\Http\Request;

class EdiPipefyController extends Controller
{
    public function index(EdiPipefySolicitacaoService $service)
    {
        $solicitacoes = EdiPipefySolicitacao::query()
            ->with('solicitadoPor')
            ->withCount('itens')
            ->orderByDesc('id')
            ->paginate(20);

        $preview = $service->previewPendentes();

        return view('admin.edi-pipefy.index', [
            'solicitacoes' => $solicitacoes,
            'preview' => $preview,
            'emailDevolutiva' => config('pagbank.pipefy_edi.email'),
            'desde' => config('pagbank.pipefy_edi.desde'),
            'pipefyUrl' => config('pagbank.pipefy_edi.page_url'),
        ]);
    }

    public function show(EdiPipefySolicitacao $solicitacao)
    {
        $solicitacao->load(['itens.estabelecimento', 'solicitadoPor']);

        return view('admin.edi-pipefy.show', compact('solicitacao'));
    }

    public function solicitar(Request $request, EdiPipefySolicitacaoService $service)
    {
        $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $solicitacao = $service->iniciar(
                $request->user()?->id,
                $request->boolean('force'),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['pipefy' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.edi-pipefy.show', $solicitacao)
            ->with('status', "Chamado enfileirado com {$solicitacao->total_ids} ID(s). Acompanhe o status abaixo.");
    }

    public function criarEmail(EdiPipefySolicitacaoService $service, DirectAdminService $da)
    {
        try {
            $resultado = $service->garantirEmailDevolutiva($da);
        } catch (\Throwable $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        $msg = $resultado['mensagem'].' E-mail: '.$resultado['email'];
        if (filled($resultado['senha'] ?? null)) {
            $msg .= ' | Senha gerada: '.$resultado['senha'].' (copie agora)';
        }

        return back()->with('status', $msg);
    }
}
