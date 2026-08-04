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

    public function test_meio_edi_ignora_tipo_armazenado_outros_quando_arranjo_e_credito(): void
    {
        $this->assertSame(
            'credito',
            ConciliacaoDimensao::meioDoEdi('outros', '3', 'CREDIT_MASTERCARD', '3'),
        );
    }
}
