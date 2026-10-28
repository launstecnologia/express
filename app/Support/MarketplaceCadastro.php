<?php

namespace App\Support;

use App\Models\Estabelecimento;
use App\Models\MarketplaceBranding;
use Illuminate\Support\Facades\Schema;

class MarketplaceCadastro
{
    public static function exigeAssinatura(Estabelecimento $estabelecimento): bool
    {
        return self::exigeAssinaturaParaMarketplace($estabelecimento->marketplace_id ? (int) $estabelecimento->marketplace_id : null);
    }

    public static function exigeAssinaturaParaMarketplace(?int $marketplaceId): bool
    {
        if (! $marketplaceId || ! Schema::hasTable('marketplace_brandings') || ! Schema::hasColumn('marketplace_brandings', 'exige_assinatura')) {
            return true;
        }

        $valor = MarketplaceBranding::query()
            ->where('marketplace_id', $marketplaceId)
            ->value('exige_assinatura');

        if ($valor === null) {
            return true;
        }

        return (bool) $valor;
    }
}
