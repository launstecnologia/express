<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DirectAdminService
{
    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('directadmin.url'))
            ->asForm()
            ->withBasicAuth((string) config('directadmin.usuario'), (string) config('directadmin.senha'))
            ->withoutVerifying()
            ->timeout(20);
    }

    public function criarSubdominio(string $subdominio): bool
    {
        $response = $this->client()->post('/CMD_API_SUBDOMAINS', [
            'action' => 'create',
            'domain' => config('directadmin.dominio'),
            'subdomain' => $subdominio,
        ]);

        return $response->successful();
    }

    public function criarEmail(string $subdominio, string $prefixo, string $senha, int $cota = 250): bool
    {
        $response = $this->client()->post('/CMD_API_EMAIL_POP', [
            'action' => 'create',
            'domain' => $this->dominioCompleto($subdominio),
            'user' => $prefixo,
            'passwd' => $senha,
            'passwd2' => $senha,
            'quota' => $cota,
        ]);

        return $response->successful();
    }

    public function alterarSenhaEmail(string $subdominio, string $prefixo, string $novaSenha): bool
    {
        $response = $this->client()->post('/CMD_API_EMAIL_POP', [
            'action' => 'modify',
            'domain' => $this->dominioCompleto($subdominio),
            'user' => $prefixo,
            'passwd' => $novaSenha,
            'passwd2' => $novaSenha,
        ]);

        return $response->successful();
    }

    public function redirecionarEmail(string $subdominio, string $prefixo, string $destino): bool
    {
        $response = $this->client()->post('/CMD_API_EMAIL_FORWARDERS', [
            'action' => 'create',
            'domain' => $this->dominioCompleto($subdominio),
            'user' => $prefixo,
            'email' => $destino,
        ]);

        return $response->successful();
    }

    public function excluirEmail(string $subdominio, string $prefixo): bool
    {
        $response = $this->client()->post('/CMD_API_EMAIL_POP', [
            'action' => 'delete',
            'domain' => $this->dominioCompleto($subdominio),
            'select0' => $prefixo,
        ]);

        return $response->successful();
    }

    // ── Métodos para o domínio principal da plataforma ────────────────

    public function emailExistePlataforma(string $user): bool
    {
        // Em alguns DirectAdmin a listagem exige action=list (CMD_API_POP).
        $response = $this->client()->asForm()->post('/CMD_API_POP', [
            'action' => 'list',
            'domain' => config('directadmin.dominio'),
        ]);

        if (! $response->successful()) {
            // Fallback legado
            $response = $this->client()->get('/CMD_API_EMAIL_POP', [
                'domain' => config('directadmin.dominio'),
            ]);
        }

        if (! $response->successful()) {
            return false;
        }

        parse_str($response->body(), $dados);
        if (($dados['error'] ?? null) === '1') {
            return false;
        }

        // DirectAdmin pode devolver list[]=a&list[]=b
        $lista = $dados['list'] ?? $dados['list[]'] ?? [];
        if (! is_array($lista)) {
            $lista = array_filter(explode(',', (string) $lista));
        }

        return in_array(strtolower($user), array_map('strtolower', array_map('strval', $lista)), true);
    }

    public function criarEmailPlataforma(string $user, string $senha, int $cota = 500): bool
    {
        $response = $this->client()->post('/CMD_API_POP', [
            'action' => 'create',
            'domain' => config('directadmin.dominio'),
            'user' => $user,
            'passwd' => $senha,
            'passwd2' => $senha,
            'quota' => $cota,
        ]);

        if ($response->successful()) {
            parse_str($response->body(), $dados);
            if (($dados['error'] ?? '1') === '0') {
                return true;
            }
        }

        // Fallback legado
        $response = $this->client()->post('/CMD_API_EMAIL_POP', [
            'action' => 'create',
            'domain' => config('directadmin.dominio'),
            'user' => $user,
            'passwd' => $senha,
            'passwd2' => $senha,
            'quota' => $cota,
        ]);

        if (! $response->successful()) {
            return false;
        }

        parse_str($response->body(), $dados);

        return ($dados['error'] ?? '1') === '0';
    }

    public function alterarSenhaEmailPlataforma(string $user, string $novaSenha): bool
    {
        $response = $this->client()->post('/CMD_API_POP', [
            'action' => 'modify',
            'domain' => config('directadmin.dominio'),
            'user' => $user,
            'passwd' => $novaSenha,
            'passwd2' => $novaSenha,
        ]);

        if ($response->successful()) {
            parse_str($response->body(), $dados);
            if (($dados['error'] ?? '1') === '0') {
                return true;
            }
        }

        $response = $this->client()->post('/CMD_API_EMAIL_POP', [
            'action' => 'modify',
            'domain' => config('directadmin.dominio'),
            'user' => $user,
            'passwd' => $novaSenha,
            'passwd2' => $novaSenha,
        ]);

        if (! $response->successful()) {
            return false;
        }

        parse_str($response->body(), $dados);

        return ($dados['error'] ?? '1') === '0';
    }

    public function redirecionarEmailPlataforma(string $user, string $destino): bool
    {
        $dominio       = config('directadmin.dominio');
        $emailLocal    = "{$user}@{$dominio}";

        // Inclui o próprio e-mail local para manter cópia na caixa do Roundcube
        // e ao mesmo tempo encaminhar para o e-mail original do estabelecimento
        $destinosStr = "{$emailLocal},{$destino}";

        $response = $this->client()->post('/CMD_API_EMAIL_FORWARDERS', [
            'action' => 'create',
            'domain' => $dominio,
            'user'   => $user,
            'email'  => $destinosStr,
        ]);

        return $response->successful();
    }

    public function excluirForwarderPlataforma(string $user): bool
    {
        $response = $this->client()->post('/CMD_API_EMAIL_FORWARDERS', [
            'action' => 'delete',
            'domain' => config('directadmin.dominio'),
            'select0' => $user,
        ]);

        return $response->successful();
    }

    public function excluirEmailPlataforma(string $user): bool
    {
        $response = $this->client()->post('/CMD_API_EMAIL_POP', [
            'action' => 'delete',
            'domain' => config('directadmin.dominio'),
            'select0' => $user,
        ]);

        return $response->successful();
    }

    private function dominioCompleto(string $subdominio): string
    {
        return $subdominio.'.'.config('directadmin.dominio');
    }
}
