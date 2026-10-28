<?php

namespace App\Services;

use App\Jobs\AutomacaoPagBankJob;
use App\Models\Estabelecimento;
use App\Models\KycAnalise;
use App\Models\KycDocumento;
use App\Models\Usuario;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\EstabelecimentoSchema;
use App\Support\KycDocumentosObrigatorios;
use App\Support\NotificacaoVars;
use App\Support\PlatformSettings;
use Illuminate\Support\Collection;

class KycFinalizacaoService
{
    public function __construct(
        private KycHistoricoService $historico,
        private PagBankCadastroDispatcher $pagBankCadastro,
        private NotificacaoEmailService $notificacao,
    ) {}

    public function verificar(int $kycAnaliseId): void
    {
        $kyc = KycAnalise::with('estabelecimento')->findOrFail($kycAnaliseId);
        $estab = $kyc->estabelecimento;
        $docs = KycDocumento::where('kyc_analise_id', $kycAnaliseId)->get();

        if (! $this->obrigatoriosAnalisados($estab, $docs)) {
            return;
        }

        $obrigatorios = $this->documentosObrigatoriosPresentes($estab, $docs);

        $reprovados = $obrigatorios->filter(fn (KycDocumento $doc) => $doc->statusEfetivo() === 'reprovado');
        if ($reprovados->isNotEmpty()) {
            $kyc->update(['status' => 'reprovado']);
            $estab->update(['status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::NEGADO), 'risco' => 'bloqueado']);
            $this->historico->registrar($kyc, 'reprovado_automatico', 'Documento reprovado pela análise automática');
            $this->notificarKyc($kyc->fresh(), 'kyc.reprovado', 'Documento reprovado pela análise automática.');

            return;
        }

        $revisao = $obrigatorios->filter(fn (KycDocumento $doc) => $doc->statusEfetivo() === 'revisao_manual');
        if ($revisao->isNotEmpty()) {
            $kyc->update(['status' => 'revisao_manual']);
            $this->historico->registrar($kyc, 'kyc_revisao_manual', 'KYC aguardando revisão manual');
            $this->notificacao->enfileirarParaAdmins('kyc.revisao_manual', NotificacaoVars::kyc($kyc), route('admin.kyc.show', $kyc));

            return;
        }

        $kyc->update(['status' => 'em_analise']);
        $this->historico->registrar($kyc, 'kyc_em_analise', 'Todos os documentos analisados — aguardando Admin');
        $this->notificarKyc($kyc->fresh(), 'kyc.em_analise');
    }

    public function aprovar(KycAnalise $kyc, int $adminId, ?string $motivo = null): void
    {
        $kyc->update([
            'status' => 'aprovado',
            'admin_id' => $adminId,
            'admin_decisao' => 'aprovado',
            'admin_motivo' => $motivo,
            'admin_decidido_em' => now(),
        ]);

        $estab = $kyc->estabelecimento;
        $estab->update(['status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::PENDENTE)]);

        $this->pagBankCadastro->enfileirar($estab->fresh());
        $this->dispararAutomacaoFv($estab->fresh());

        $this->historico->registrar(
            $kyc,
            'admin_aprovado',
            $motivo,
            null,
            Usuario::find($adminId),
        );

        $this->notificarKyc($kyc->fresh(), 'kyc.aprovado', $motivo);
    }

    public function enfileirarAutomacaoAposAprovacao(Estabelecimento $estab): bool
    {
        $estab = $estab->fresh();
        $this->pagBankCadastro->enfileirar($estab);

        return $this->dispararAutomacaoFv($estab, exigirWebmail: true);
    }

    public function aprovarAutomaticoNoCadastro(Estabelecimento $estab, ?Usuario $usuario = null): bool
    {
        $kyc = KycAnalise::firstOrCreate(
            ['estabelecimento_id' => $estab->id],
            ['status' => 'pendente'],
        );

        if ($kyc->status === 'aprovado') {
            return $this->dispararAutomacaoFv($estab->fresh(), exigirWebmail: true);
        }

        $kyc->update([
            'status' => 'aprovado',
            'admin_id' => $usuario?->id,
            'admin_decisao' => 'aprovado',
            'admin_motivo' => 'Aprovado automaticamente no cadastro',
            'admin_decidido_em' => now(),
        ]);

        $estab->update(['status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::PENDENTE)]);

        $this->historico->registrar(
            $kyc,
            'aprovado_automatico_cadastro',
            'KYC aprovado automaticamente no cadastro — documentos dispensados',
            null,
            $usuario,
        );

        return $this->dispararAutomacaoFv($estab->fresh(), exigirWebmail: true);
    }

    private function dispararAutomacaoFv(Estabelecimento $estab, bool $exigirWebmail = false): bool
    {
        if ($exigirWebmail && (! filled($estab->webmail_email) || ! filled($estab->webmail_senha))) {
            return false;
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            return false;
        }

        if (in_array($estab->fv_status, ['em_andamento', 'concluido'], true)) {
            return false;
        }

        $senha6 = $this->gerarSenha6();

        AutomacaoPagBankJob::dispatch($estab->id, $senha6)
            ->onQueue('automacao')
            ->delay(now()->addSeconds(10));

        $estab->update(['fv_status' => 'pendente']);

        return true;
    }

    public function reprovar(KycAnalise $kyc, int $adminId, string $motivo): void
    {
        $kyc->update([
            'status' => 'reprovado',
            'admin_id' => $adminId,
            'admin_decisao' => 'reprovado',
            'admin_motivo' => $motivo,
            'admin_decidido_em' => now(),
        ]);

        $kyc->estabelecimento->update([
            'status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::NEGADO),
            'risco' => 'bloqueado',
        ]);

        $this->historico->registrar(
            $kyc,
            'admin_reprovado',
            $motivo,
            null,
            Usuario::find($adminId),
        );

        $this->notificarKyc($kyc->fresh(), 'kyc.reprovado', $motivo);
    }

    private function notificarKyc(KycAnalise $kyc, string $slug, ?string $motivo = null): void
    {
        $kyc->loadMissing('estabelecimento');
        $vars = NotificacaoVars::kyc($kyc, $motivo);
        $email = $vars['email'] ?? $kyc->estabelecimento?->email;

        if (blank($email)) {
            return;
        }

        $link = route('estabelecimentos.show', $kyc->estabelecimento_id);
        $this->notificacao->enfileirar($slug, $email, $vars, $link);
    }

    private function gerarSenha6(): string
    {
        // Gera 6 dígitos numéricos evitando sequências óbvias (1234, 111111, etc.)
        do {
            $senha = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $ehSequencia = preg_match('/^(\d)\1{5}$/', $senha)
                || in_array($senha, ['123456', '654321', '012345', '567890'], true);
        } while ($ehSequencia);

        return $senha;
    }

    private function obrigatoriosAnalisados($estab, Collection $docs): bool
    {
        foreach (KycDocumentosObrigatorios::grupos($estab) as $grupo) {
            $doc = $docs->first(fn (KycDocumento $d) => in_array($d->tipo, $grupo['tipos'], true));
            if (! $doc) {
                return false;
            }
            if (in_array($doc->openai_status, ['pendente', 'processando'], true) && ! $doc->admin_override) {
                return false;
            }
        }

        return true;
    }

    private function documentosObrigatoriosPresentes($estab, Collection $docs): Collection
    {
        $selecionados = collect();

        foreach (KycDocumentosObrigatorios::grupos($estab) as $grupo) {
            $doc = $docs->first(fn (KycDocumento $d) => in_array($d->tipo, $grupo['tipos'], true));
            if ($doc) {
                $selecionados->push($doc);
            }
        }

        return $selecionados;
    }
}
