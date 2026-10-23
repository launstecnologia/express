<?php

namespace Tests\Unit;

use App\Models\PlanoTaxa;
use App\Support\AceiteContratacao;
use PHPUnit\Framework\TestCase;

class AceiteContratacaoTest extends TestCase
{
    public function test_pessoa_fisica_nao_exige_contrato(): void
    {
        $tipos = array_column(AceiteContratacao::documentosExigidos('fisica'), 'tipo');

        $this->assertCount(5, $tipos);
        $this->assertNotContains('Contrato Social (Se for MEI - CCMEI)', $tipos);
        $this->assertContains('Selfie Segurando Documento', $tipos);
        $this->assertContains('Comprovante da Atividade', $tipos);
    }

    public function test_pessoa_juridica_exige_contrato_ou_cmei(): void
    {
        $tipos = array_column(AceiteContratacao::documentosExigidos('juridica'), 'tipo');

        $this->assertCount(6, $tipos);
        $this->assertContains('Contrato Social (Se for MEI - CCMEI)', $tipos);
    }

    public function test_agrupa_taxas_iguais_e_separa_bandeiras_diferentes(): void
    {
        $linhas = AceiteContratacao::linhasDeTaxas(collect([
            $this->taxa('debito', 1, 'VISA', '1.99', true),
            $this->taxa('debito', 1, 'MASTERCARD', '1.99', true),
            $this->taxa('debito', 1, 'ELO', '2.10', true),
            $this->taxa('debito', 1, 'CABAL', '9.99', false),
            $this->taxa('credito', 1, 'VISA', '2.50', true),
            $this->taxa('credito', 2, 'VISA', '3.00', true),
            $this->taxa('pix', 1, 'BACEN', '0.99', true),
        ]));

        $this->assertSame('Débito', $linhas[0]['modalidade']);
        $this->assertNull($linhas[0]['taxa']);
        $this->assertSame('1.99', $linhas[0]['detalhes'][0]['taxa']);
        $this->assertSame(['Mastercard', 'VISA'], $linhas[0]['detalhes'][0]['bandeiras']);
        $this->assertSame('2.10', $linhas[0]['detalhes'][1]['taxa']);
        $this->assertSame('Crédito à vista', $linhas[1]['modalidade']);
        $this->assertSame('2.50', $linhas[1]['taxa']);
        $this->assertSame(['VISA'], $linhas[1]['bandeiras']);
        $this->assertSame('Crédito parcelado em 2x', $linhas[2]['modalidade']);
        $this->assertSame('Pix na maquininha', $linhas[3]['modalidade']);
        $this->assertSame(['Pix'], $linhas[3]['bandeiras']);
        $this->assertArrayNotHasKey(4, $linhas);
    }

    public function test_hash_nao_muda_com_a_ordem_das_chaves(): void
    {
        $primeiro = ['b' => 1, 'a' => ['d' => 2, 'c' => [3, 1]]];
        $segundo = ['a' => ['c' => [3, 1], 'd' => 2], 'b' => 1];

        $this->assertSame(
            AceiteContratacao::hashConfirmacao($primeiro),
            AceiteContratacao::hashConfirmacao($segundo),
        );
        $this->assertSame(64, strlen(AceiteContratacao::hashConfirmacao($primeiro)));
    }

    public function test_declaracao_registra_chargeback_e_o_responsavel(): void
    {
        $texto = AceiteContratacao::declaracao(
            'Padaria Sol',
            '12.345.678/0001-90',
            'Express',
            'Maria Souza',
            '123.456.789-09',
            'Titular do cadastro',
        );

        $this->assertStringContainsString('chargeback', $texto);
        $this->assertStringContainsString('Maria Souza', $texto);
        $this->assertStringContainsString('Padaria Sol', $texto);
    }

    public function test_assinatura_em_branco_e_recusada(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD ausente');
        }

        $imagem = imagecreatetruecolor(40, 40);
        $branco = imagecolorallocate($imagem, 255, 255, 255);
        imagefill($imagem, 0, 0, $branco);
        ob_start();
        imagepng($imagem);
        $binario = ob_get_clean();
        imagedestroy($imagem);

        $resultado = AceiteContratacao::validarAssinatura('data:image/png;base64,'.base64_encode($binario));

        $this->assertArrayHasKey('erro', $resultado);
    }

    public function test_assinatura_com_traco_e_aceita(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD ausente');
        }

        $imagem = imagecreatetruecolor(40, 40);
        $branco = imagecolorallocate($imagem, 255, 255, 255);
        $preto = imagecolorallocate($imagem, 15, 23, 42);
        imagefill($imagem, 0, 0, $branco);
        imageline($imagem, 2, 20, 38, 22, $preto);
        ob_start();
        imagepng($imagem);
        $binario = ob_get_clean();
        imagedestroy($imagem);

        $resultado = AceiteContratacao::validarAssinatura('data:image/png;base64,'.base64_encode($binario));

        $this->assertArrayHasKey('bin', $resultado);
        $this->assertNotSame('', $resultado['bin']);
    }

    private function taxa(string $tipo, int $parcelas, string $instituicao, string $percentual, bool $ativo): PlanoTaxa
    {
        return new PlanoTaxa([
            'tipo_transacao' => $tipo,
            'parcelas' => $parcelas,
            'instituicao' => $instituicao,
            'taxa_percentual' => $percentual,
            'ativo' => $ativo,
        ]);
    }
}
