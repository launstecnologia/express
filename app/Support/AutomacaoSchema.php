<?php

namespace App\Support;

use App\Models\Estabelecimento;
use Illuminate\Support\Facades\Schema;

class AutomacaoSchema
{
    public static function temTabelaLogs(): bool
    {
        return Schema::hasTable('automacao_logs');
    }

    public static function temColunasProposta(): bool
    {
        return Schema::hasColumn('estabelecimentos', 'fv_proposta_status');
    }

    /**
     * @return array<string, mixed>
     */
    public static function atualizacaoProposta(string $status, ?string $erro = null): array
    {
        if (! self::temColunasProposta()) {
            return [];
        }

        $dados = ['fv_proposta_status' => $status];

        if ($erro !== null) {
            $dados['fv_proposta_erro'] = $erro;
        }

        if ($status === 'concluido') {
            $dados['fv_proposta_concluido_em'] = now();
        }

        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    public static function atualizacaoErroProposta(Estabelecimento $estab, string $erro): array
    {
        $update = self::atualizacaoProposta('erro', $erro);

        if (filled($estab->fv_concluido_em)) {
            return $update;
        }

        return array_merge($update, [
            'fv_status' => 'erro_proposta',
            'fv_erro'   => $erro,
        ]);
    }

    /**
     * Detecta nos logs se o cadastro no portal FV já foi concluído e a automação
     * entrou na etapa de webmail / senha (inclui falha de polling do Laravel).
     */
    public static function cadastroFvConcluidoNosLogs(iterable $logs): bool
    {
        $marcadores = [
            'cadastro pagbank concluído',
            'iniciando e-mail',
            'acessando webmail',
            'fazendo login no webmail',
            'aguardando e-mail do pagbank',
            'finalizando cadastro no pagbank',
            'criando senha de acesso',
            'confirmando senha',
        ];

        foreach ($logs as $log) {
            $msg = strtolower((string) ($log->mensagem ?? ''));
            foreach ($marcadores as $marcador) {
                if (str_contains($msg, $marcador)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function podeRetentarApenasEmail(Estabelecimento $estab, iterable $logs): bool
    {
        if ($estab->fv_status === 'erro_email') {
            return true;
        }

        return in_array($estab->fv_status, ['erro', 'timeout'], true)
            && self::cadastroFvConcluidoNosLogs($logs);
    }
}
