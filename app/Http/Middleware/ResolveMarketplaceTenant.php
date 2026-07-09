<?php

namespace App\Http\Middleware;

use App\Models\MarketplaceBranding;
use App\Support\TenantBranding;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ResolveMarketplaceTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantBranding::reset();
        $this->limparSlugInativoDaSessao($request);

        $brandingHost = $this->resolverPorHost($request->getHost());

        if ($brandingHost) {
            TenantBranding::set($brandingHost, 'host');
            $request->attributes->set('tenant.marketplace_id', $brandingHost->marketplace_id);

            return $next($request);
        }

        if (TenantContext::isPlatformHost($request->getHost())) {
            if (TenantContext::usuarioEhAdmin()) {
                TenantContext::limparSessaoLocal($request);
            }

            $brandingPreview = $this->resolverPreviewLocal($request);

            if ($brandingPreview) {
                TenantBranding::set($brandingPreview, 'preview');
                $request->attributes->set('tenant.marketplace_id', $brandingPreview->marketplace_id);
            }
        }

        return $next($request);
    }

    private function resolverPreviewLocal(Request $request): ?MarketplaceBranding
    {
        if (! app()->environment('local')) {
            return null;
        }

        $slug = TenantContext::slugNaRequisicao($request);

        if (! $slug && $request->hasSession() && ! TenantContext::usuarioEhAdmin()) {
            $slug = $request->session()->get('tenant_slug');
            $slug = is_string($slug) && $slug !== '' ? strtolower(trim($slug)) : null;
        }

        if (! $slug) {
            return null;
        }

        if (TenantContext::usuarioEhAdmin()) {
            if (! $request->routeIs('login')) {
                return null;
            }

            if (! TenantContext::slugNaRequisicao($request)) {
                return null;
            }
        }

        $branding = TenantBranding::porSlugAtivo($slug);

        if ($branding && $request->hasSession() && TenantContext::slugNaRequisicao($request)) {
            if (TenantContext::usuarioEhMarketplace() || ! auth()->check()) {
                $request->session()->put('tenant_slug', $branding->slug);
            }
        }

        return $branding;
    }

    private function limparSlugInativoDaSessao(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $armazenado = $request->session()->get('tenant_slug');

        if (! is_string($armazenado) || $armazenado === '') {
            return;
        }

        if (! TenantBranding::porSlugAtivo($armazenado)) {
            $request->session()->forget('tenant_slug');
        }
    }

    private function resolverPorHost(string $host): ?MarketplaceBranding
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '' || TenantContext::isPlatformHost($host)) {
            return null;
        }

        return Cache::remember("tenant_host:{$host}", 3600, function () use ($host) {
            if (str_ends_with($host, '.localhost')) {
                $slug = substr($host, 0, -strlen('.localhost'));

                if ($slug !== '' && $slug !== 'www') {
                    return MarketplaceBranding::query()
                        ->where('slug', $slug)
                        ->where('whitelabel_ativo', true)
                        ->first();
                }
            }

            $base = strtolower((string) config('tenant.base_domain'));

            if ($base && str_ends_with($host, '.'.$base)) {
                $slug = substr($host, 0, -strlen('.'.$base));

                if ($slug !== '' && $slug !== 'www' && $slug !== 'app') {
                    return MarketplaceBranding::query()
                        ->where('slug', $slug)
                        ->where('whitelabel_ativo', true)
                        ->first();
                }
            }

            return MarketplaceBranding::query()
                ->where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->where('whitelabel_ativo', true)
                ->first();
        });
    }
}
