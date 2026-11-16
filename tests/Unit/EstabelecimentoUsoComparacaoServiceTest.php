<?php

namespace Tests\Unit;

use App\Services\EstabelecimentoUsoComparacaoService;
use Tests\TestCase;

class EstabelecimentoUsoComparacaoServiceTest extends TestCase
{
    private EstabelecimentoUsoComparacaoService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servico = new EstabelecimentoUsoComparacaoService;
    }

    public function test_classifica_quem_parou_baixou_aumentou_ou_comecou(): void
    {
        $this->assertSame('parou', $this->servico->situacao(1500, 0));
        $this->assertSame('abaixou', $this->servico->situacao(1500, 400));
        $this->assertSame('aumentou', $this->servico->situacao(400, 1500));
        $this->assertSame('comecou', $this->servico->situacao(0, 800));
        $this->assertSame('manteve', $this->servico->situacao(800, 800));
        $this->assertSame('sem_uso', $this->servico->situacao(0, 0));
    }

    public function test_variacao_percentual_usa_o_mes_de_referencia(): void
    {
        $this->assertEqualsWithDelta(-100.0, $this->servico->variacaoPercentual(200, 0), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->servico->variacaoPercentual(200, 300), 0.001);
        $this->assertNull($this->servico->variacaoPercentual(0, 300));
    }

    public function test_intervalo_do_mes_cobre_o_mes_inteiro(): void
    {
        $this->assertSame(['2026-02-01', '2026-02-28'], $this->servico->intervaloDoMes('2026-02'));
    }
}
