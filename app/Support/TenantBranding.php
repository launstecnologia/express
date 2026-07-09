<?php

namespace App\Support;

use App\Models\MarketplaceBranding;
use Illuminate\Support\Facades\Storage;

class TenantBranding
{
    private static ?MarketplaceBranding $tenant = null;

    private static bool $resolved = false;

    /** host = subdomínio/domínio custom; preview = ?tenant= em local */
    private static ?string $scope = null;

    public static function reset(): void
    {
        self::$tenant = null;
        self::$resolved = false;
        self::$scope = null;
    }

    public static function set(?MarketplaceBranding $branding, string $scope = 'host'): void
    {
        self::$tenant = $branding;
        self::$resolved = true;
        self::$scope = $branding ? $scope : null;
    }

    public static function scope(): ?string
    {
        return self::$scope;
    }

    /**
     * Marca do marketplace só na área do tenant — nunca no painel admin global.
     */
    public static function deveExibirMarcaTenant(): bool
    {
        if (! self::$resolved || self::$tenant === null || ! self::isActive()) {
            return false;
        }

        if (self::$scope === 'host') {
            return true;
        }

        if (self::$scope === 'preview') {
            return ! TenantContext::usuarioEhAdmin();
        }

        return false;
    }

    public static function current(): ?MarketplaceBranding
    {
        return self::$tenant;
    }

    public static function isActive(): bool
    {
        return self::$tenant !== null && self::$tenant->whitelabel_ativo;
    }

    /** Whitelabel configurado e ativo (subdomínio / ?tenant= em local). */
    public static function porSlugAtivo(?string $slug): ?MarketplaceBranding
    {
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        return MarketplaceBranding::query()
            ->where('slug', strtolower(trim($slug)))
            ->where('whitelabel_ativo', true)
            ->first();
    }

    public static function marketplaceId(): ?int
    {
        return self::$tenant?->marketplace_id;
    }

    public static function appName(): string
    {
        if (self::isActive() && self::$tenant->app_name) {
            return self::$tenant->app_name;
        }

        return PlatformSettings::appName();
    }

    public static function metaDescription(): string
    {
        return PlatformSettings::metaDescription();
    }

    public static function metaKeywords(): string
    {
        return PlatformSettings::metaKeywords();
    }

    public static function metaRobots(): string
    {
        return PlatformSettings::metaRobots();
    }

    public static function themeColor(): string
    {
        if (self::isActive() && self::$tenant->primary_color) {
            return self::$tenant->primary_color;
        }

        return PlatformSettings::themeColor();
    }

    public static function primaryColor(): string
    {
        return self::themeColor();
    }

    public static function logoUrl(string $variant = 'default'): ?string
    {
        if (self::isActive()) {
            if ($variant === 'white' && self::$tenant->logo_white_oculta) {
                return null;
            }

            if ($variant === 'default' && self::$tenant->logo_oculta) {
                return null;
            }

            $path = match ($variant) {
                'white' => self::$tenant->logo_white_path ?: (self::$tenant->logo_oculta ? null : self::$tenant->logo_path),
                'favicon' => self::$tenant->favicon_path,
                default => self::$tenant->logo_path,
            };

            if ($path && Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->url($path);
            }
        }

        return PlatformSettings::logoUrl($variant);
    }

    /**
     * @return array<string, mixed>
     */
    public static function paraViews(): array
    {
        return [
            'appName' => self::appName(),
            'metaDescription' => self::metaDescription(),
            'metaKeywords' => self::metaKeywords(),
            'metaRobots' => self::metaRobots(),
            'themeColor' => self::themeColor(),
            'primaryColor' => self::primaryColor(),
            'logoUrl' => self::logoUrl('default'),
            'logoWhiteUrl' => self::logoUrl('white'),
            'faviconUrl' => self::logoUrl('favicon'),
            'empresa' => PlatformSettings::dadosEmpresa(),
            'tenantAtivo' => self::isActive(),
            'tenantSlug' => self::$tenant?->slug,
            'tenantHost' => self::$tenant?->dominioAtivo(),
        ];
    }
}
