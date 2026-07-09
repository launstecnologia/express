<?php

namespace App\Jobs;

use App\Models\Estabelecimento;
use App\Services\AutomacaoLogService;
use App\Services\AutomacaoPagBankService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AutomacaoBuscarSafepayJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $estabelecimentoId) {}

    public function handle(AutomacaoPagBankService $service, AutomacaoLogService $automacaoLog): void
    {
        $estab = Estabelecimento::withoutGlobalScopes()->find($this->estabelecimentoId);

        if (! $estab) {
            Log::warning('AutomacaoBuscarSafepayJob: estabelecimento não encontrado', [
                'id' => $this->estabelecimentoId,
            ]);

            return;
        }

        try {
            $automacaoLog->registrarInicio($estab->id, 'Buscar Safepay ID no FV');

            $jobId = $service->iniciarBuscaSafepayId($estab);
            $intervalo = (int) config('automacao.polling_intervalo_seg', 20);
            $maxTentativas = 15;

            for ($i = 0; $i < $maxTentativas; $i++) {
                sleep($intervalo);

                $status = $service->consultarStatusParaPolling($estab, $jobId);
                if ($status === null) {
                    continue;
                }

                $statusFinal = $status['status'] ?? 'desconhecido';

                if (! in_array($statusFinal, ['concluido', 'erro'], true)) {
                    continue;
                }

                if ($statusFinal === 'concluido') {
                    $safepayId = $service->extrairSafepayIdDoResultado($status['resultado'] ?? null);

                    if (filled($safepayId)) {
                        $estab->update(['token_pagseguro' => $safepayId]);
                        $automacaoLog->registrarConclusao(
                            $estab->id,
                            "Safepay ID encontrado: {$safepayId}",
                            $jobId,
                            ['safepay_id' => $safepayId],
                        );
                        Log::info('AutomacaoBuscarSafepayJob: Safepay ID salvo', [
                            'estabelecimento_id' => $estab->id,
                            'safepay_id' => $safepayId,
                        ]);
                    } else {
                        $automacaoLog->registrarErro($estab->id, 'Job concluído sem Safepay ID', $jobId, 'aviso');
                        Log::warning('AutomacaoBuscarSafepayJob: job concluído sem Safepay ID', [
                            'estabelecimento_id' => $estab->id,
                            'job_id' => $jobId,
                        ]);
                    }
                } else {
                    $automacaoLog->registrarErro(
                        $estab->id,
                        $status['erro'] ?? 'Erro desconhecido na busca Safepay',
                        $jobId,
                        'erro',
                    );
                    Log::error('AutomacaoBuscarSafepayJob: falha na busca', [
                        'estabelecimento_id' => $estab->id,
                        'job_id' => $jobId,
                        'erro' => $status['erro'] ?? 'Erro desconhecido',
                    ]);
                }

                return;
            }

            Log::warning('AutomacaoBuscarSafepayJob: timeout de polling', [
                'estabelecimento_id' => $estab->id,
                'job_id' => $jobId,
            ]);
        } catch (\Throwable $e) {
            Log::error('AutomacaoBuscarSafepayJob: exceção', [
                'estabelecimento_id' => $estab->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
