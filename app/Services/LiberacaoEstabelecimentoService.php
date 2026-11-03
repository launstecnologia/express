<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\KycAnalise;
use App\Models\Usuario;
use App\Support\EmailPagBank;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\EstabelecimentoSchema;
use Illuminate\Support\Facades\Schema;

class LiberacaoEstabelecimentoService
{
    /**
     * Aprova o estabelecimento e inicia o e-mail da plataforma e o cadastro na PagBank.
     *
     * @return array{0: string, 1: ?string}
     */
    public function liberar(Estabelecimento $estabelecimento): array
    {
        $estabelecimento = $estabelecimento->fresh() ?? $estabelecimento;

        if ($this->jaAprovado($estabelecimento)) {
            return ['', null];
        }

        $estabelecimento->update([
            'status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::APROVADO),
        ]);

        return $this->iniciarEmailEPagBank($estabelecimento->fresh());
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    public function iniciarEmailEPagBank(Estabelecimento $estabelecimento): array
    {
        $aviso = null;
        $emailOk = EmailPagBank::daPlataforma($estabelecimento->webmail_email) !== null;
        $criouAgora = false;

        if (! $emailOk && config('directadmin.criar_email_ao_habilitar') && filled($estabelecimento->email)) {
            try {
                $ocupado = app(EmailPlataformaService::class)->provisionarAutomatico($estabelecimento);
                if ($ocupado !== null) {
                    $aviso = "O username \"{$ocupado}\" já existe no servidor de e-mail. Defina o e-mail da plataforma manualmente para seguir com a PagBank.";
                } else {
                    $emailOk = true;
                    $criouAgora = true;
                    $estabelecimento = $estabelecimento->fresh();
                }
            } catch (\Throwable) {
                $aviso = 'Não foi possível criar o e-mail da plataforma. O cadastro na PagBank não foi iniciado.';
            }
        }

        $pagbank = $emailOk
            && app(KycFinalizacaoService::class)->enfileirarAutomacaoAposAprovacao($estabelecimento);

        if ($pagbank) {
            $texto = $criouAgora
                ? ' E-mail da plataforma criado e cadastro na PagBank enfileirado.'
                : ' Cadastro na PagBank enfileirado.';

            return [$texto, $aviso];
        }

        if ($emailOk) {
            return [' O e-mail da plataforma está pronto, mas o cadastro na PagBank não foi enfileirado.', $aviso];
        }

        return ['', $aviso ?: 'O e-mail da plataforma não foi criado, então a PagBank não foi iniciada.'];
    }

    /**
     * Aprova o KYC, dispensa a assinatura do termo, cria o e-mail da plataforma e inicia a automação.
     *
     * @return array{0: string, 1: ?string}
     */
    public function liberarSemTermo(Estabelecimento $estabelecimento, Usuario $admin): array
    {
        $kyc = KycAnalise::firstOrCreate(
            ['estabelecimento_id' => $estabelecimento->id],
            ['status' => 'pendente'],
        );

        $kyc->update([
            'status' => 'aprovado',
            'admin_id' => $admin->id,
            'admin_decisao' => 'aprovado',
            'admin_motivo' => 'Liberado pelo administrador sem assinatura do termo e sem aguardar o KYC.',
            'admin_decidido_em' => now(),
        ]);

        app(KycHistoricoService::class)->registrar(
            $kyc,
            'admin_liberado_sem_termo',
            'KYC dispensado e cadastro aprovado sem assinatura do termo',
            null,
            $admin,
        );

        $dados = [
            'status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::APROVADO),
        ];

        if (Schema::hasColumn('estabelecimentos', 'aceite_dispensado_em')) {
            $dados['aceite_dispensado_em'] = now();
            $dados['aceite_dispensado_por'] = $admin->id;
        }

        $estabelecimento->update($dados);

        return $this->iniciarEmailEPagBank($estabelecimento->fresh());
    }

    private function jaAprovado(Estabelecimento $estabelecimento): bool
    {
        return EstabelecimentoEtapaListagem::normalizarStatus($estabelecimento->status) === EstabelecimentoEtapaListagem::APROVADO;
    }
}
