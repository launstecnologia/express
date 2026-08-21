<?php

namespace Tests\Unit;

use App\Models\Hierarquia;
use App\Models\Usuario;
use App\Services\ComissaoPagService;
use Tests\TestCase;

class ComissaoPagServiceTest extends TestCase
{
    private ComissaoPagService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ComissaoPagService;
    }

    public function test_desconta_royalty_do_marketplace_sobre_comissao_bruta(): void
    {
        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 20]);
        $marketplace->id = 10;

        $resultado = $this->service->comissaoLiquidaParceiro(500.0, $marketplace);

        $this->assertSame(500.0, $resultado['bruta']);
        $this->assertSame(100.0, $resultado['royalty']);
        $this->assertSame(400.0, $resultado['liquida']);
        $this->assertSame(20.0, $resultado['percentual']);
    }

    public function test_comissao_revenda_e_percentual_sobre_liquida_do_marketplace(): void
    {
        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 20]);
        $marketplace->id = 10;

        $revenda = new Usuario(['tipo' => 'revenda', 'percentual_retencao_pai' => 25]);
        $revenda->id = 20;

        // Bruta 1000 → admin 20% = 200 → marketplace 800 → revenda 25% = 200
        $resultado = $this->service->comissaoRevendaDaCarteira(1000.0, $marketplace, $revenda);

        $this->assertSame(1000.0, $resultado['marketplace_bruta']);
        $this->assertSame(200.0, $resultado['admin_royalty']);
        $this->assertSame(800.0, $resultado['marketplace_liquida']);
        $this->assertSame(25.0, $resultado['percentual_revenda']);
        $this->assertSame(200.0, $resultado['revenda']);
    }

    public function test_valor_comissao_revenda_usa_percentual_sobre_liquida_do_marketplace(): void
    {
        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 20]);
        $marketplace->id = 10;

        $revenda = new Usuario(['tipo' => 'revenda', 'percentual_retencao_pai' => 25]);
        $revenda->id = 20;

        $noMarketplace = new Hierarquia(['usuario_id' => 10]);
        $noMarketplace->setRelation('usuario', $marketplace);

        $noRevenda = new Hierarquia(['usuario_id' => 20]);
        $noRevenda->setRelation('pai', $noMarketplace);
        $revenda->setRelation('hierarquia', $noRevenda);

        // Bruta 1000 → admin 20% = 200 → marketplace 800 → revenda 25% = 200
        $this->assertSame(200.0, $this->service->valorComissaoParceiro(1000.0, $revenda));
    }

    public function test_valor_comissao_marketplace_desconta_royalty_do_pai(): void
    {
        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 20]);
        $marketplace->id = 10;

        $this->assertSame(400.0, $this->service->valorComissaoParceiro(500.0, $marketplace));
    }

    public function test_mantem_comissao_inteira_sem_retencao_configurada(): void
    {
        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 0]);
        $marketplace->id = 10;

        $resultado = $this->service->comissaoLiquidaParceiro(40102.51, $marketplace);

        $this->assertSame(0.0, $resultado['royalty']);
        $this->assertSame(40102.51, $resultado['liquida']);
    }
}
