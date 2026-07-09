<?php

namespace App\Jobs;

use App\Models\Estabelecimento;
use App\Services\AutomacaoLogService;
use App\Services\AutomacaoPagBankService;
use App\Services\EmailPlataformaService;
use App\Services\NotificacaoEmailService;
use App\Support\AutomacaoSchema;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\EstabelecimentoSchema;
use App\Support\NotificacaoVars;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AutomacaoRetentarEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** 10 minutos: aguardar e-mail + criar senha */
    public int $timeout = 600;

    public function __construct(
        public readonly int $estabelecimentoId,
        public readonly string $senha6,
    ) {}

    public function handle(
        AutomacaoPagBankService $service,
        AutomacaoLogService $automacaoLog,
        EmailPlataformaService $emailService,
        NotificacaoEmailService $notificacao,
    ): void {
        $estab = Estabelecimento::withoutGlobalScopes()->find($this->estabelecimentoId);

        if (! $estab) {
            Log::warning('AutomacaoRetentarEmailJob: estabelecimento não encontrado', [
                'id' => $this->estabelecimentoId,
            ]);
            return;
        }

        try {
            $automacaoLog->registrarInicio($estab->id, 'Retentar e-mail e senha PagBank');

            $jobId = $service->retentarEmail($estab, $this->senha6);

            $estab->update(['fv_job_id' => $jobId]);

            Log::info('AutomacaoRetentarEmailJob: job Python iniciado', [
                'estabelecimento_id' => $estab->id,
                'job_id'             => $jobId,
            ]);

            // Polling
            $intervalo     = (int) config('automacao.polling_intervalo_seg', 20);
            $maxTentativas = (int) config('automacao.polling_max_tentativas', 25);
            $statusFinal   = null;
            $status        = [];

            for ($i = 0; $i < $maxTentativas; $i++) {
                sleep($intervalo);

                $status = $service->consultarStatusParaPolling($estab, $jobId);
                if ($status === null) {
                    continue;
                }

                $statusFinal = $status['status'] ?? 'desconhecido';

                Log::debug("AutomacaoRetentarEmailJob: poll {$i}/{$maxTentativas}", [
                    'job_id' => $jobId,
                    'status' => $statusFinal,
                ]);

                if (in_array($statusFinal, ['concluido', 'erro', 'erro_email', 'erro_proposta'], true)) {
                    break;
                }
            }

            if ($statusFinal === 'concluido') {
                $estab->update([
                    'fv_status'       => 'concluido',
                    'fv_senha_6'      => $this->senha6,
                    'fv_concluido_em' => now(),
                    'fv_erro'         => null,
                    'status'          => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::APROVADO),
                ]);

                $automacaoLog->registrarConclusao($estab->id, 'Retentativa de e-mail concluída com sucesso', $jobId);

                // Reativa o forwarder
                try {
                    $emailService->ativarForwarder($estab->fresh());
                } catch (\Throwable $e) {
                    Log::warning('AutomacaoRetentarEmailJob: falha ao reativar forwarder', [
                        'estabelecimento_id' => $estab->id,
                        'erro' => $e->getMessage(),
                    ]);
                }

                Log::info('AutomacaoRetentarEmailJob: e-mail concluído com sucesso', [
                    'estabelecimento_id' => $estab->id,
                ]);

                if (filled($estab->email)) {
                    $notificacao->enfileirar(
                        'pagbank.fv_concluido',
                        $estab->email,
                        NotificacaoVars::estabelecimento($estab),
                        route('estabelecimentos.show', $estab),
                    );
                }

            } elseif (in_array($statusFinal, ['erro', 'erro_email', 'erro_proposta'], true)) {
                $erro = $status['erro'] ?? 'Erro na retentativa de e-mail';

                if ($statusFinal === 'erro_proposta') {
                    $update = AutomacaoSchema::atualizacaoErroProposta($estab, $erro);
                } else {
                    $update = [
                        'fv_status' => 'erro_email',
                        'fv_erro'   => $erro,
                    ];
                }

                if ($update !== []) {
                    $estab->update($update);
                }

                $automacaoLog->registrarErro($estab->id, $erro, $jobId, $statusFinal);

                Log::error('AutomacaoRetentarEmailJob: retentativa falhou', [
                    'estabelecimento_id' => $estab->id,
                    'erro'               => $erro,
                ]);

            } else {
                $estab->update([
                    'fv_status' => 'timeout',
                    'fv_erro'   => 'Timeout: retentativa de e-mail não concluiu dentro do prazo.',
                ]);
            }

        } catch (\Throwable $e) {
            $estab->update([
                'fv_status' => 'erro_email',
                'fv_erro'   => $e->getMessage(),
            ]);

            Log::error('AutomacaoRetentarEmailJob: exceção inesperada', [
                'estabelecimento_id' => $this->estabelecimentoId,
                'erro'               => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('AutomacaoRetentarEmailJob: job falhou definitivamente', [
            'estabelecimento_id' => $this->estabelecimentoId,
            'erro'               => $exception?->getMessage(),
        ]);

        Estabelecimento::withoutGlobalScopes()
            ->where('id', $this->estabelecimentoId)
            ->update([
                'fv_status' => 'erro_email',
                'fv_erro'   => $exception?->getMessage() ?? 'Erro desconhecido',
            ]);
    }
}
