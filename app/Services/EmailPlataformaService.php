<?php

namespace App\Services;

use App\Models\Estabelecimento;
use Illuminate\Support\Str;

class EmailPlataformaService
{
    public function __construct(
        private readonly DirectAdminService $da,
    ) {}

    /**
     * Deriva o username do e-mail informado.
     * Ex: lucasmoraes.lrm@gmail.com → lucasmoraes.lrm
     */
    public function derivarUsername(string $email): string
    {
        $prefixo = Str::before($email, '@');

        return strtolower(preg_replace('/[^a-z0-9._-]/i', '', $prefixo));
    }

    public function usernameOcupado(string $username): bool
    {
        return $this->da->emailExistePlataforma($username);
    }

    /**
     * Cria a conta de e-mail da plataforma e o redirecionamento.
     * Salva webmail_email e webmail_senha (encriptada) no banco.
     *
     * @throws \RuntimeException se o username estiver inválido, já existir ou a criação falhar.
     */
    public function provisionar(Estabelecimento $estabelecimento, string $username): void
    {
        $username = strtolower(trim($username));

        if (blank($username) || ! preg_match('/^[a-z0-9._-]+$/', $username)) {
            throw new \RuntimeException('Nome de usuário inválido. Use apenas letras, números, ponto, hífen ou sublinhado.');
        }

        if ($this->usernameOcupado($username)) {
            throw new \RuntimeException("O nome \"{$username}\" já está em uso no servidor de e-mail.");
        }

        $senha = Str::password(16, true, true, false);
        $dominio = config('directadmin.dominio');
        $emailPlataforma = "{$username}@{$dominio}";

        $criou = $this->da->criarEmailPlataforma($username, $senha);

        if (! $criou) {
            throw new \RuntimeException("Não foi possível criar a conta {$emailPlataforma} no servidor.");
        }

        if (filled($estabelecimento->email)) {
            // Caixa POP mantém cópia no Roundcube; o forwarder aponta só para o e-mail externo.
            try {
                $this->da->configurarForwarderPlataforma($username, $estabelecimento->email);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Conta {$emailPlataforma} criada, mas o redirecionamento falhou: {$e->getMessage()}"
                );
            }
        }

        $estabelecimento->update([
            'webmail_email' => $emailPlataforma,
            'webmail_senha' => $senha,
        ]);
    }

    /**
     * Garante o forwarder da caixa da plataforma para o e-mail do estabelecimento.
     */
    public function ativarForwarder(Estabelecimento $estabelecimento): void
    {
        if (blank($estabelecimento->webmail_email) || blank($estabelecimento->email)) {
            return;
        }

        $username = strtolower(Str::before($estabelecimento->webmail_email, '@'));
        $this->da->configurarForwarderPlataforma($username, $estabelecimento->email);
    }

    /**
     * Substitui a conta de e-mail da plataforma por uma nova.
     * Provisiona o novo username e, em seguida, remove a conta/forwarder antigos
     * no servidor (best-effort). A ordem garante que, se a criação do novo
     * e-mail falhar, a conta atual permaneça intacta.
     *
     * @throws \RuntimeException se o username for inválido, já existir ou a criação falhar.
     */
    public function recriar(Estabelecimento $estabelecimento, string $novoUsername): void
    {
        $novoUsername = strtolower(trim($novoUsername));

        $usernameAntigo = filled($estabelecimento->webmail_email)
            ? strtolower(Str::before($estabelecimento->webmail_email, '@'))
            : null;

        if ($usernameAntigo !== null && $novoUsername === $usernameAntigo) {
            throw new \RuntimeException('O novo nome de e-mail deve ser diferente do atual.');
        }

        // Cria a nova conta e atualiza o banco. Se falhar, lança exceção e nada é removido.
        $this->provisionar($estabelecimento, $novoUsername);

        // Remove a conta e o forwarder antigos (ignora erros para não travar a troca).
        if ($usernameAntigo !== null) {
            try {
                $this->da->excluirForwarderPlataforma($usernameAntigo);
            } catch (\Throwable) {
            }

            try {
                $this->da->excluirEmailPlataforma($usernameAntigo);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Tenta provisionar automaticamente ao cadastrar.
     * Retorna null em caso de sucesso, ou o username sugerido caso esteja ocupado
     * (indicando que o admin precisa escolher manualmente).
     */
    public function provisionarAutomatico(Estabelecimento $estabelecimento): ?string
    {
        if (blank($estabelecimento->email)) {
            return null;
        }

        $username = $this->derivarUsername($estabelecimento->email);

        if (blank($username)) {
            return null;
        }

        if ($this->usernameOcupado($username)) {
            return $username;
        }

        try {
            $this->provisionar($estabelecimento, $username);
        } catch (\Throwable) {
            return $username;
        }

        return null;
    }

    /**
     * Altera a senha da caixa no DirectAdmin e atualiza o banco.
     */
    public function alterarSenha(Estabelecimento $estabelecimento, string $novaSenha): void
    {
        $username = Str::before($estabelecimento->webmail_email, '@');

        $ok = $this->da->alterarSenhaEmailPlataforma($username, $novaSenha);

        if (! $ok) {
            throw new \RuntimeException('Não foi possível alterar a senha no servidor.');
        }

        $estabelecimento->update(['webmail_senha' => $novaSenha]);
    }
}
