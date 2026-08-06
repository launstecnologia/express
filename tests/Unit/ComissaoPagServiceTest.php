<?php

namespace Tests\Unit;

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

    public function test_desconta_royalty_mesmo_sem_pai_hierarquico(): void
    {
        $marketplace = new Usuario(['tipo' => 'marketplace', 'percentual_retencao_pai' => 25]);
        $marketplace->id = 10;
        $marketplace->setRelation('hierarquia', null);

        $resultado = $this->service->comissaoLiquidaParceiro(8860.17, $marketplace);

        $this->assertSame(2215.04, $resultado['royalty']);
        $this->assertSame(6645.13, $resultado['liquida']);
        $this->assertSame(25.0, $resultado['percentual']);
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
