<?php

namespace Tests\Unit;

use App\Models\KycDocumento;
use App\Services\Didit\DiditApiException;
use App\Services\Didit\DiditVerificationClient;
use App\Services\DiditKycService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiditKycServiceTest extends TestCase
{
    private const CHAVE = 'chave-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.didit.key' => self::CHAVE,
            'services.didit.base_url' => 'https://verification.didit.me',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('kyc/frente.jpg', 'imagem-falsa');
    }

    public function test_identidade_aprovada_envia_chave_do_ambiente_e_mapeia_cpf(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/id-verification/' => Http::response([
                'request_id' => '11d219ed-d59c-4b1d-8d65-9b933f12d5b8',
                'id_verification' => [
                    'status' => 'Approved',
                    'document_type' => 'Driver\'s License',
                    'personal_number' => '529.982.247-25',
                    'full_name' => 'Maria Souza',
                    'date_of_birth' => '1990-04-02',
                    'expiration_date' => '2030-01-01',
                    'issuing_state' => 'SP',
                    'warnings' => [],
                    'front_image_quality_score' => ['overall_score' => 80],
                ],
            ]),
        ]);

        $resultado = $this->servico()->analisarDocumento($this->documento('cnh_frente'));

        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->url() === 'https://verification.didit.me/v3/id-verification/'
                && $request->hasHeader('x-api-key', self::CHAVE)
                && ! $request->hasHeader('Authorization')
                && str_contains($body, 'name="perform_document_liveness"')
                && str_contains($body, 'name="vendor_data"')
                && str_contains($body, 'kyc-documento-15')
                && str_contains($body, 'name="front_image"');
        });

        $this->assertSame('aprovado', $resultado['status']);
        $this->assertSame('52998224725', $resultado['dados']['cpf']);
        $this->assertSame('Maria Souza', $resultado['dados']['nome']);
        $this->assertSame('1990-04-02', $resultado['dados']['data_nascimento']);
        $this->assertSame('2030-01-01', $resultado['dados']['data_expiracao']);
        $this->assertSame('SP', $resultado['dados']['uf']);
        $this->assertSame(0.8, $resultado['confianca']);
        $this->assertSame('didit-id-verification', $resultado['modelo']);
        $this->assertSame('11d219ed-d59c-4b1d-8d65-9b933f12d5b8', $resultado['ppid_consulta_id']);
        $this->assertNull($resultado['motivo']);
    }

    public function test_documento_expirado_reprova(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/id-verification/' => Http::response([
                'request_id' => 'f61681b8-9422-4378-ac3e-04c6a247fb45',
                'id_verification' => [
                    'status' => 'Declined',
                    'full_name' => 'Lucia Marin',
                    'expiration_date' => '2020-11-26',
                    'warnings' => [[
                        'risk' => 'DOCUMENT_EXPIRED',
                        'short_description' => 'Document expired',
                    ]],
                ],
            ]),
        ]);

        $resultado = $this->servico()->analisarDocumento($this->documento('rg_frente'));

        $this->assertSame('reprovado', $resultado['status']);
        $this->assertSame('Document expired', $resultado['motivo']);
        $this->assertFalse($resultado['dados']['sinais_adulteracao']);
    }

    public function test_documento_nao_reconhecido_vai_para_revisao_sem_nova_tentativa(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/id-verification/' => Http::response([
                'error' => 'COULD_NOT_RECOGNIZE_DOCUMENT',
            ], 400),
        ]);

        $resultado = $this->servico()->analisarDocumento($this->documento('rg_frente'));

        $this->assertSame('revisao_manual', $resultado['status']);
        $this->assertStringContainsString('não reconheceu', $resultado['motivo']);
    }

    public function test_limite_de_requisicoes_e_reexecutavel(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/id-verification/' => Http::response([
                'detail' => 'Write request rate limit exceeded.',
            ], 429),
        ]);

        try {
            $this->servico()->analisarDocumento($this->documento('cnh_frente'));
            $this->fail('A chamada deveria falhar com limite da Didit.');
        } catch (DiditApiException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(429, $e->httpStatus);
        }
    }

    public function test_sem_chave_nao_chama_a_api(): void
    {
        config(['services.didit.key' => null]);

        $resultado = $this->servico()->analisarDocumento($this->documento('cnh_frente'));

        Http::assertNothingSent();
        $this->assertSame('revisao_manual', $resultado['status']);
        $this->assertStringContainsString('DIDIT_API_KEY', $resultado['motivo']);
        $this->assertStringNotContainsString('sk_', (string) $resultado['motivo']);
    }

    public function test_comprovante_usa_document_ai_e_calcula_prazo(): void
    {
        Http::fake([
            'https://verification.didit.me/v3/document-ai/' => Http::response([
                'request_id' => '9d6a3b1e-2f0c-4a8e-9b7d-1c2e3f4a5b6c',
                'document_ai' => [
                    'status' => 'Approved',
                    'extracted_data' => [
                        'titular' => 'Maria Souza',
                        'cidade' => 'Campinas',
                        'uf' => 'sp',
                        'data_documento' => now()->subDays(10)->toDateString(),
                    ],
                    'warnings' => [],
                ],
            ]),
        ]);

        $resultado = $this->servico()->analisarDocumento($this->documento('comprovante_endereco'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://verification.didit.me/v3/document-ai/'
                && $request->hasHeader('x-api-key', self::CHAVE)
                && str_contains($request->body(), 'name="fields"')
                && str_contains($request->body(), '"key":"titular"');
        });

        $this->assertSame('aprovado', $resultado['status']);
        $this->assertSame('didit-document-ai', $resultado['modelo']);
        $this->assertSame('SP', $resultado['dados']['uf']);
        $this->assertTrue($resultado['dados']['dentro_prazo']);
    }

    private function servico(): DiditKycService
    {
        return new DiditKycService(new DiditVerificationClient);
    }

    private function documento(string $tipo): KycDocumento
    {
        $documento = new KycDocumento([
            'tipo' => $tipo,
            'caminho' => 'kyc/frente.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $documento->id = 15;

        return $documento;
    }
}
