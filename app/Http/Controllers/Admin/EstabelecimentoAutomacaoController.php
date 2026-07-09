<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AutomacaoAceitarPropostaJob;
use App\Jobs\AutomacaoBuscarSafepayJob;
use App\Jobs\AutomacaoPagBankJob;
use App\Jobs\AutomacaoRetentarEmailJob;
use App\Models\Estabelecimento;
use App\Services\AutomacaoLogService;
use App\Services\AutomacaoPagBankService;
use App\Support\AutomacaoSchema;
use App\Support\PlatformSettings;
use App\Support\UsuarioComercial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EstabelecimentoAutomacaoController extends Controller
{
    public function iniciar(Request $request, Estabelecimento $estabelecimento)
    {
        $this->autorizarAutomacao($estabelecimento);

        $statusBloqueante = ['em_andamento'];
        if (in_array($estabelecimento->fv_status, $statusBloqueante, true)) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->with('aviso', 'A automação já está em andamento. Aguarde a conclusão antes de reexecutar.');
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['automacao' => 'A automação não está configurada. Verifique AUTOMACAO_API_URL e AUTOMACAO_API_KEY.']);
        }

        $senha6 = $this->gerarSenha6();

        $estabelecimento->update(['fv_status' => 'pendente', 'fv_erro' => null]);

        AutomacaoPagBankJob::dispatch($estabelecimento->id, $senha6)
            ->onQueue('automacao');

        return redirect()
            ->route('estabelecimentos.show', $estabelecimento)
            ->with('status', 'Automação enfileirada. Acompanhe o status nesta página.');
    }

    public function retentarEmail(Request $request, Estabelecimento $estabelecimento)
    {
        $this->autorizarAutomacao($estabelecimento);

        $logs = app(AutomacaoLogService::class)->listarParaEstabelecimento($estabelecimento->id);

        if (! AutomacaoSchema::podeRetentarApenasEmail($estabelecimento, $logs)) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->with('aviso', 'Esta ação só está disponível quando o cadastro no portal FV já foi concluído e a falha é na etapa de e-mail/senha.');
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['automacao' => 'A automação não está configurada.']);
        }

        try {
            $senha6 = $estabelecimento->fv_senha_6 ?: $this->gerarSenha6();

            $estabelecimento->update([
                'fv_status'  => 'em_andamento',
                'fv_erro'    => null,
                'fv_senha_6' => $senha6,
            ]);

            AutomacaoRetentarEmailJob::dispatch($estabelecimento->id, $senha6)
                ->onQueue('automacao');

            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->with('status', 'Retentando etapa de e-mail e senha. O cadastro no portal FV não será refeito.');

        } catch (\Throwable $e) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['automacao' => 'Erro ao retentar e-mail: '.$e->getMessage()]);
        }
    }

    public function status(Estabelecimento $estabelecimento)
    {
        $this->autorizarAutomacao($estabelecimento);

        $estabelecimento->refresh();

        if (! PlatformSettings::automacaoConfigurado()) {
            return response()->json([
                'fv_status'   => $estabelecimento->fv_status,
                'etapa_atual' => null,
                'erro'        => null,
            ]);
        }

        if (blank($estabelecimento->fv_job_id)) {
            return response()->json([
                'fv_status'   => $estabelecimento->fv_status,
                'etapa_atual' => $estabelecimento->fv_status === 'pendente'
                    ? 'Aguardando fila...'
                    : null,
                'erro'        => $estabelecimento->fv_erro,
            ]);
        }

        try {
            $status = app(AutomacaoPagBankService::class)
                ->consultarStatusESincronizarLogs($estabelecimento, $estabelecimento->fv_job_id);

            return response()->json([
                'fv_status'   => $estabelecimento->fv_status,
                'status'      => $status['status'] ?? null,
                'etapa_atual' => $status['etapa_atual'] ?? null,
                'erro'        => $status['erro'] ?? $estabelecimento->fv_erro,
                'atualizado_em' => $status['atualizado_em'] ?? null,
                'automacao_logs' => $this->logsAutomacaoJson($estabelecimento),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Automacao status: falha ao consultar API Python', [
                'estabelecimento_id' => $estabelecimento->id,
                'job_id'             => $estabelecimento->fv_job_id,
                'erro'               => $e->getMessage(),
            ]);

            return response()->json([
                'fv_status'   => $estabelecimento->fv_status,
                'etapa_atual' => 'Consultando status...',
                'erro'        => null,
            ]);
        }
    }

    public function screenshots(Estabelecimento $estabelecimento)
    {
        $this->autorizarAutomacao($estabelecimento, apenasAdmin: true);

        if (blank($estabelecimento->fv_job_id)) {
            return response()->json(['job_id' => null, 'screenshots' => []]);
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            return response()->json(['erro' => 'Automação não configurada'], 503);
        }

        try {
            $lista = app(AutomacaoPagBankService::class)
                ->listarScreenshots($estabelecimento->fv_job_id);

            return response()->json($lista);
        } catch (\Throwable $e) {
            return response()->json(['erro' => $e->getMessage()], 502);
        }
    }

    public function screenshot(Estabelecimento $estabelecimento, string $filename)
    {
        $this->autorizarAutomacao($estabelecimento, apenasAdmin: true);

        if (blank($estabelecimento->fv_job_id)) {
            abort(404, 'Screenshot não encontrado');
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            abort(503, 'Automação não configurada');
        }

        try {
            $response = app(AutomacaoPagBankService::class)
                ->baixarScreenshot($estabelecimento->fv_job_id, $filename);

            if (! $response->successful()) {
                abort(404, 'Screenshot não encontrado');
            }

            return response($response->body(), 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]);
        } catch (\Throwable $e) {
            abort(502, $e->getMessage());
        }
    }

    public function buscarSafepayId(Request $request, Estabelecimento $estabelecimento)
    {
        $this->autorizarAutomacao($estabelecimento, apenasAdmin: true);

        if (! in_array($estabelecimento->fv_status, ['concluido', 'erro_email'], true)) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->with('aviso', 'A busca do Safepay ID só está disponível após o cadastro na Força de Vendas.');
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['automacao' => 'A automação não está configurada.']);
        }

        try {
            app(AutomacaoPagBankService::class)->documentoEstabelecimento($estabelecimento);
        } catch (\Throwable $e) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['automacao' => $e->getMessage()]);
        }

        AutomacaoBuscarSafepayJob::dispatch($estabelecimento->id)
            ->onQueue('automacao');

        return redirect()
            ->route('estabelecimentos.show', $estabelecimento)
            ->withFragment('automacao')
            ->with('safepay_buscando', true)
            ->with('status', 'Pesquisando Safepay ID no portal PagBank...');
    }

    public function aceitarProposta(Request $request, Estabelecimento $estabelecimento)
    {
        $this->autorizarAutomacao($estabelecimento, apenasAdmin: true);

        if (! AutomacaoSchema::temColunasProposta()) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withFragment('automacao')
                ->withErrors(['automacao' => 'Execute docker compose exec app php artisan migrate --force no servidor antes de aceitar a proposta.']);
        }

        if (blank($estabelecimento->fv_senha_6)) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withFragment('automacao')
                ->withErrors(['automacao' => 'Senha PagBank (6 dígitos) não disponível. Conclua a etapa de e-mail/senha primeiro.']);
        }

        if ($estabelecimento->fv_proposta_status === 'em_andamento') {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withFragment('automacao')
                ->with('aviso', 'O aceite de proposta já está em andamento.');
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withFragment('automacao')
                ->withErrors(['automacao' => 'A automação não está configurada.']);
        }

        try {
            app(AutomacaoPagBankService::class)->documentoEstabelecimento($estabelecimento);
        } catch (\Throwable $e) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withFragment('automacao')
                ->withErrors(['automacao' => $e->getMessage()]);
        }

        $estabelecimento->update(array_merge(
            AutomacaoSchema::atualizacaoProposta('em_andamento'),
            ['fv_proposta_erro' => null],
        ));

        app(AutomacaoLogService::class)->registrarInicio(
            $estabelecimento->id,
            'Aceitar proposta comercial PagBank (manual)',
        );

        AutomacaoAceitarPropostaJob::dispatch($estabelecimento->id)
            ->onQueue('automacao');

        return redirect()
            ->route('estabelecimentos.show', $estabelecimento)
            ->withFragment('automacao')
            ->with('proposta_aceitando', true)
            ->with('status', 'Aceitando proposta comercial no PagBank... Aguarde, a página atualiza automaticamente.');
    }

    private function autorizarAutomacao(Estabelecimento $estabelecimento, bool $apenasAdmin = false): void
    {
        if ($apenasAdmin) {
            abort_unless(UsuarioComercial::ehAdmin(), 403);

            return;
        }

        abort_unless(
            UsuarioComercial::podeGerenciarAutomacaoEstabelecimento($estabelecimento),
            403,
            'Sem permissão para gerenciar a automação deste estabelecimento.',
        );
    }

    private function gerarSenha6(): string
    {
        do {
            $senha = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $ehSequencia = preg_match('/^(\d)\1{5}$/', $senha)
                || in_array($senha, ['123456', '654321', '012345', '567890'], true);
        } while ($ehSequencia);

        return $senha;
    }

    private function logsAutomacaoJson(Estabelecimento $estabelecimento): array
    {
        return app(AutomacaoLogService::class)->listarJson($estabelecimento->id);
    }
}
