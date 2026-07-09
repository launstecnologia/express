<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\MarketplaceBrandingService;
use App\Services\TenantSslProvisionerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketplaceBrandingAdminController extends Controller
{
    public function update(Request $request, Usuario $usuario, MarketplaceBrandingService $service)
    {
        abort_unless($request->user()?->tipo === 'admin', 403);
        abort_unless($usuario->tipo === 'marketplace', 404);

        $branding = $usuario->marketplaceBranding ?? $service->criarPara($usuario);

        $dados = $request->validate([
            'app_name' => ['nullable', 'string', 'max:120'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', Rule::unique('marketplace_brandings', 'slug')->ignore($branding->id)],
            'custom_domain' => ['nullable', 'string', 'max:255', Rule::unique('marketplace_brandings', 'custom_domain')->ignore($branding->id)],
            'whitelabel_ativo' => ['boolean'],
            'verificar_dominio' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_white' => ['nullable', 'image', 'max:4096'],
            'favicon' => ['nullable', 'image', 'max:2048'],
            'remover_logo' => ['boolean'],
            'remover_logo_white' => ['boolean'],
            'remover_favicon' => ['boolean'],
        ]);

        $service->aplicarAtualizacao($branding, $usuario, $dados, $request);

        return redirect()
            ->route('usuarios.show', $usuario)
            ->with('status', 'Whitelabel do marketplace atualizado.');
    }

    public function provisionarSsl(Request $request, Usuario $usuario, TenantSslProvisionerService $ssl)
    {
        abort_unless($request->user()?->tipo === 'admin', 403);
        abort_unless($usuario->tipo === 'marketplace', 404);

        set_time_limit(330);

        $branding = $usuario->marketplaceBranding;

        abort_unless($branding && $ssl->podeProvisionar($branding), 422, 'Cadastre um domínio personalizado antes de configurar o SSL.');

        try {
            $resultado = $ssl->provisionar($branding);
        } catch (\Throwable $e) {
            return redirect()
                ->route('usuarios.show', $usuario)
                ->withErrors(['ssl' => $e->getMessage()]);
        }

        if ($resultado['modo'] === 'manual' && filled($resultado['comando'])) {
            return redirect()
                ->route('usuarios.show', $usuario)
                ->with('status', $resultado['mensagem'])
                ->with('ssl_comando', $resultado['comando']);
        }

        return redirect()
            ->route('usuarios.show', $usuario)
            ->with('status', $resultado['mensagem']);
    }
}
