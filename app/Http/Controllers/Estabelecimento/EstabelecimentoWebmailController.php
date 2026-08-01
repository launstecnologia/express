<?php

namespace App\Http\Controllers\Estabelecimento;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Services\DirectAdminService;
use App\Services\EmailPlataformaService;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EstabelecimentoWebmailController extends Controller
{
    public function __construct(
        private readonly EmailPlataformaService $emailPlataforma,
    ) {}

    /**
     * Cria manualmente a conta de e-mail da plataforma para o estabelecimento.
     */
    public function criar(Request $request, Estabelecimento $estabelecimento)
    {
        abort_unless(blank($estabelecimento->webmail_email), 422, 'Este estabelecimento já possui e-mail da plataforma.');

        $dados = $request->validate([
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/i'],
        ]);

        try {
            $this->emailPlataforma->provisionar($estabelecimento, $dados['username']);
        } catch (\Throwable $e) {
            return back()->withErrors(['username' => $e->getMessage()])->withInput();
        }

        $email = $estabelecimento->fresh()->webmail_email;

        return redirect()->route('estabelecimentos.show', $estabelecimento)
            ->with('status', "E-mail {$email} criado com sucesso.");
    }

    /**
     * Cria uma nova conta de e-mail da plataforma substituindo a atual.
     * Útil quando há conflito de e-mail entre clientes ou para troca de endereço.
     * Restrito a administradores.
     */
    public function recriar(Request $request, Estabelecimento $estabelecimento)
    {
        abort_unless(in_array($request->user()?->tipo, ['admin', 'marketplace'], true), 403);
        abort_unless(filled($estabelecimento->webmail_email), 422, 'Este estabelecimento ainda não possui e-mail da plataforma.');

        $dados = $request->validate([
            'username_novo' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/i'],
        ]);

        try {
            $this->emailPlataforma->recriar($estabelecimento, $dados['username_novo']);
        } catch (\Throwable $e) {
            return back()->withErrors(['username_novo' => $e->getMessage()])->withInput();
        }

        $email = $estabelecimento->fresh()->webmail_email;

        return redirect()->route('estabelecimentos.show', $estabelecimento)
            ->with('status', "Novo e-mail {$email} criado com sucesso. A caixa anterior foi removida.");
    }

    /**
     * Mostra as credenciais da caixa de e-mail da plataforma e um atalho para
     * abrir o Roundcube (na VPS do e-mail, domínio separado).
     *
     * Não é possível fazer login automático de verdade aqui: o Roundcube fica
     * num domínio diferente (mail.express.app.br) e o token CSRF da tela de
     * login dele é amarrado à sessão de quem o busca. Se o nosso servidor
     * busca o token, ele fica preso à sessão do servidor — o POST feito pelo
     * navegador do usuário (sessão diferente) é sempre rejeitado com 401.
     * Corrigir isso de verdade exigiria um proxy reverso do Roundcube pelo
     * nosso próprio domínio ou o SSO nativo do DirectAdmin.
     */
    public function sso(Estabelecimento $estabelecimento)
    {
        abort_unless(filled($estabelecimento->webmail_email), 404, 'Nenhuma caixa de e-mail configurada.');
        abort_unless(filled($estabelecimento->webmail_senha), 404, 'Senha do e-mail não disponível.');

        $webmailUrl = rtrim((string) (PlatformSettings::automacaoWebmailUrl() ?? config('directadmin.webmail_url')), '/');

        return view('estabelecimento.webmail-sso', [
            'webmailUrl' => $webmailUrl,
            'email'      => $estabelecimento->webmail_email,
            'senha'      => $estabelecimento->webmail_senha,
        ]);
    }

    /**
     * Reconfigura o forwarder do e-mail da plataforma:
     * deleta o forwarder antigo e recria com cópia local.
     */
    public function reconfigurarForwarder(Request $request, Estabelecimento $estabelecimento)
    {
        abort_unless($request->user()?->tipo === 'admin', 403);
        abort_unless(filled($estabelecimento->webmail_email), 422, 'Nenhum e-mail da plataforma configurado.');
        abort_unless(filled($estabelecimento->email), 422, 'E-mail original do estabelecimento não informado.');

        $da       = app(DirectAdminService::class);
        $username = Str::before($estabelecimento->webmail_email, '@');

        // Deleta forwarder existente (ignora erro se não existir)
        try {
            $da->excluirForwarderPlataforma($username);
        } catch (\Throwable) {}

        // Recria com cópia local
        $ok = $da->redirecionarEmailPlataforma($username, $estabelecimento->email);

        if (! $ok) {
            return redirect()->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['webmail' => 'Não foi possível reconfigurar o forwarder no servidor.']);
        }

        return redirect()->route('estabelecimentos.show', $estabelecimento)
            ->with('status', 'Forwarder reconfigurado com sucesso. Agora e-mails ficam com cópia no Roundcube e são encaminhados para ' . $estabelecimento->email);
    }

    /**
     * Altera a senha da caixa de e-mail no DirectAdmin e atualiza o banco.
     */
    public function trocarSenha(Request $request, Estabelecimento $estabelecimento)
    {
        abort_unless(filled($estabelecimento->webmail_email), 422, 'Nenhuma caixa de e-mail configurada.');

        $dados = $request->validate([
            'senha'              => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'senha_confirmation' => ['required', 'string'],
        ]);

        try {
            $this->emailPlataforma->alterarSenha($estabelecimento, $dados['senha']);
        } catch (\Throwable $e) {
            return back()->withErrors(['senha_webmail' => $e->getMessage()]);
        }

        return redirect()->route('estabelecimentos.show', $estabelecimento)
            ->with('status', 'Senha do e-mail da plataforma alterada com sucesso.');
    }
}
