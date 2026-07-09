<?php

namespace App\Support;

use App\Models\Estabelecimento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

class UsuarioComercial
{
    public static function principal(): ?Usuario
    {
        $user = auth()->user();

        if ($user instanceof SubUsuario) {
            return $user->dono;
        }

        return $user instanceof Usuario ? $user : null;
    }

    public static function tipo(): ?string
    {
        return self::principal()?->tipo;
    }

    public static function ehAdmin(): bool
    {
        return self::tipo() === 'admin';
    }

    public static function ehMaster(): bool
    {
        return self::tipo() === 'master';
    }

    public static function ehMarketplace(): bool
    {
        return self::tipo() === 'marketplace';
    }

    public static function ehRevenda(): bool
    {
        return self::tipo() === 'revenda';
    }

    public static function ehMarketplaceOuRevenda(): bool
    {
        return in_array(self::tipo(), ['marketplace', 'revenda'], true);
    }

    public static function podeGerirPlanos(): bool
    {
        return self::ehAdmin();
    }

    /**
     * Admin ou master da rede: libera quais planos o marketplace pode usar.
     * Revenda herda os planos do marketplace pai (não há liberação direta).
     */
    public static function podeLiberarPlanosMarketplace(?Usuario $marketplace = null): bool
    {
        if (self::ehAdmin()) {
            return true;
        }

        if (! self::ehMaster()) {
            return false;
        }

        if (! $marketplace) {
            return true;
        }

        return $marketplace->tipo === 'marketplace' && self::podeGerenciar($marketplace);
    }

    public static function podeCadastrarEstabelecimento(): bool
    {
        return in_array(self::tipo(), ['admin', 'marketplace', 'revenda'], true);
    }

    public static function deveEscolherModoCadastro(): bool
    {
        return in_array(self::tipo(), ['marketplace', 'revenda'], true);
    }

    public static function podeGerenciarAutomacaoEstabelecimento(Estabelecimento $estabelecimento): bool
    {
        $usuario = self::principal();

        if (! $usuario) {
            return false;
        }

        if ($usuario->tipo === 'admin') {
            return true;
        }

        if ($usuario->tipo === 'marketplace') {
            return (int) $estabelecimento->marketplace_id === (int) $usuario->id;
        }

        return false;
    }

    public static function podeDefinirRetencaoPai(string $tipoFilho): bool
    {
        if ($tipoFilho === 'marketplace') {
            return self::ehAdmin() || self::ehMaster();
        }

        if ($tipoFilho === 'revenda') {
            return self::ehAdmin() || self::ehMaster() || self::ehMarketplace();
        }

        return false;
    }

    public static function marketplacesDo(Usuario $master): Builder
    {
        $master->loadMissing('hierarquia');
        $noId = $master->hierarquia?->id;

        return Usuario::query()
            ->where('tipo', 'marketplace')
            ->when(
                $noId,
                fn (Builder $q) => $q->whereHas('hierarquia', fn (Builder $h) => $h->where('pai_id', $noId)),
                fn (Builder $q) => $q->whereRaw('1 = 0')
            );
    }

    public static function podeGerenciar(Usuario $alvo): bool
    {
        $actor = self::principal();

        if (! $actor) {
            return false;
        }

        if ($actor->tipo === 'admin') {
            return true;
        }

        if ($actor->tipo === 'marketplace') {
            if ((int) $alvo->id === (int) $actor->id) {
                return true;
            }

            if ($alvo->tipo === 'revenda') {
                return self::revendasDo($actor)->whereKey($alvo->id)->exists();
            }

            return false;
        }

        if ($actor->tipo === 'master') {
            if ((int) $alvo->id === (int) $actor->id) {
                return true;
            }

            return self::pertenceAoMaster($alvo, $actor);
        }

        return false;
    }

    public static function revendasDo(Usuario $marketplace): Builder
    {
        $marketplace->loadMissing('hierarquia');
        $noId = $marketplace->hierarquia?->id;

        return Usuario::query()
            ->where('tipo', 'revenda')
            ->when(
                $noId,
                fn (Builder $q) => $q->whereHas('hierarquia', fn (Builder $h) => $h->where('pai_id', $noId)),
                fn (Builder $q) => $q->whereRaw('1 = 0')
            );
    }

    public static function tipoListaPermitido(?string $tipo): bool
    {
        if (self::ehAdmin()) {
            return $tipo === null || in_array($tipo, ['master', 'marketplace', 'revenda'], true);
        }

        if (self::ehMarketplace()) {
            return $tipo === 'revenda';
        }

        if (self::tipo() === 'master') {
            return $tipo === null || in_array($tipo, ['marketplace', 'revenda'], true);
        }

        return false;
    }

    private static function pertenceAoMaster(Usuario $alvo, Usuario $master): bool
    {
        $alvo->loadMissing('hierarquia.pai.usuario');

        $atual = $alvo->hierarquia?->pai?->usuario;

        for ($i = 0; $i < 5 && $atual; $i++) {
            if ((int) $atual->id === (int) $master->id) {
                return true;
            }

            $atual->loadMissing('hierarquia.pai.usuario');
            $atual = $atual->hierarquia?->pai?->usuario;
        }

        return false;
    }
}
