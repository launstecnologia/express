<?php

namespace App\Services;

use App\Models\MarketplaceBranding;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class TenantSslProvisionerService
{
    public function podeProvisionar(MarketplaceBranding $branding): bool
    {
        return filled($branding->custom_domain)
            && ! $this->ehSubdominioPlataforma($branding->custom_domain);
    }

    public function provisionar(MarketplaceBranding $branding): array
    {
        if (! $this->podeProvisionar($branding)) {
            throw new RuntimeException('Informe um domínio personalizado válido (não use subdomínio da plataforma).');
        }

        $domain = strtolower(trim((string) $branding->custom_domain));

        $this->validarDns($domain);
        $this->escreverConfigHttp($domain);

        $branding->update([
            'custom_domain_verified_at' => now(),
            'ssl_last_error' => null,
        ]);

        $script = base_path('scripts/provision-tenant-ssl.sh');
        $comandoManual = "bash deploy.sh provision-ssl {$domain}";

        if (! $this->deveProvisionarAutomaticamente($script)) {
            return [
                'modo' => 'manual',
                'dominio' => $domain,
                'mensagem' => 'DNS validado e configuração HTTP gerada. Execute no servidor para emitir o certificado:',
                'comando' => $comandoManual,
            ];
        }

        $this->recarregarNginx();

        $resultado = Process::timeout(300)
            ->path(base_path())
            ->env([
                'COMPOSE_PROJECT_NAME' => 'express',
                'TENANT_CERTBOT_EMAIL' => (string) config('tenant.certbot_email'),
            ])
            ->run(['bash', $script, $domain]);

        if (! $resultado->successful()) {
            $erro = trim($resultado->errorOutput() ?: $resultado->output());

            $branding->update(['ssl_last_error' => $erro]);

            throw new RuntimeException(
                "Falha ao emitir SSL automaticamente. DNS e config HTTP já foram gerados.\n"
                .$this->mensagemDockerIndisponivel()."\n\n{$erro}"
            );
        }

        $branding->refresh();
        app(MarketplaceBrandingService::class)->limparCacheHost($branding);

        return [
            'modo' => 'automatico',
            'dominio' => $domain,
            'mensagem' => "SSL configurado com sucesso para {$domain}.",
            'comando' => null,
        ];
    }

    public function finalizarAposCertbot(string $domain): void
    {
        $domain = strtolower(trim($domain));
        $this->escreverConfigHttps($domain);

        $branding = MarketplaceBranding::query()->where('custom_domain', $domain)->first();

        if ($branding) {
            $branding->update([
                'ssl_provisioned_at' => now(),
                'ssl_last_error' => null,
            ]);
            app(MarketplaceBrandingService::class)->limparCacheHost($branding);
        }
    }

    public function validarDns(string $domain): void
    {
        $ipEsperado = trim((string) config('tenant.server_ip'));

        if ($ipEsperado === '') {
            throw new RuntimeException('Configure TENANT_SERVER_IP no .env para validar o DNS do domínio.');
        }

        $registros = @dns_get_record($domain, DNS_A);

        if (! is_array($registros) || $registros === []) {
            throw new RuntimeException("O domínio {$domain} não possui registro A. Aponte o DNS para {$ipEsperado}.");
        }

        $ips = array_filter(array_map(
            fn (array $r) => $r['ip'] ?? null,
            $registros,
        ));

        if (! in_array($ipEsperado, $ips, true)) {
            $encontrados = implode(', ', $ips) ?: 'nenhum';

            throw new RuntimeException(
                "O domínio {$domain} aponta para {$encontrados}, mas o servidor espera {$ipEsperado}."
            );
        }
    }

    public function escreverConfigHttp(string $domain): void
    {
        File::ensureDirectoryExists($this->diretorioTenants());
        File::put($this->caminhoConfig($domain), $this->templateHttp($domain));
    }

    public function escreverConfigHttps(string $domain): void
    {
        $cert = $this->caminhoCertificado($domain, 'fullchain.pem');
        $key = $this->caminhoCertificado($domain, 'privkey.pem');

        if (! is_file($cert) || ! is_file($key)) {
            throw new RuntimeException("Certificado não encontrado para {$domain}. Rode o Certbot primeiro.");
        }

        File::put($this->caminhoConfig($domain), $this->templateHttps($domain));
    }

    public function removerConfig(string $domain): void
    {
        $arquivo = $this->caminhoConfig($domain);

        if (is_file($arquivo)) {
            File::delete($arquivo);
        }
    }

    private function ehSubdominioPlataforma(string $domain): bool
    {
        $base = strtolower((string) config('tenant.base_domain'));

        return $base !== '' && (str_ends_with($domain, '.'.$base) || $domain === $base);
    }

    private function deveProvisionarAutomaticamente(string $script): bool
    {
        if (! config('tenant.ssl_auto_provision') || ! is_file($script)) {
            return false;
        }

        if (! $this->dockerDisponivel()) {
            throw new RuntimeException($this->mensagemDockerIndisponivel());
        }

        return true;
    }

    private function dockerDisponivel(): bool
    {
        if (! is_readable('/var/run/docker.sock')) {
            return false;
        }

        $resultado = Process::timeout(15)->run(['docker', 'info']);

        return $resultado->successful();
    }

    private function recarregarNginx(): void
    {
        Process::timeout(30)
            ->path(base_path())
            ->env(['COMPOSE_PROJECT_NAME' => 'express'])
            ->run(['docker', 'compose', 'exec', '-T', 'nginx', 'nginx', '-s', 'reload']);
    }

    private function mensagemDockerIndisponivel(): string
    {
        return 'SSL automático indisponível no container app. No servidor: '
            .'1) adicione DOCKER_GID=$(stat -c \'%g\' /var/run/docker.sock) no .env '
            .'2) rode bash deploy.sh update';
    }

    private function diretorioTenants(): string
    {
        return base_path('docker/nginx/tenants');
    }

    private function caminhoConfig(string $domain): string
    {
        return $this->diretorioTenants().'/'.Str::slug($domain, '.').'.conf';
    }

    private function caminhoCertificado(string $domain, string $arquivo): string
    {
        return base_path("docker/nginx/certs/live/{$domain}/{$arquivo}");
    }

    private function templateHttp(string $domain): string
    {
        return <<<NGINX
# Gerado automaticamente — {$domain} (HTTP / validação Let's Encrypt)
server {
    listen 80;
    server_name {$domain};

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 200 'Aguardando emissão do certificado SSL para {$domain}';
        add_header Content-Type text/plain;
    }
}

NGINX;
    }

    private function templateHttps(string $domain): string
    {
        $cert = "/etc/nginx/certs/live/{$domain}/fullchain.pem";
        $key = "/etc/nginx/certs/live/{$domain}/privkey.pem";

        return <<<NGINX
# Gerado automaticamente — {$domain}
server {
    listen 80;
    server_name {$domain};

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://{$domain}\$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name {$domain};

    ssl_certificate     {$cert};
    ssl_certificate_key {$key};

    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    root /var/www/html/public;
    index index.php index.html;

    client_max_body_size 40M;
    resolver 127.0.0.11 valid=10s ipv6=off;

    location ~ ^/estabelecimentos/[^/]+/automacao/screenshots/.+\.png$ {
        try_files \$uri /index.php?\$query_string;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|webp)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        set \$php_upstream app:9000;
        fastcgi_pass   \$php_upstream;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param  HTTPS \$https if_not_empty;
        fastcgi_param  HTTP_X_FORWARDED_PROTO \$scheme;
        include        fastcgi_params;
        fastcgi_read_timeout  300;
    }

    location ~ /\.(env|git|htaccess) {
        deny all;
    }

    location /storage {
        alias /var/www/html/storage/app/public;
        expires 30d;
        add_header Cache-Control "public";
        access_log off;
    }

    location ~ /(bootstrap/cache|vendor)/ {
        deny all;
    }
}

NGINX;
    }
}
