<?php

namespace Tests\Unit;

use App\Services\ConciliacaoConfrontoService;
use PHPUnit\Framework\TestCase;

class ConciliacaoConfrontoServiceTest extends TestCase
{
    public function test_rateia_comissao_registrada_pelo_tpv_da_linha(): void
    {
        $this->assertSame(25.0, ConciliacaoConfrontoService::ratear(50.0, 100.0, 200.0));
    }

    public function test_ratear_zera_quando_o_grupo_nao_tem_peso(): void
    {
        $this->assertSame(0.0, ConciliacaoConfrontoService::ratear(50.0, 100.0, 0.0));
    }

    public function test_tpv_compativel_respeita_tolerancia(): void
    {
        $this->assertTrue(ConciliacaoConfrontoService::tpvCompativel(100.00, 100.02));
        $this->assertFalse(ConciliacaoConfrontoService::tpvCompativel(100.00, 100.03));
    }

    public function test_comissao_compativel_usa_a_mesma_tolerancia(): void
    {
        $this->assertTrue(ConciliacaoConfrontoService::valoresCompativeis(40.807, 40.82));
        $this->assertFalse(ConciliacaoConfrontoService::valoresCompativeis(40.807, 40.85));
    }
}
