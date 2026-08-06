<?php

namespace Tests\Unit;

use App\Models\Hierarquia;
use App\Models\Usuario;
use App\Services\ComissaoPagService;
use App\Services\RoyaltyCalculadorService;
use Tests\TestCase;

class ComissaoPagServiceTest extends TestCase
{
    private ComissaoPagService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ComissaoPagService(new RoyaltyCalculadorService);
    }

    public function test_desconta_royalty_do_marketplace_sobre_comissao_bruta(): void
    {
        $admin = new Usuario(['tipo' => 'admin']);
        $admin->id = 1;

        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 20]);
        $marketplace->id = 10;

        $noAdmin = new Hierarquia(['usuario_id' => 1]);
        $noAdmin->setRelation('usuario', $admin);

        $noMarketplace = new Hierarquia(['usuario_id' => 10]);
        $noMarketplace->setRelation('pai', $noAdmin);
        $marketplace->setRelation('hierarquia', $noMarketplace);

        $resultado = $this->service->comissaoLiquidaMarketplace(500.0, $marketplace);

        $this->assertSame(500.0, $resultado['bruta']);
        $this->assertSame(100.0, $resultado['royalty']);
        $this->assertSame(400.0, $resultado['liquida']);
        $this->assertSame(20.0, $resultado['percentual']);
    }

    public function test_mantem_comissao_inteira_sem_retencao_configurada(): void
    {
        $admin = new Usuario(['tipo' => 'admin']);
        $admin->id = 1;

        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 0]);
        $marketplace->id = 10;

        $noAdmin = new Hierarquia(['usuario_id' => 1]);
        $noAdmin->setRelation('usuario', $admin);

        $noMarketplace = new Hierarquia(['usuario_id' => 10]);
        $noMarketplace->setRelation('pai', $noAdmin);
        $marketplace->setRelation('hierarquia', $noMarketplace);

        $resultado = $this->service->comissaoLiquidaMarketplace(40102.51, $marketplace);

        $this->assertSame(0.0, $resultado['royalty']);
        $this->assertSame(40102.51, $resultado['liquida']);
    }
}
