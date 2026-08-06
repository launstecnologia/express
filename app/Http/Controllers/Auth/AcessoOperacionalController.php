<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SubUsuario;
use App\Services\AcessoOperacionalService;
use App\Services\MarketplaceTenantAccessService;
use App\Support\TenantBranding;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AcessoOperacionalController extends Controller
{
    public function __invoke(Request $request, string $token, AcessoOperacionalService $acesso)
    {
        $payload = $acesso->lerToken($token);

        if (! $payload) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Link de acesso expirado ou inválido. Gere um novo pelo painel admin.']);
        }

        $subUsuario = SubUsuario::with('dono')->find($payload['sub_usuario_id']);

        if (! $subUsuario || ! $subUsuario->ativo || ! $subUsuario->dono?->ativo) {
            $acesso->invalidarToken($token);

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Usuário operacional indisponível.']);
        }

        $branding = $acesso->brandingDoDono($subUsuario->dono);

        if ($branding && ! TenantContext::isPlatformHost($request->getHost())) {
            $atual = TenantBranding::current();

            if (! $atual || (int) $atual->marketplace_id !== (int) $branding->marketplace_id) {
                // Host errado: manda para o domínio correto mantendo o mesmo token.
                return redirect()->away(rtrim($acesso->urlBaseTenant($branding), '/').'/acesso-operacional/'.$token);
            }
        }

        if ($branding && TenantContext::isPlatformHost($request->getHost())) {
            // Acesso precisa acontecer no domínio do marketplace (cookie/sessão do tenant).
            return redirect()->away(rtrim($acesso->urlBaseTenant($branding), '/').'/acesso-operacional/'.$token);
        }

        $acesso->invalidarToken($token);

        if (auth()->check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Auth::login($subUsuario);
        $request->session()->regenerate();
        $request->session()->put('acesso_via_admin_id', $payload['admin_id']);

        if ($branding) {
            TenantBranding::set($branding, 'host');
            $request->session()->put('tenant_slug', $branding->slug);
        }

        app(MarketplaceTenantAccessService::class)->gravarEscopoAutenticacao($request);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Acesso operacional liberado como '.$subUsuario->nome.'.');
    }
}
