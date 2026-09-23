<?php

namespace Tests\Unit;

use App\Support\ConciliacaoDimensao;
use PHPUnit\Framework\TestCase;

class ConciliacaoDimensaoTest extends TestCase
{
    public function test_solucao_edi_moderninha_mapeia_para_mobile(): void
    {
        $this->assertSame('mobile', ConciliacaoDimensao::solucaoDoEdi('01', 'ME', '01'));
    }

    public function test_solucao_edi_ecommerce_mapeia_para_web(): void
    {
        $this->assertSame('web', ConciliacaoDimensao::solucaoDoEdi(null, 'W', null));
    }

    public function test_solucao_edi_tap_on_phone(): void
    {
        $this->assertSame('tap on', ConciliacaoDimensao::solucaoDoEdi(null, 'TP', null));
    }

    public function test_chaves_pagseguro_e_edi_coincidem_para_cenario_tipico(): void
    {
        $chavePag = ConciliacaoDimensao::chaveConfrontoDaLinha(
            '12345678',
            'credito',
            'a vista',
            'visa',
            '0',
            'mobile',
        );

        $chaveEdi = ConciliacaoDimensao::chaveConfrontoDaLinha(
            '12345678',
            ConciliacaoDimensao::meioDoEdi('credito', '3', 'CREDIT_VISA', '1'),
            ConciliacaoDimensao::parcelamentoDoEdi('1'),
            ConciliacaoDimensao::bandeiraDoEdi('VISA', 'credito', 'CREDIT_VISA'),
            ConciliacaoDimensao::escrowDoEdi('00'),
            ConciliacaoDimensao::solucaoDoEdi('01', 'ME', '01'),
        );

        $this->assertSame($chavePag, $chaveEdi);
    }

    public function test_id_cliente_normaliza_zeros_a_esquerda(): void
    {
        $chaveComZeros = ConciliacaoDimensao::chaveConfrontoDaLinha(
            '0012345678',
            'credito',
            'a vista',
            'visa',
            '0',
            'mobile',
        );

        $chaveSemZeros = ConciliacaoDimensao::chaveConfrontoDaLinha(
            '12345678',
            'credito',
            'a vista',
            'visa',
            '0',
            'mobile',
        );

        $this->assertSame($chaveSemZeros, $chaveComZeros);
    }

    public function test_escrow_edi_recebimento_unico_usa_plano(): void
    {
        $this->assertSame('14', ConciliacaoDimensao::escrowDoEdi('U', '14'));
        $this->assertSame('0', ConciliacaoDimensao::escrowDoEdi('M', '03'));
        $this->assertSame('0', ConciliacaoDimensao::escrowDoEdi('U', '00'));
    }

    public function test_meio_edi_ignora_tipo_armazenado_outros_quando_arranjo_e_credito(): void
    {
        $this->assertSame(
            'credito',
            ConciliacaoDimensao::meioDoEdi('outros', '3', 'CREDIT_MASTERCARD', '3'),
        );
    }

    public function test_chave_unica_venda_agrupa_parcelas_com_mesmo_nsu_e_valor(): void
    {
        $primeira = ConciliacaoDimensao::chaveUnicaVenda(10, 25745.55, null, null, '621914030372', 107, '2026-08-07');
        $segunda = ConciliacaoDimensao::chaveUnicaVenda(11, 25745.55, null, null, '621914030372', 107, '2026-08-07');

        $this->assertSame($primeira, $segunda);
    }

    public function test_maestro_normaliza_para_master(): void
    {
        $this->assertSame('master', ConciliacaoDimensao::bandeiraNormalizada('maestro'));
        $this->assertSame(
            ConciliacaoDimensao::chaveConfrontoDaLinha('806172719', 'debito', 'a vista', 'master', '0', 'mobile'),
            ConciliacaoDimensao::chaveConfrontoDaLinha('806172719', 'debito', 'a vista', 'maestro', '0', 'mobile'),
        );
    }

    public function test_grupo_basico_ignora_escrow_e_solucao(): void
    {
        $this->assertSame(
            ConciliacaoDimensao::chaveGrupoBasico('806172719', 'credito', '2 a 6', 'visa'),
            ConciliacaoDimensao::chaveGrupoBasico('806172719', 'CREDITO', '2-6', 'Visa'),
        );
    }

    public function test_chave_unica_venda_separa_estorno_com_valor_diferente(): void
    {
        $venda = ConciliacaoDimensao::chaveUnicaVenda(10, 25745.55, 'ABC123', null, null, 107, '2026-08-07');
        $estorno = ConciliacaoDimensao::chaveUnicaVenda(11, -25745.55, 'ABC123', null, null, 107, '2026-08-07');

        $this->assertNotSame($venda, $estorno);
    }
}
