<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceBranding extends Model
{
    protected $fillable = [
        'marketplace_id',
        'slug',
        'app_name',
        'primary_color',
        'logo_path',
        'logo_white_path',
        'logo_oculta',
        'logo_white_oculta',
        'favicon_path',
        'custom_domain',
        'custom_domain_verified_at',
        'ssl_provisioned_at',
        'ssl_last_error',
        'whitelabel_ativo',
        'subdominio_provisionado',
    ];

    protected function casts(): array
    {
        return [
            'custom_domain_verified_at' => 'datetime',
            'ssl_provisioned_at' => 'datetime',
            'whitelabel_ativo' => 'boolean',
            'logo_oculta' => 'boolean',
            'logo_white_oculta' => 'boolean',
            'subdominio_provisionado' => 'boolean',
        ];
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'marketplace_id');
    }

    public function hostSubdominio(): string
    {
        return $this->slug.'.'.config('tenant.base_domain');
    }

    public function dominioAtivo(): ?string
    {
        if ($this->custom_domain && $this->custom_domain_verified_at) {
            return strtolower($this->custom_domain);
        }

        return $this->whitelabel_ativo ? $this->hostSubdominio() : null;
    }
}
