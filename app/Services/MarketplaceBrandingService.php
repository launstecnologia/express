<?php

namespace App\Services;

use App\Jobs\ProvisionarSubdominioMarketplaceJob;
use App\Models\MarketplaceBranding;
use App\Models\Usuario;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketplaceBrandingService
{
    public function criarPara(Usuario $marketplace, ?string $slugSugerido = null): MarketplaceBranding
    {
        $slug = $this->gerarSlugUnico($slugSugerido ?: $marketplace->nome_fantasia ?: $marketplace->razao_social ?: $marketplace->email);

        $branding = MarketplaceBranding::query()->firstOrCreate(
            ['marketplace_id' => $marketplace->id],
            [
                'slug' => $slug,
                'app_name' => $marketplace->nome_fantasia ?: $marketplace->razao_social,
                'primary_color' => '#2563eb',
                'whitelabel_ativo' => true,
            ]
        );

        if (config('tenant.provision_subdomain') && ! $branding->subdominio_provisionado) {
            ProvisionarSubdominioMarketplaceJob::dispatch($branding->id);
        }

        $this->limparCacheHost($branding);

        return $branding;
    }

    public function gerarSlugUnico(string $base, ?int $exceptBrandingId = null): string
    {
        $slug = Str::slug($base);
        $slug = $slug !== '' ? $slug : 'marketplace';

        if (strlen($slug) > 63) {
            $slug = substr($slug, 0, 63);
        }

        $original = $slug;
        $i = 1;

        while (
            MarketplaceBranding::query()
                ->where('slug', $slug)
                ->when($exceptBrandingId, fn ($q) => $q->where('id', '!=', $exceptBrandingId))
                ->exists()
        ) {
            $suffix = '-'.$i;
            $slug = substr($original, 0, 63 - strlen($suffix)).$suffix;
            $i++;
        }

        return $slug;
    }

    /**
     * @return array{logoUrl: ?string, logoWhiteUrl: ?string, faviconUrl: ?string}
     */
    public function urlsPreview(?MarketplaceBranding $branding): array
    {
        $logoOculta = (bool) ($branding?->logo_oculta);
        $logoWhiteOculta = (bool) ($branding?->logo_white_oculta);

        return [
            'logoUrl' => $logoOculta
                ? null
                : $this->urlArquivo($branding?->logo_path, 'default'),
            'logoWhiteUrl' => $logoWhiteOculta
                ? null
                : $this->urlArquivo(
                    $branding?->logo_white_path ?: ($logoOculta ? null : $branding?->logo_path),
                    'white',
                ),
            'faviconUrl' => $this->urlArquivo($branding?->favicon_path, 'favicon'),
        ];
    }

    public function urlArquivo(?string $path, string $variantePadrao = 'default'): ?string
    {
        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return match ($variantePadrao) {
            'white' => PlatformSettings::logoUrl('white'),
            'favicon' => PlatformSettings::logoUrl('favicon'),
            default => PlatformSettings::logoUrl('default'),
        };
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public function aplicarAtualizacao(MarketplaceBranding $branding, Usuario $marketplace, array $dados, Request $request): MarketplaceBranding
    {
        $dados['whitelabel_ativo'] = $request->boolean('whitelabel_ativo');
        $dados['custom_domain'] = filled($dados['custom_domain'] ?? null)
            ? strtolower((string) $dados['custom_domain'])
            : null;

        if (($dados['custom_domain'] ?? null) !== $branding->custom_domain) {
            $dados['custom_domain_verified_at'] = null;
        }

        if ($request->boolean('verificar_dominio') && ($dados['custom_domain'] ?? $branding->custom_domain)) {
            $dados['custom_domain_verified_at'] = now();
        }

        foreach ([
            ['logo', 'logo_path', 'logo_oculta', 'logo_acao'],
            ['logo_white', 'logo_white_path', 'logo_white_oculta', 'logo_white_acao'],
            ['favicon', 'favicon_path', null, 'remover_favicon'],
        ] as [$input, $column, $ocultaColumn, $acaoKey]) {
            if ($ocultaColumn) {
                $updates = $this->aplicarAcaoLogo(
                    $request,
                    $branding,
                    $marketplace,
                    $input,
                    $column,
                    $ocultaColumn,
                    $acaoKey,
                );
                $dados = array_merge($dados, $updates);

                continue;
            }

            if ($request->boolean($acaoKey) && $branding->{$column}) {
                Storage::disk('public')->delete($branding->{$column});
                $dados[$column] = null;
            }

            if ($request->hasFile($input)) {
                if ($branding->{$column}) {
                    Storage::disk('public')->delete($branding->{$column});
                }
                $dados[$column] = $request->file($input)->store("platform/marketplaces/{$marketplace->id}", 'public');
            }
        }

        unset(
            $dados['logo'],
            $dados['logo_white'],
            $dados['favicon'],
            $dados['logo_acao'],
            $dados['logo_white_acao'],
            $dados['remover_favicon'],
            $dados['verificar_dominio'],
        );

        $hostsAntigos = array_filter([
            $branding->hostSubdominio(),
            $branding->custom_domain ? strtolower($branding->custom_domain) : null,
        ]);

        $branding->update($dados);

        foreach ($hostsAntigos as $host) {
            Cache::forget("tenant_host:{$host}");
        }

        $branding = $branding->fresh();
        $this->limparCacheHost($branding);

        return $branding;
    }

    public function limparCacheHost(MarketplaceBranding $branding): void
    {
        Cache::forget("tenant_host:{$branding->hostSubdominio()}");

        if ($branding->custom_domain) {
            Cache::forget('tenant_host:'.strtolower($branding->custom_domain));
        }
    }

    public function ehAmbienteLocal(): bool
    {
        return app()->environment('local');
    }

    public function urlAcessoProducao(MarketplaceBranding $branding): string
    {
        if ($branding->custom_domain && $branding->custom_domain_verified_at) {
            return 'https://'.$branding->custom_domain;
        }

        return 'https://'.$branding->hostSubdominio();
    }

    public function urlAcessoLocal(MarketplaceBranding $branding): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $param = (string) config('tenant.local_query', 'tenant');

        return $base.'?'.$param.'='.$branding->slug;
    }

    /**
     * @return array{ehLocal: bool, atual: string, local: string, producao: string}
     */
    public function urlsAcesso(MarketplaceBranding $branding): array
    {
        $producao = $this->urlAcessoProducao($branding);
        $local = $this->urlAcessoLocal($branding);
        $ehLocal = $this->ehAmbienteLocal();

        return [
            'ehLocal' => $ehLocal,
            'atual' => $ehLocal ? $local : $producao,
            'local' => $local,
            'producao' => $producao,
        ];
    }

    public function urlAcesso(MarketplaceBranding $branding): string
    {
        return $this->urlsAcesso($branding)['atual'];
    }

    /**
     * @return array<string, mixed>
     */
    private function aplicarAcaoLogo(
        Request $request,
        MarketplaceBranding $branding,
        Usuario $marketplace,
        string $input,
        string $columnPath,
        string $columnOculta,
        string $acaoInput,
    ): array {
        $updates = [];
        $acao = $request->input($acaoInput);

        if (in_array($acao, ['padrao', 'ocultar'], true)) {
            if ($branding->{$columnPath}) {
                Storage::disk('public')->delete($branding->{$columnPath});
            }

            $updates[$columnPath] = null;
            $updates[$columnOculta] = $acao === 'ocultar';
        }

        if ($request->hasFile($input)) {
            if ($branding->{$columnPath}) {
                Storage::disk('public')->delete($branding->{$columnPath});
            }

            $updates[$columnPath] = $request->file($input)->store("platform/marketplaces/{$marketplace->id}", 'public');
            $updates[$columnOculta] = false;
        }

        return $updates;
    }
}
