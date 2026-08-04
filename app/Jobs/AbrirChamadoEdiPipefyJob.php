<?php

namespace App\Jobs;

use App\Models\EdiPipefySolicitacao;
use App\Services\EdiPipefySolicitacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AbrirChamadoEdiPipefyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** 15 minutos — navegação Pipefy + polling */
    public int $timeout = 900;

    public function __construct(
        public readonly int $solicitacaoId,
    ) {}

    public function handle(EdiPipefySolicitacaoService $service): void
    {
        $solicitacao = EdiPipefySolicitacao::query()->find($this->solicitacaoId);

        if (! $solicitacao) {
            Log::warning('AbrirChamadoEdiPipefyJob: solicitação não encontrada', [
                'id' => $this->solicitacaoId,
            ]);

            return;
        }

        try {
            $solicitacao->update([
                'status' => 'em_andamento',
                'erro' => null,
            ]);

            $jobId = $service->dispararAutomacao($solicitacao);
            $solicitacao->update(['automacao_job_id' => $jobId]);

            $maxTentativas = (int) config('automacao.polling_max_tentativas', 30);
            $intervalo = (int) config('automacao.polling_intervalo_seg', 20);

            for ($i = 0; $i < $maxTentativas; $i++) {
                sleep(max(5, $intervalo));

                $status = $service->consultarStatusAutomacao($jobId);
                $estado = (string) ($status['status'] ?? '');

                if (in_array($estado, ['concluido', 'erro', 'erro_email', 'erro_proposta'], true)) {
                    $resultado = $status['resultado'] ?? null;
                    $detalhe = is_array($resultado) ? ($resultado['detalhe'] ?? $resultado) : null;

                    if ($estado === 'concluido') {
                        $solicitacao->update([
                            'status' => 'concluido',
                            'concluido_em' => now(),
                            'pipefy_card_id' => data_get($detalhe, 'card_id') ?: data_get($resultado, 'card_id'),
                            'resultado' => is_array($resultado) ? $resultado : ['raw' => $resultado],
                            'screenshots' => data_get($detalhe, 'screenshots') ?: data_get($resultado, 'screenshots'),
                            'erro' => null,
                        ]);

                        Log::info('AbrirChamadoEdiPipefyJob: concluído', [
                            'solicitacao_id' => $solicitacao->id,
                            'job_id' => $jobId,
                        ]);

                        return;
                    }

                    $solicitacao->update([
                        'status' => 'erro',
                        'concluido_em' => now(),
                        'erro' => $status['erro'] ?? 'Falha na automação Pipefy EDI',
                        'resultado' => is_array($resultado) ? $resultado : null,
                        'screenshots' => data_get($detalhe, 'screenshots'),
                    ]);

                    Log::error('AbrirChamadoEdiPipefyJob: falhou', [
                        'solicitacao_id' => $solicitacao->id,
                        'erro' => $solicitacao->erro,
                    ]);

                    return;
                }
            }

            $solicitacao->update([
                'status' => 'erro',
                'concluido_em' => now(),
                'erro' => 'Timeout aguardando automação Pipefy EDI',
            ]);
        } catch (\Throwable $e) {
            Log::error('AbrirChamadoEdiPipefyJob: exceção', [
                'solicitacao_id' => $this->solicitacaoId,
                'erro' => $e->getMessage(),
            ]);

            $solicitacao->update([
                'status' => 'erro',
                'concluido_em' => now(),
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
