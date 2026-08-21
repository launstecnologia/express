<?php

namespace Tests\Unit;

use App\Services\ConciliacaoConfrontoService;
use PHPUnit\Framework\TestCase;

class ConciliacaoConfrontoServiceTest extends TestCase
{
    public function test_comissao_cai_quando_edi_tem_menos_tpv(): void
    {
        $comissao = ConciliacaoConfrontoService::comissaoNaProporcaoDoTpv(
            40.0,
            16_000.0,
            12_000.0,
        );

        $this->assertSame(30.0, $comissao);
    }

    public function test_comissao_bate_quando_tpv_bate(): void
    {
        $comissao = ConciliacaoConfrontoService::comissaoNaProporcaoDoTpv(
            40.807,
            16_878.07,
            16_878.07,
        );

        $this->assertSame(40.807, $comissao);
    }

    public function test_comissao_zerada_quando_nao_ha_tpv_na_planilha(): void
    {
        $this->assertSame(0.0, ConciliacaoConfrontoService::comissaoNaProporcaoDoTpv(10.0, 0.0, 50.0));
    }

    public function test_tpv_compativel_respeita_tolerancia(): void
    {
        $this->assertTrue(ConciliacaoConfrontoService::tpvCompativel(100.00, 100.02));
        $this->assertFalse(ConciliacaoConfrontoService::tpvCompativel(100.00, 100.03));
    }
}
