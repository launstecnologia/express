<?php

namespace App\Support;

use App\Models\Chamado;
use App\Models\Estabelecimento;
use App\Models\KycAnalise;
use App\Models\SubUsuario;
use App\Models\Usuario;

class NotificacaoVars
{
    /**
     * @return array<string, string>
     */
    public static function estabelecimento(Estabelecimento $estab): array
    {
        $nome = $estab->nome_fantasia ?: $estab->razao_social ?: $estab->nome_completo ?: 'Estabelecimento';
        $documento = $estab->pessoa_tipo === 'fisica'
            ? (string) $estab->cpf
            : (string) $estab->cnpj;

        return [
            'nome' => $nome,
            'estabelecimento' => $nome,
            'documento' => $documento ?: '-',
            'email' => (string) ($estab->email ?? ''),
            'link' => route('estabelecimentos.show', $estab),
            'link_documentos' => filled($estab->documento_token_publico)
                ? route('documentos.public.create', $estab->documento_token_publico)
                : '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kyc(KycAnalise $kyc, ?string $motivo = null): array
    {
        $estab = $kyc->estabelecimento ?? Estabelecimento::withoutGlobalScopes()->find($kyc->estabelecimento_id);

        $vars = $estab ? self::estabelecimento($estab) : ['estabelecimento' => 'Estabelecimento', 'nome' => 'Cliente'];

        if ($motivo) {
            $vars['motivo'] = $motivo;
        }

        $vars['link'] = $estab
            ? route('admin.kyc.show', $kyc)
            : route('admin.kyc.index');

        return $vars;
    }

    /**
     * @return array<string, string>
     */
    public static function chamado(Chamado $chamado, ?string $mensagem = null): array
    {
        $autor = self::emailAutorChamado($chamado);

        return [
            'numero' => $chamado->numero,
            'titulo' => $chamado->titulo,
            'categoria' => ucfirst($chamado->categoria),
            'prioridade' => ucfirst($chamado->prioridade),
            'status' => ucfirst(str_replace('_', ' ', $chamado->status)),
            'nome' => $autor['nome'],
            'mensagem' => $mensagem ?? '',
            'link' => route('admin.chamados.show', $chamado->numero),
        ];
    }

    /**
     * @return array{nome: string, email: ?string}
     */
    public static function emailAutorChamado(Chamado $chamado): array
    {
        if ($chamado->aberto_por_tipo === 'sub_usuario') {
            $sub = SubUsuario::find($chamado->aberto_por_id);

            return [
                'nome' => $sub?->nome ?? 'Usuário',
                'email' => $sub?->email,
            ];
        }

        $usuario = Usuario::find($chamado->aberto_por_id);

        return [
            'nome' => $usuario?->nomeExibicao() ?? 'Usuário',
            'email' => $usuario?->email,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function usuario(Usuario $usuario, ?string $senhaTemporaria = null): array
    {
        return [
            'nome' => $usuario->nomeExibicao(),
            'email' => $usuario->email,
            'perfil' => ucfirst($usuario->tipo),
            'link' => route('login'),
            'senha' => $senhaTemporaria ?? '',
        ];
    }
}
