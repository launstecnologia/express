<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Services\MarketplaceBrandingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketplaceBrandingController extends Controller
{
    public function edit(Request $request, MarketplaceBrandingService $service)
    {
        $marketplace = $this->marketplaceAutenticado($request);
        $branding = $marketplace->marketplaceBranding
            ?? $service->criarPara($marketplace);

        $previews = $service->urlsPreview($branding);

        return view('marketplace.branding.edit', [
            'branding' => $branding,
            'marketplace' => $marketplace,
            'urlsAcesso' => $service->urlsAcesso($branding),
            ...$previews,
        ]);
    }

    public function update(Request $request, MarketplaceBrandingService $service)
    {
        $marketplace = $this->marketplaceAutenticado($request);
        $branding = $marketplace->marketplaceBranding
            ?? $service->criarPara($marketplace);

        $dados = $request->validate([
            'app_name' => ['nullable', 'string', 'max:120'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', Rule::unique('marketplace_brandings', 'slug')->ignore($branding->id)],
            'custom_domain' => ['nullable', 'string', 'max:255', Rule::unique('marketplace_brandings', 'custom_domain')->ignore($branding->id)],
            'whitelabel_ativo' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_white' => ['nullable', 'image', 'max:4096'],
            'favicon' => ['nullable', 'image', 'max:2048'],
            'logo_acao' => ['nullable', 'in:padrao,ocultar'],
            'logo_white_acao' => ['nullable', 'in:padrao,ocultar'],
            'remover_favicon' => ['boolean'],
        ]);

        $service->aplicarAtualizacao($branding, $marketplace, $dados, $request);

        return redirect()
            ->route('marketplace.branding.edit')
            ->with('status', 'Whitelabel atualizado com sucesso.');
    }

    public function verificarDominio(Request $request, MarketplaceBrandingService $service)
    {
        $marketplace = $this->marketplaceAutenticado($request);
        $branding = $marketplace->marketplaceBranding;

        abort_unless($branding && $branding->custom_domain, 422);

        $branding->update(['custom_domain_verified_at' => now()]);
        $service->limparCacheHost($branding);

        return back()->with('status', 'Domínio personalizado marcado como verificado. Configure o DNS apontando para este servidor.');
    }

    private function marketplaceAutenticado(Request $request): Usuario
    {
        $user = $request->user();

        if ($user instanceof SubUsuario) {
            abort_unless($user->dono?->tipo === 'marketplace', 403);

            return $user->dono;
        }

        abort_unless($user instanceof Usuario && $user->tipo === 'marketplace', 403);

        return $user;
    }
}
