<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EdiPipefySolicitacao;
use App\Services\AutomacaoPagBankService;
use App\Services\DirectAdminService;
use App\Services\EdiPipefySolicitacaoService;
use App\Support\PlatformSettings;
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

    public function show(EdiPipefySolicitacao $solicitacao, EdiPipefySolicitacaoService $service)
    {
        $solicitacao->load(['itens.estabelecimento', 'solicitadoPor']);

        $arquivos = [];
        if (filled($solicitacao->automacao_job_id)) {
            $arquivos = $service->listarArquivosScreenshots($solicitacao->automacao_job_id);
        }
        if ($arquivos === [] && is_array($solicitacao->screenshots)) {
            $arquivos = array_values(array_filter(array_map(
                fn ($s) => is_string($s) ? basename($s) : null,
                $solicitacao->screenshots
            )));
        }

        $screenshots = [];
        foreach ($arquivos as $arquivo) {
            $src = null;
            if (filled($solicitacao->automacao_job_id) && PlatformSettings::automacaoConfigurado()) {
                try {
                    $response = app(AutomacaoPagBankService::class)
                        ->baixarScreenshot($solicitacao->automacao_job_id, $arquivo);
                    if ($response->successful() && filled($response->body())) {
                        $src = 'data:image/png;base64,'.base64_encode($response->body());
                    }
                } catch (\Throwable) {
                    $src = null;
                }
            }

            $screenshots[] = [
                'arquivo' => $arquivo,
                'rotulo' => preg_replace('/_\d+$/', '', (string) preg_replace('/^pipefy_|\.png$/i', '', $arquivo)),
                'url' => route('admin.edi-pipefy.screenshot', [$solicitacao, $arquivo]),
                'src' => $src,
            ];
        }

        $etapaAtual = data_get($solicitacao->resultado, 'etapa_atual')
            ?: data_get($solicitacao->resultado, 'detalhe.etapa');

        return view('admin.edi-pipefy.show', [
            'solicitacao' => $solicitacao,
            'screenshots' => $screenshots,
            'etapaAtual' => $etapaAtual,
        ]);
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

    public function screenshots(EdiPipefySolicitacao $solicitacao, EdiPipefySolicitacaoService $service)
    {
        if (blank($solicitacao->automacao_job_id)) {
            $locais = is_array($solicitacao->screenshots) ? $solicitacao->screenshots : [];

            return response()->json([
                'job_id' => null,
                'screenshots' => array_map(
                    fn ($arquivo) => ['arquivo' => basename((string) $arquivo)],
                    $locais
                ),
            ]);
        }

        $arquivos = $service->listarArquivosScreenshots($solicitacao->automacao_job_id);

        return response()->json([
            'job_id' => $solicitacao->automacao_job_id,
            'screenshots' => array_map(fn ($arquivo) => ['arquivo' => $arquivo], $arquivos),
        ]);
    }

    public function screenshot(EdiPipefySolicitacao $solicitacao, string $filename)
    {
        $arquivo = basename($filename);
        if ($arquivo === '' || ! str_ends_with(strtolower($arquivo), '.png')) {
            abort(404, 'Screenshot não encontrado');
        }

        if (blank($solicitacao->automacao_job_id) || ! PlatformSettings::automacaoConfigurado()) {
            abort(404, 'Screenshot não disponível (job de automação ausente)');
        }

        try {
            $response = app(AutomacaoPagBankService::class)
                ->baixarScreenshot($solicitacao->automacao_job_id, $arquivo);

            if (! $response->successful()) {
                abort(404, 'Screenshot não encontrado');
            }

            return response($response->body(), 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'inline; filename="'.$arquivo.'"',
                'Cache-Control' => 'private, max-age=300',
            ]);
        } catch (\Throwable $e) {
            abort(502, $e->getMessage());
        }
    }
}
