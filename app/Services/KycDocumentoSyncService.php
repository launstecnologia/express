<?php

namespace App\Services;

use App\Jobs\AnalisarDocumentoKycJob;
use App\Models\Estabelecimento;
use App\Models\EstabelecimentoDocumento;
use App\Models\KycDocumento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Support\KycTipoDocumentoMapper;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Storage;

class KycDocumentoSyncService
{
    public function __construct(
        private KycInicializacaoService $kycInicializacao,
        private KycHistoricoService $historico,
        private KycFinalizacaoService $finalizacao,
    ) {}

    public function sincronizar(
        EstabelecimentoDocumento $estabelecimentoDocumento,
        Usuario|SubUsuario|null $autor = null,
    ): ?KycDocumento {
        if (! PlatformSettings::kycAtivo()) {
            return null;
        }

        $tipoKyc = KycTipoDocumentoMapper::tipoKyc($estabelecimentoDocumento->tipo_documento);
        if (! $tipoKyc) {
            return null;
        }

        $estabelecimento = $estabelecimentoDocumento->estabelecimento
            ?? Estabelecimento::find($estabelecimentoDocumento->estabelecimento_id);

        if (! $estabelecimento) {
            return null;
        }

        $kyc = $this->kycInicializacao->iniciar($estabelecimento);

        $existente = KycDocumento::where('estabelecimento_documento_id', $estabelecimentoDocumento->id)->first();

        if ($existente && $existente->caminho === $estabelecimentoDocumento->arquivo_path) {
            if ($this->precisaAnalise($existente)) {
                $this->dispararAnalise($existente);
            }

            return $existente;
        }

        KycDocumento::query()
            ->where('kyc_analise_id', $kyc->id)
            ->where('tipo', $tipoKyc)
            ->where(function ($q) use ($estabelecimentoDocumento) {
                $q->whereNull('estabelecimento_documento_id')
                    ->orWhere('estabelecimento_documento_id', '!=', $estabelecimentoDocumento->id);
            })
            ->each(function (KycDocumento $antigo) {
                if (! $antigo->estabelecimento_documento_id) {
                    Storage::disk('local')->delete($antigo->caminho);
                }
                $antigo->delete();
            });

        $mimeType = Storage::disk('public')->mimeType($estabelecimentoDocumento->arquivo_path)
            ?: 'application/octet-stream';

        $atributos = [
            'kyc_analise_id' => $kyc->id,
            'estabelecimento_id' => $estabelecimento->id,
            'tipo' => $tipoKyc,
            'nome_original' => $estabelecimentoDocumento->arquivo_nome ?: basename($estabelecimentoDocumento->arquivo_path),
            'caminho' => $estabelecimentoDocumento->arquivo_path,
            'mime_type' => $mimeType,
            'tamanho_bytes' => (int) Storage::disk('public')->size($estabelecimentoDocumento->arquivo_path),
            'enviado_por_id' => $autor?->id,
            'enviado_por_tipo' => $autor instanceof SubUsuario ? 'sub_usuario' : ($autor ? 'usuario' : null),
            'openai_status' => 'pendente',
            'openai_motivo_reprovacao' => null,
            'openai_dados_extraidos' => null,
            'cruzamento_status' => 'nao_verificado',
            'cruzamento_divergencias' => null,
            'admin_override' => null,
            'admin_override_motivo' => null,
        ];

        $documento = KycDocumento::updateOrCreate(
            ['estabelecimento_documento_id' => $estabelecimentoDocumento->id],
            $atributos
        );

        $this->historico->registrar(
            $kyc,
            'documento_sincronizado',
            "Documento \"{$estabelecimentoDocumento->tipo_documento}\" vinculado ao KYC",
            ['documento_id' => $documento->id, 'tipo' => $tipoKyc],
            $autor,
        );

        if ($documento->wasRecentlyCreated || $documento->wasChanged('caminho')) {
            $this->dispararAnalise($documento);
        }

        return $documento;
    }

    public function sincronizarTodosDoEstabelecimento($estabelecimento): void
    {
        $estabelecimento->loadMissing('documentos');

        foreach ($estabelecimento->documentos as $documento) {
            $this->sincronizar($documento);
        }

        $this->processarAnalisesPendentes($estabelecimento);
    }

    public function processarAnalisesPendentes($estabelecimento): int
    {
        $kyc = $estabelecimento->kycAnalise;
        if (! $kyc) {
            return 0;
        }

        $pendentes = KycDocumento::where('kyc_analise_id', $kyc->id)
            ->whereIn('openai_status', ['pendente', 'revisao_manual'])
            ->whereNull('openai_analisado_em')
            ->get();

        $disparados = 0;
        foreach ($pendentes as $documento) {
            if ($this->precisaAnalise($documento)) {
                $this->dispararAnalise($documento);
                $disparados++;
            }
        }

        return $disparados;
    }

    private function precisaAnalise(KycDocumento $documento): bool
    {
        if ($documento->admin_override) {
            return false;
        }

        if ($documento->openai_analisado_em) {
            return false;
        }

        return in_array($documento->openai_status, ['pendente', 'revisao_manual'], true);
    }

    public function removerPorEstabelecimentoDocumento(EstabelecimentoDocumento $estabelecimentoDocumento): void
    {
        $kycDoc = KycDocumento::where('estabelecimento_documento_id', $estabelecimentoDocumento->id)->first();

        if (! $kycDoc) {
            return;
        }

        $kyc = $kycDoc->kycAnalise;
        $kycDoc->delete();

        if ($kyc) {
            $this->historico->registrar($kyc, 'documento_removido', 'Documento desvinculado do KYC');
            $this->finalizacao->verificar($kyc->id);
        }
    }

    public function dispararAnalise(KycDocumento $documento): void
    {
        if (! PlatformSettings::diditConfigurado()) {
            $documento->update([
                'openai_status' => 'revisao_manual',
                'openai_motivo_reprovacao' => 'Didit não habilitada no painel — análise manual necessária.',
            ]);
            $this->finalizacao->verificar($documento->kyc_analise_id);

            return;
        }

        if ($documento->admin_override) {
            return;
        }

        if (! $this->tipoElegivelDidit($documento->tipo)) {
            $documento->update([
                'openai_status' => 'revisao_manual',
                'openai_motivo_reprovacao' => 'Tipo de documento não analisado automaticamente pela Didit — revisão manual necessária.',
            ]);
            $this->finalizacao->verificar($documento->kyc_analise_id);

            return;
        }

        if (! $this->mimeSuportadoDidit($documento->mime_type)) {
            $documento->update([
                'openai_status' => 'revisao_manual',
                'openai_motivo_reprovacao' => 'Formato não suportado pela Didit (use JPG, PNG, WEBP ou PDF).',
            ]);
            $this->finalizacao->verificar($documento->kyc_analise_id);

            return;
        }

        $documento->update(['openai_status' => 'processando']);
        AnalisarDocumentoKycJob::dispatch($documento->fresh());
    }

    private function tipoElegivelDidit(string $tipo): bool
    {
        return in_array($tipo, [
            'rg_frente',
            'cnh_frente',
            'rg_verso',
            'cnh_verso',
            'comprovante_endereco',
        ], true);
    }

    private function mimeSuportadoDidit(?string $mime): bool
    {
        if (! $mime) {
            return false;
        }

        return in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ], true);
    }
}
