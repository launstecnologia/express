<?php

namespace App\Jobs;

use App\Models\KycDocumento;
use App\Services\Didit\DiditApiException;
use App\Services\DiditKycService;
use App\Services\KycCruzamentoService;
use App\Services\KycFinalizacaoService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class AnalisarDocumentoKycJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(public KycDocumento $documento) {}

    public function handle(
        DiditKycService $diditKyc,
        KycCruzamentoService $cruzamento,
        KycFinalizacaoService $finalizacao,
    ): void {
        $lock = $this->travaDoPar($this->documento);

        try {
            $lock?->block(25);

            $documento = $this->documento->fresh(['estabelecimento', 'kycAnalise']);
            if (! $documento) {
                return;
            }

            if ($documento->openai_analisado_em && str_starts_with((string) $documento->openai_modelo, 'didit')) {
                return;
            }

            $documento->update(['openai_status' => 'processando']);

            $resultado = $diditKyc->analisarDocumento($documento);
            $this->gravarResultado($documento, $resultado, $cruzamento);
            $finalizacao->verificar($documento->kyc_analise_id);
        } catch (DiditApiException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                throw $e;
            }

            $this->registrarFalha($this->documento->fresh() ?? $this->documento, $finalizacao, $e->getMessage());
        } catch (LockTimeoutException $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $this->registrarFalha($this->documento->fresh() ?? $this->documento, $finalizacao, 'A análise Didit do documento ainda estava em andamento.');
        } catch (\Throwable $e) {
            $this->registrarFalha($this->documento->fresh() ?? $this->documento, $finalizacao, 'Erro na análise Didit: '.$e->getMessage());
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param  array{status: string, dados: array, motivo: ?string, confianca: float, tokens: null, modelo: string, ppid_consulta_id: ?string, outros_documentos?: array<int, int>}  $resultado
     */
    private function gravarResultado(KycDocumento $documento, array $resultado, KycCruzamentoService $cruzamento): void
    {
        $ids = array_values(array_unique(array_filter([
            $documento->id,
            ...($resultado['outros_documentos'] ?? []),
        ])));

        $documentos = KycDocumento::query()->whereIn('id', $ids)->get();
        if ($documentos->isEmpty()) {
            $documentos = collect([$documento]);
        }

        foreach ($documentos as $doc) {
            $doc->update([
                'openai_status' => $resultado['status'],
                'openai_dados_extraidos' => $resultado['dados'],
                'openai_motivo_reprovacao' => $resultado['motivo'] ?? null,
                'openai_confianca' => $resultado['confianca'],
                'openai_tokens_usados' => $resultado['tokens'],
                'openai_modelo' => $resultado['modelo'],
                'openai_analisado_em' => now(),
                'ppid_consulta_id' => $resultado['ppid_consulta_id'] ?? null,
            ]);

            if ($resultado['status'] === 'aprovado') {
                $cruzamento->cruzar($doc->fresh());
            }
        }
    }

    private function travaDoPar(KycDocumento $documento): ?Lock
    {
        $familia = match ($documento->tipo) {
            'rg_frente', 'rg_verso' => 'rg',
            'cnh_frente', 'cnh_verso' => 'cnh',
            default => null,
        };

        if (! $familia || ! $documento->kyc_analise_id) {
            return null;
        }

        return Cache::lock('didit-kyc-'.$documento->kyc_analise_id.'-'.$familia, 90);
    }

    private function registrarFalha(KycDocumento $documento, KycFinalizacaoService $finalizacao, string $motivo): void
    {
        $documento->update([
            'openai_status' => 'revisao_manual',
            'openai_motivo_reprovacao' => mb_substr($motivo, 0, 480),
        ]);

        $finalizacao->verificar($documento->kyc_analise_id);
    }
}
