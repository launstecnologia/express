<?php

namespace App\Services;

use App\Models\MarketplaceBranding;
use App\Models\SubUsuario;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class AcessoOperacionalService
{
    private const TTL_SEGUNDOS = 120;

    public function __construct(
        private readonly MarketplaceBrandingService $brandingService,
    ) {}

    /**
     * Gera link de acesso único no domínio do marketplace (próprio ou subdomínio).
     */
    public function gerarUrlAcesso(Usuario $dono, SubUsuario $subUsuario, Usuario $admin): string
    {
        if ((int) $subUsuario->dono_id !== (int) $dono->id) {
            throw new RuntimeException('Usuário operacional não pertence a este cadastro.');
        }

        if (! $subUsuario->ativo) {
            throw new RuntimeException('Usuário operacional está inativo.');
        }

        $branding = $this->brandingDoDono($dono);

        if (! $branding) {
            throw new RuntimeException('Marketplace sem whitelabel/domínio configurado para acesso.');
        }

        if (! $branding->whitelabel_ativo) {
            throw new RuntimeException('Whitelabel do marketplace está inativo. Ative para liberar o acesso.');
        }

        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'sub_usuario_id' => (int) $subUsuario->id,
            'dono_id' => (int) $dono->id,
            'admin_id' => (int) $admin->id,
        ], now()->addSeconds(self::TTL_SEGUNDOS));

        return rtrim($this->urlBaseTenant($branding), '/').'/acesso-operacional/'.$token;
    }

    /**
     * @return array{sub_usuario_id: int, dono_id: int, admin_id: int}|null
     */
    public function lerToken(string $token): ?array
    {
        $payload = Cache::get($this->cacheKey($token));

        if (! is_array($payload) || empty($payload['sub_usuario_id'])) {
            return null;
        }

        return [
            'sub_usuario_id' => (int) $payload['sub_usuario_id'],
            'dono_id' => (int) ($payload['dono_id'] ?? 0),
            'admin_id' => (int) ($payload['admin_id'] ?? 0),
        ];
    }

    public function invalidarToken(string $token): void
    {
        Cache::forget($this->cacheKey($token));
    }

    public function brandingDoDono(Usuario $dono): ?MarketplaceBranding
    {
        if ($dono->tipo === 'marketplace') {
            $branding = $dono->marketplaceBranding;

            return $branding ?: $this->brandingService->criarPara($dono);
        }

        if ($dono->tipo === 'revenda') {
            $dono->loadMissing('hierarquia.pai.usuario.marketplaceBranding');

            return $dono->hierarquia?->pai?->usuario?->marketplaceBranding;
        }

        return null;
    }

    public function urlBaseTenant(MarketplaceBranding $branding): string
    {
        // Domínio próprio verificado tem prioridade; senão usa o subdomínio da plataforma.
        return $this->brandingService->urlAcessoProducao($branding);
    }

    private function cacheKey(string $token): string
    {
        return 'acesso_operacional:'.$token;
    }
}
