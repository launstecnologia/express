<?php

namespace App\Jobs;

use App\Models\Estabelecimento;
use App\Services\AutomacaoLogService;
use App\Services\AutomacaoPagBankService;
use App\Services\NotificacaoEmailService;
use App\Support\AutomacaoErroInterpretador;
use App\Support\AutomacaoSchema;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\EstabelecimentoSchema;
use App\Support\NotificacaoVars;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AutomacaoPagBankJob implements ShouldQueue
{
    use Queueable;

    /**
     * Uma única tentativa — o polling interno já trata retries.
     * Se a API Python estiver indisponível, o job falha e pode ser reprocessado manualmente.
     */
    public int $tries = 1;

    /** 20 minutos: cadastro FV + e-mail + proposta + safepay */
    public int $timeout = 1200;

    public function __construct(
        public readonly int $estabelecimentoId,
        public readonly string $senha6,
    ) {}

    public function handle(
        AutomacaoPagBankService $service,
        AutomacaoLogService $automacaoLog,
        NotificacaoEmailService $notificacao,
    ): void {
        $estab = Estabelecimento::withoutGlobalScopes()->find($this->estabelecimentoId);

        if (! $estab) {
            Log::warning('AutomacaoPagBankJob: estabelecimento não encontrado', [
                'id' => $this->estabelecimentoId,
            ]);

            return;
        }

        // Evita re-execução se já em andamento (a re-execução manual via controller já valida antes)
        if ($estab->fv_status === 'em_andamento') {
            Log::info('AutomacaoPagBankJob: automação já em andamento', ['id' => $estab->id]);

            return;
        }

        $estab->update([
            'fv_status'     => 'em_andamento',
            'fv_iniciado_em' => now(),
            'fv_erro'        => null,
        ]);

        try {
            $automacaoLog->registrarInicio($estab->id, 'Cadastro completo FV + e-mail + proposta');

            // 1. Inicia o job na API Python
            $jobId = $service->iniciarCadastro($estab, $this->senha6);

            $estab->update(['fv_job_id' => $jobId]);
            $automacaoLog->registrar($estab->id, "Job Python enfileirado (ID: {$jobId})", 'info', $jobId, 'Enfileirado');

            Log::info('AutomacaoPagBankJob: job Python iniciado', [
                'estabelecimento_id' => $estab->id,
                'job_id'             => $jobId,
            ]);

            // 2. Polling até concluir ou atingir timeout
            $intervalo   = (int) config('automacao.polling_intervalo_seg', 20);
            $maxTentativas = (int) config('automacao.polling_max_tentativas', 50);
            $statusFinal = null;
            $resultado   = null;

            for ($i = 0; $i < $maxTentativas; $i++) {
                sleep($intervalo);

                $status = $service->consultarStatusParaPolling($estab, $jobId);
                if ($status === null) {
                    continue;
                }

                $statusFinal = $status['status'] ?? 'desconhecido';
                $resultado   = $status['resultado'] ?? null;

                Log::debug("AutomacaoPagBankJob: poll {$i}/{$maxTentativas}", [
                    'job_id' => $jobId,
                    'status' => $statusFinal,
                ]);

                if (in_array($statusFinal, ['concluido', 'erro', 'erro_email', 'erro_proposta'], true)) {
                    break;
                }
            }

        // 3. Processa resultado
        if ($statusFinal === 'concluido') {
            $safepayId = $service->extrairSafepayIdDoResultado($resultado);

            $update = [
                'fv_status'       => 'concluido',
                'fv_senha_6'      => $this->senha6,
                'fv_concluido_em' => now(),
                'fv_erro'         => null,
                'status'          => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::APROVADO),
            ];

            if (filled($safepayId)) {
                $update['token_pagseguro'] = $safepayId;
            }

            $estab->update($update);

            $automacaoLog->registrarConclusao(
                $estab->id,
                'Automação concluída com sucesso'.(filled($safepayId) ? " — Safepay ID: {$safepayId}" : ''),
                $jobId,
                ['safepay_id' => $safepayId, 'resultado' => $resultado],
            );

            Log::info('AutomacaoPagBankJob: automação concluída com sucesso', [
                    'estabelecimento_id' => $estab->id,
                    'job_id'             => $jobId,
                    'safepay_id'         => $safepayId,
                ]);

                // Notifica o estabelecimento
                if (filled($estab->email)) {
                    $notificacao->enfileirar(
                        'pagbank.fv_concluido',
                        $estab->email,
                        NotificacaoVars::estabelecimento($estab),
                        route('estabelecimentos.show', $estab),
                    );
                }

            } elseif (in_array($statusFinal, ['erro', 'erro_email', 'erro_proposta'], true)) {
                $erroBruto = $status['erro'] ?? 'Erro desconhecido na automação';
                $erroInterpretado = AutomacaoErroInterpretador::interpretar($erroBruto, [
                    'resultado' => $resultado,
                ]);
                $erro = $erroInterpretado['mensagem_amigavel'];

                if ($statusFinal === 'erro_proposta') {
                    $update = array_merge(
                        AutomacaoSchema::atualizacaoErroProposta($estab, $erro),
                        ['fv_senha_6' => $this->senha6],
                    );
                } else {
                    $update = [
                        'fv_status' => $statusFinal,
                        'fv_erro'   => $erro,
                    ];
                }

                if ($update !== []) {
                    $estab->update($update);
                }

                $automacaoLog->registrarErro(
                    $estab->id,
                    $erro,
                    $jobId,
                    $erroInterpretado['etapa'] ?? $statusFinal,
                    [
                        'status' => $statusFinal,
                        'resultado' => $resultado,
                        'erro_tecnico' => $erroBruto,
                    ],
                );

                Log::error('AutomacaoPagBankJob: automação falhou', [
                    'estabelecimento_id' => $estab->id,
                    'job_id'             => $jobId,
                    'status'             => $statusFinal,
                    'erro'               => $erro,
                ]);

                // Notifica admins
                $notificacao->enfileirarParaAdmins(
                    'pagbank.fv_erro_admin',
                    array_merge(NotificacaoVars::estabelecimento($estab), [
                        'motivo' => $erro,
                        'fv_status' => $statusFinal,
                    ]),
                    route('estabelecimentos.show', $estab),
                );

            } else {
                // Timeout de polling — job ainda em andamento após o limite
                $statusTimeout = null;
                try {
                    $statusTimeout = $service->consultarStatusParaPolling($estab, $jobId)
                        ?? $service->consultarStatus($jobId);
                } catch (\Throwable) {
                    // mantém contexto mínimo
                }

                $contextoTimeout = $service->montarContextoErroJob($jobId, $statusTimeout);
                $erroBruto = AutomacaoErroInterpretador::mensagemTimeout($contextoTimeout);
                $erroInterpretado = AutomacaoErroInterpretador::interpretar($erroBruto, $contextoTimeout);
                $erro = $erroInterpretado['mensagem_amigavel'];

                $estab->update([
                    'fv_status' => 'timeout',
                    'fv_erro'   => $erro,
                ]);

                $automacaoLog->registrarErro(
                    $estab->id,
                    $erro,
                    $jobId ?? null,
                    $erroInterpretado['etapa'] ?? 'timeout',
                    array_merge($contextoTimeout, [
                        'status_final' => $statusFinal,
                        'tipo' => 'timeout_polling',
                    ]),
                );

                Log::warning('AutomacaoPagBankJob: timeout de polling', [
                    'estabelecimento_id' => $estab->id,
                    'job_id'             => $jobId,
                    'status_atual'       => $statusFinal,
                    'etapa_atual'        => $contextoTimeout['etapa_atual'] ?? null,
                    'codigo_versao'      => $contextoTimeout['codigo_versao'] ?? null,
                ]);
            }

        } catch (\Throwable $e) {
            $estab->update([
                'fv_status' => 'erro',
                'fv_erro'   => $e->getMessage(),
            ]);

            $automacaoLog->registrarErro($estab->id, $e->getMessage(), $estab->fv_job_id, 'excecao');

            Log::error('AutomacaoPagBankJob: exceção inesperada', [
                'estabelecimento_id' => $this->estabelecimentoId,
                'erro'               => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('AutomacaoPagBankJob: job falhou definitivamente', [
            'estabelecimento_id' => $this->estabelecimentoId,
            'erro'               => $exception?->getMessage(),
        ]);

        Estabelecimento::withoutGlobalScopes()
            ->where('id', $this->estabelecimentoId)
            ->update([
                'fv_status' => 'erro',
                'fv_erro'   => $exception?->getMessage() ?? 'Erro desconhecido',
            ]);
    }
}
