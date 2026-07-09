<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    private static ?bool $tabelaExiste = null;

    private static ?PlatformSetting $instancia = null;

    /** @var array<string, mixed>|null */
    private static ?array $paraViewsCache = null;

    private static function tabelaExiste(): bool
    {
        if (self::$tabelaExiste !== null) {
            return self::$tabelaExiste;
        }

        self::$tabelaExiste = Schema::hasTable('platform_settings');

        return self::$tabelaExiste;
    }

    public static function get(): PlatformSetting
    {
        if (self::$instancia !== null) {
            return self::$instancia;
        }

        if (! self::tabelaExiste()) {
            self::$instancia = self::fallback();

            return self::$instancia;
        }

        self::$instancia = Cache::remember(self::CACHE_KEY, 3600, function () {
            return PlatformSetting::query()->firstOrCreate([], self::defaultAttributes());
        });

        return self::$instancia;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultAttributes(): array
    {
        return [
            'app_name' => config('app.name', 'Express Payments'),
            'meta_description' => config('app.description'),
            'meta_keywords' => config('app.keywords'),
            'meta_robots' => config('app.robots', 'noindex, nofollow'),
            'theme_color' => '#2563eb',
        ];
    }

    private static function fallback(): PlatformSetting
    {
        return new PlatformSetting(self::defaultAttributes());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$instancia = null;
        self::$paraViewsCache = null;
    }

    public static function appName(): string
    {
        return self::get()->app_name ?: config('app.name', 'Express Payments');
    }

    public static function metaDescription(): string
    {
        return self::get()->meta_description ?: (string) config('app.description', '');
    }

    public static function metaKeywords(): string
    {
        return self::get()->meta_keywords ?: (string) config('app.keywords', '');
    }

    public static function metaRobots(): string
    {
        return self::get()->meta_robots ?: (string) config('app.robots', 'noindex, nofollow');
    }

    public static function themeColor(): string
    {
        return self::get()->theme_color ?: '#2563eb';
    }

    public static function kycAtivo(): bool
    {
        $setting = self::get();

        return ($setting->kyc_ativo ?? true) === true;
    }

    public static function openaiApiKey(): ?string
    {
        $setting = self::get();
        $key = $setting->openai_api_key ?? null;

        if ($key) {
            return $key;
        }

        $env = config('services.openai.key');

        return filled($env) ? (string) $env : null;
    }

    public static function openaiModelo(): string
    {
        return self::get()->openai_modelo
            ?: config('services.openai.model', 'gpt-4o');
    }

    public static function brasilApiUrl(): string
    {
        return rtrim(
            self::get()->brasilapi_url ?: config('services.brasilapi.url', 'https://brasilapi.com.br/api'),
            '/'
        );
    }

    public static function openaiConfigurado(): bool
    {
        return filled(self::openaiApiKey());
    }

    public static function ppidApiUrl(): string
    {
        $setting = self::get();
        $url = $setting->ppid_api_url ?? config('services.ppid.url');

        return rtrim((string) ($url ?: 'https://api.ppid.com.br'), '/');
    }

    public static function ppidEmail(): ?string
    {
        $setting = self::get();
        $email = $setting->ppid_email ?? config('services.ppid.email');

        return filled($email) ? (string) $email : null;
    }

    public static function ppidSenha(): ?string
    {
        $setting = self::get();
        $senha = $setting->ppid_senha ?? config('services.ppid.senha');

        return filled($senha) ? (string) $senha : null;
    }

    public static function ppidLimiteMensal(): int
    {
        $setting = self::get();
        $limite = $setting->ppid_limite_mensal ?? config('services.ppid.limite_mensal', 490);

        return max(1, (int) $limite);
    }

    public static function ppidConfigurado(): bool
    {
        return filled(self::ppidApiUrl())
            && filled(self::ppidEmail())
            && filled(self::ppidSenha());
    }

    public static function pagbankAmbiente(): string
    {
        $ambiente = self::get()->pagbank_ambiente;

        if (in_array($ambiente, ['sandbox', 'producao'], true)) {
            return $ambiente;
        }

        $env = config('pagbank.ambiente', 'producao');

        return in_array($env, ['sandbox', 'producao'], true) ? $env : 'producao';
    }

    public static function pagbankApiUrl(): string
    {
        $override = config('pagbank.api_url');

        if (filled($override)) {
            return rtrim((string) $override, '/');
        }

        return match (self::pagbankAmbiente()) {
            'sandbox' => 'https://sandbox.api.pagseguro.com',
            default => 'https://api.pagseguro.com',
        };
    }

    public static function pagbankToken(): ?string
    {
        $setting = self::get();
        $token = $setting->pagbank_token ?? null;

        if ($token) {
            return $token;
        }

        $env = config('pagbank.token');

        return filled($env) ? (string) $env : null;
    }

    public static function pagbankClientId(): ?string
    {
        $setting = self::get();
        $id = $setting->pagbank_client_id ?? null;

        if ($id) {
            return $id;
        }

        $env = config('pagbank.client_id');

        return filled($env) ? (string) $env : null;
    }

    public static function pagbankClientSecret(): ?string
    {
        $setting = self::get();
        $secret = $setting->pagbank_client_secret ?? null;

        if ($secret) {
            return $secret;
        }

        $env = config('pagbank.client_secret');

        return filled($env) ? (string) $env : null;
    }

    public static function pagbankConfigurado(): bool
    {
        return filled(self::pagbankClientId())
            && filled(self::pagbankClientSecret())
            && filled(self::pagbankToken());
    }

    public static function pagbankAmbienteRotulo(): string
    {
        return self::pagbankAmbiente() === 'sandbox' ? 'Sandbox' : 'Produção';
    }

    public static function ediUser(): ?string
    {
        $setting = self::get();
        $ambiente = self::pagbankAmbiente();

        $col = $ambiente === 'sandbox' ? 'pagbank_edi_user_sandbox' : 'pagbank_edi_user_producao';
        $db = $setting->{$col} ?? null;

        if (filled($db)) {
            return (string) $db;
        }

        $env = config('pagseguro.edi_user');

        return filled($env) ? (string) $env : null;
    }

    public static function ediToken(): ?string
    {
        $setting = self::get();
        $ambiente = self::pagbankAmbiente();

        $col = $ambiente === 'sandbox' ? 'pagbank_edi_token_sandbox' : 'pagbank_edi_token_producao';
        $db = $setting->{$col} ?? null;

        if (filled($db)) {
            return $db;
        }

        // fallback .env / config
        $env = config('pagseguro.edi_token');
        return filled($env) ? (string) $env : null;
    }

    public static function ediUrl(): string
    {
        $override = config('pagseguro.edi_url');
        return filled($override) ? rtrim((string) $override, '/') : 'https://edi.api.pagbank.com.br';
    }

    public static function ediConfigurado(): bool
    {
        return filled(self::ediUser()) && filled(self::ediToken());
    }

    public static function automacaoApiUrl(): ?string
    {
        $env = config('automacao.api_url');

        return filled($env) ? rtrim((string) $env, '/') : null;
    }

    public static function automacaoApiKey(): ?string
    {
        $env = config('automacao.api_key');

        return filled($env) ? (string) $env : null;
    }

    public static function automacaoConfigurado(): bool
    {
        return filled(self::automacaoApiUrl()) && filled(self::automacaoApiKey());
    }

    public static function automacaoFvUsuario(): ?string
    {
        $env = config('automacao.fv_usuario');

        return filled($env) ? (string) $env : null;
    }

    public static function automacaoFvSenha(): ?string
    {
        $env = config('automacao.fv_senha');

        return filled($env) ? (string) $env : null;
    }

    public static function automacaoWebmailUrl(): ?string
    {
        $candidatos = [
            config('automacao.webmail_url'),
            config('directadmin.webmail_url'),
        ];

        foreach ($candidatos as $url) {
            if (self::urlWebmailUtilizavel($url)) {
                return rtrim((string) $url, '/');
            }
        }

        $dominio = config('directadmin.dominio');
        if (filled($dominio) && ! str_contains(strtolower((string) $dominio), 'seudominio')) {
            return 'http://mail.'.$dominio.'/roundcube';
        }

        return null;
    }

    private static function urlWebmailUtilizavel(mixed $url): bool
    {
        if (! filled($url)) {
            return false;
        }

        $normalizada = strtolower(trim((string) $url));

        return ! str_contains($normalizada, 'seudominio.com.br')
            && ! str_contains($normalizada, 'seudominio');
    }

    public static function logoUrl(string $variant = 'default'): ?string
    {
        return self::logoUrlDe(self::get(), $variant);
    }

    private static function logoUrlDe(PlatformSetting $setting, string $variant): ?string
    {
        $path = match ($variant) {
            'white' => $setting->logo_white_path ?: $setting->logo_path,
            'favicon' => $setting->favicon_path,
            default => $setting->logo_path,
        };

        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return match ($variant) {
            'white', 'default' => asset('images/logo-express-sidebar.png'),
            'favicon' => asset('favicon-32.png'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function dadosEmpresa(): array
    {
        return self::dadosEmpresaDe(self::get());
    }

    /**
     * @return array<string, mixed>
     */
    private static function dadosEmpresaDe(PlatformSetting $s): array
    {
        $appName = $s->app_name ?: config('app.name', 'Express Payments');

        $enderecoLinhas = array_filter([
            trim(collect([$s->endereco, $s->numero])->filter()->join(', ')),
            $s->complemento,
            trim(collect([$s->bairro, $s->cidade, $s->uf])->filter()->join(' · ')),
            $s->cep ? 'CEP '.$s->cep : null,
        ]);

        return [
            'app_name' => $appName,
            'razao_social' => $s->razao_social,
            'nome_fantasia' => $s->nome_fantasia,
            'nome_exibicao' => $s->nome_fantasia ?: $s->razao_social ?: $appName,
            'cnpj' => $s->cnpj,
            'inscricao_estadual' => $s->inscricao_estadual,
            'email' => $s->email,
            'telefone' => $s->telefone,
            'celular' => $s->celular,
            'site_url' => $s->site_url,
            'cep' => $s->cep,
            'endereco' => $s->endereco,
            'numero' => $s->numero,
            'complemento' => $s->complemento,
            'bairro' => $s->bairro,
            'cidade' => $s->cidade,
            'uf' => $s->uf,
            'endereco_completo' => implode("\n", $enderecoLinhas),
            'endereco_uma_linha' => implode(' — ', $enderecoLinhas),
            'responsavel_nome' => $s->responsavel_nome,
            'responsavel_cpf' => $s->responsavel_cpf,
            'observacoes_relatorio' => $s->observacoes_relatorio,
            'logo_url' => self::logoUrlDe($s, 'default'),
            'logo_white_url' => self::logoUrlDe($s, 'white'),
            'favicon_url' => self::logoUrlDe($s, 'favicon'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paraViews(): array
    {
        if (self::$paraViewsCache !== null) {
            return self::$paraViewsCache;
        }

        $s = self::get();
        $themeColor = $s->theme_color ?: '#2563eb';

        self::$paraViewsCache = [
            'appName' => $s->app_name ?: config('app.name', 'Express Payments'),
            'metaDescription' => $s->meta_description ?: (string) config('app.description', ''),
            'metaKeywords' => $s->meta_keywords ?: (string) config('app.keywords', ''),
            'metaRobots' => $s->meta_robots ?: (string) config('app.robots', 'noindex, nofollow'),
            'themeColor' => $themeColor,
            'primaryColor' => $themeColor,
            'logoUrl' => self::logoUrlDe($s, 'default'),
            'logoWhiteUrl' => self::logoUrlDe($s, 'white'),
            'faviconUrl' => self::logoUrlDe($s, 'favicon'),
            'empresa' => self::dadosEmpresaDe($s),
        ];

        return self::$paraViewsCache;
    }
}
