<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\KycDocumento;
use App\Services\Didit\DiditApiException;
use App\Services\Didit\DiditVerificationClient;
use App\Services\Ppid\PpidOcrService;

class DiditKycService
{
    private const FRAUDES_IDENTIDADE = [
        'SCREEN_CAPTURE_DETECTED',
        'PRINTED_COPY_DETECTED',
        'PORTRAIT_MANIPULATION_DETECTED',
        'PUBLIC_DOCUMENT_IMAGE_DETECTED',
        'DOCUMENT_EXPIRED',
    ];

    public function __construct(
        private DiditVerificationClient $didit,
    ) {}

    /**
     * @return array{status: string, dados: array, motivo: ?string, confianca: float, tokens: null, modelo: string, ppid_consulta_id: ?string}
     */
    public function analisarDocumento(KycDocumento $documento): array
    {
        try {
            if ($documento->tipo === 'comprovante_endereco') {
                return $this->analisarComprovante($documento);
            }

            $herdado = $this->resultadoJaIncluidoNoPar($documento);
            if ($herdado) {
                return $herdado;
            }

            return $this->analisarIdentidade($documento);
        } catch (DiditApiException $e) {
            if ($e->retryable) {
                throw $e;
            }

            return $this->revisaoManual($e->getMessage());
        }
    }

    /**
     * @return array{status: string, dados: array, motivo: ?string, confianca: float, tokens: null, modelo: string, ppid_consulta_id: ?string, outros_documentos: array<int, int>}
     */
    private function analisarIdentidade(KycDocumento $documento): array
    {
        [$frente, $verso, $outros] = $this->arquivosDoPar($documento);

        $resposta = $this->didit->verificarIdentidade(
            $frente,
            $verso,
            $this->vendorData($documento),
            [
                'kyc_documento_id' => $documento->id,
                'tipo' => $documento->tipo,
            ],
        );

        $id = is_array($resposta['id_verification'] ?? null) ? $resposta['id_verification'] : [];
        $warnings = is_array($id['warnings'] ?? null) ? $id['warnings'] : [];
        $riscos = $this->riscos($warnings);
        $statusDidit = (string) ($id['status'] ?? '');
        $fraude = array_values(array_intersect($riscos, self::FRAUDES_IDENTIDADE));

        if ($statusDidit === 'Approved') {
            $status = 'aprovado';
            $motivo = null;
        } elseif ($fraude !== []) {
            $status = 'reprovado';
            $motivo = $this->mensagensAvisos($warnings);
        } else {
            $status = 'revisao_manual';
            $motivo = $this->mensagensAvisos($warnings);
        }

        $confianca = $this->confiancaQualidade($id['front_image_quality_score'] ?? null);

        return [
            'status' => $status,
            'dados' => [
                'documento_valido' => $statusDidit === 'Approved',
                'documento_legivel' => $statusDidit !== '',
                'sinais_adulteracao' => $fraude !== [] && ! in_array('DOCUMENT_EXPIRED', $fraude, true),
                'confianca' => $confianca,
                'tipo_documento' => $id['document_type'] ?? null,
                'nome' => $id['full_name'] ?? null,
                'cpf' => $this->extrairCpf($id),
                'data_nascimento' => $this->dataIso($id['date_of_birth'] ?? null),
                'data_expiracao' => $this->dataIso($id['expiration_date'] ?? null),
                'numero_documento' => $id['document_number'] ?? null,
                'uf' => $this->uf($id),
                'cidade' => $id['parsed_address']['city'] ?? null,
                'provedor' => 'didit',
                'avisos' => $riscos,
                'incluiu_verso' => $verso !== null,
            ],
            'motivo' => $motivo,
            'confianca' => $confianca,
            'tokens' => null,
            'modelo' => 'didit-id-verification',
            'ppid_consulta_id' => isset($resposta['request_id']) ? (string) $resposta['request_id'] : null,
            'outros_documentos' => $outros,
        ];
    }

    /**
     * @return array{status: string, dados: array, motivo: ?string, confianca: float, tokens: null, modelo: string, ppid_consulta_id: ?string}
     */
    private function analisarComprovante(KycDocumento $documento): array
    {
        $extras = $this->nomeEsperado($documento->estabelecimento_id ? $documento->estabelecimento : null);

        $resposta = $this->didit->extrairDocumento(
            $this->caminhoLegivel($documento, 30 * 1024 * 1024),
            [
                [
                    'key' => 'titular',
                    'name' => 'Titular',
                    'instruction' => 'Nome completo da pessoa ou razão social a quem o comprovante é endereçado.',
                    'type' => 'text',
                    'required' => true,
                    'is_full_name' => true,
                ],
                [
                    'key' => 'cidade',
                    'name' => 'Cidade',
                    'instruction' => 'Cidade do endereço impresso no comprovante.',
                    'type' => 'text',
                    'required' => true,
                ],
                [
                    'key' => 'uf',
                    'name' => 'UF',
                    'instruction' => 'Sigla do estado brasileiro do endereço, com duas letras.',
                    'type' => 'text',
                    'required' => true,
                ],
                [
                    'key' => 'data_documento',
                    'name' => 'Data do documento',
                    'instruction' => 'Data de emissão ou de vencimento mais recente impressa no comprovante.',
                    'type' => 'date',
                    'required' => true,
                ],
            ],
            array_merge($extras, [
                'vendor_data' => $this->vendorData($documento),
                'metadata' => json_encode([
                    'kyc_documento_id' => $documento->id,
                    'tipo' => $documento->tipo,
                ], JSON_UNESCAPED_UNICODE),
            ]),
        );

        $ai = is_array($resposta['document_ai'] ?? null) ? $resposta['document_ai'] : [];
        $extraidos = is_array($ai['extracted_data'] ?? null) ? $ai['extracted_data'] : [];
        $warnings = is_array($ai['warnings'] ?? null) ? $ai['warnings'] : [];
        $riscos = $this->riscos($warnings);
        $statusDidit = (string) ($ai['status'] ?? '');
        $adulterado = in_array('DOCUMENT_AI_SUSPECTED_MANIPULATION', $riscos, true);

        if ($statusDidit === 'Approved') {
            $status = 'aprovado';
            $motivo = null;
        } elseif ($adulterado) {
            $status = 'reprovado';
            $motivo = $this->mensagensAvisos($warnings);
        } else {
            $status = 'revisao_manual';
            $motivo = $this->mensagensAvisos($warnings);
        }

        $dataDocumento = $this->dataIso($extraidos['data_documento'] ?? null);
        $confianca = isset($ai['name_match_score']) ? round(((float) $ai['name_match_score']) / 100, 2) : ($status === 'aprovado' ? 1.0 : 0.5);

        return [
            'status' => $status,
            'dados' => [
                'documento_valido' => $statusDidit === 'Approved',
                'documento_legivel' => $statusDidit !== '',
                'sinais_adulteracao' => $adulterado,
                'confianca' => $confianca,
                'nome' => $extraidos['titular'] ?? null,
                'cidade' => $extraidos['cidade'] ?? null,
                'uf' => isset($extraidos['uf']) ? strtoupper(substr(trim((string) $extraidos['uf']), 0, 2)) : null,
                'data_documento' => $dataDocumento,
                'dentro_prazo' => $dataDocumento ? strtotime($dataDocumento) >= strtotime('-90 days') : null,
                'provedor' => 'didit',
                'avisos' => $riscos,
            ],
            'motivo' => $motivo,
            'confianca' => $confianca,
            'tokens' => null,
            'modelo' => 'didit-document-ai',
            'ppid_consulta_id' => isset($resposta['request_id']) ? (string) $resposta['request_id'] : null,
            'outros_documentos' => [],
        ];
    }

    /**
     * Evita uma segunda cobrança quando a frente já foi enviada com este verso.
     *
     * @return array{status: string, dados: array, motivo: ?string, confianca: float, tokens: null, modelo: string, ppid_consulta_id: ?string, outros_documentos: array<int, int>}|null
     */
    private function resultadoJaIncluidoNoPar(KycDocumento $documento): ?array
    {
        $frente = $this->frentePar($documento);
        $dados = $frente?->openai_dados_extraidos ?? [];

        if (! $frente || empty($dados['incluiu_verso']) || ! $frente->openai_analisado_em || ! $frente->ppid_consulta_id) {
            return null;
        }

        $status = in_array($frente->openai_status, ['aprovado', 'reprovado', 'revisao_manual'], true)
            ? $frente->openai_status
            : 'revisao_manual';

        return [
            'status' => $status,
            'dados' => $dados,
            'motivo' => $frente->openai_motivo_reprovacao,
            'confianca' => (float) ($frente->openai_confianca ?? 0),
            'tokens' => null,
            'modelo' => 'didit-id-verification',
            'ppid_consulta_id' => $frente->ppid_consulta_id,
            'outros_documentos' => [],
        ];
    }

    /**
     * @return array{status: string, dados: array, motivo: ?string, confianca: float, tokens: null, modelo: string, ppid_consulta_id: ?string}
     */
    private function revisaoManual(string $motivo): array
    {
        return [
            'status' => 'revisao_manual',
            'dados' => [
                'documento_valido' => false,
                'documento_legivel' => false,
                'sinais_adulteracao' => false,
                'provedor' => 'didit',
            ],
            'motivo' => mb_substr($motivo, 0, 480),
            'confianca' => 0.0,
            'tokens' => null,
            'modelo' => 'didit-id-verification',
            'ppid_consulta_id' => null,
            'outros_documentos' => [],
        ];
    }

    /**
     * @return array{0: string, 1: ?string, 2: array<int, int>}
     */
    private function arquivosDoPar(KycDocumento $documento): array
    {
        $atual = $this->caminhoLegivel($documento);
        $frente = $this->frentePar($documento);
        $verso = $this->versoPar($documento);

        if (in_array($documento->tipo, ['rg_verso', 'cnh_verso'], true) && $frente) {
            $caminhoFrente = PpidOcrService::caminhoAbsoluto($frente->caminho, $frente->usaDiscoPublico());
            if (is_readable($caminhoFrente)) {
                return [$caminhoFrente, $atual, [$frente->id]];
            }
        }

        if ($verso) {
            $caminhoVerso = PpidOcrService::caminhoAbsoluto($verso->caminho, $verso->usaDiscoPublico());
            if (is_readable($caminhoVerso)) {
                return [$atual, $caminhoVerso, [$verso->id]];
            }
        }

        return [$atual, null, []];
    }

    private function caminhoLegivel(KycDocumento $documento, int $limiteBytes = 10485760): string
    {
        $caminho = PpidOcrService::caminhoAbsoluto($documento->caminho, $documento->usaDiscoPublico());
        if (! is_readable($caminho)) {
            throw new DiditApiException('Arquivo do documento não encontrado ou ilegível.');
        }

        $tamanho = filesize($caminho);
        if ($tamanho !== false && $tamanho > $limiteBytes) {
            $mb = (int) round($limiteBytes / 1048576);
            throw new DiditApiException("Arquivo acima do limite da Didit ({$mb} MB).");
        }

        return $caminho;
    }

    private function versoPar(KycDocumento $documento): ?KycDocumento
    {
        $tipoVerso = match ($documento->tipo) {
            'rg_frente' => 'rg_verso',
            'cnh_frente' => 'cnh_verso',
            default => null,
        };

        if (! $tipoVerso || ! $documento->kyc_analise_id) {
            return null;
        }

        return KycDocumento::query()
            ->where('kyc_analise_id', $documento->kyc_analise_id)
            ->where('tipo', $tipoVerso)
            ->first();
    }

    private function frentePar(KycDocumento $documento): ?KycDocumento
    {
        $tipoFrente = match ($documento->tipo) {
            'rg_verso' => 'rg_frente',
            'cnh_verso' => 'cnh_frente',
            default => null,
        };

        if (! $tipoFrente || ! $documento->kyc_analise_id) {
            return null;
        }

        return KycDocumento::query()
            ->where('kyc_analise_id', $documento->kyc_analise_id)
            ->where('tipo', $tipoFrente)
            ->first();
    }

    private function vendorData(KycDocumento $documento): string
    {
        return 'kyc-documento-'.($documento->id ?: 'novo');
    }

    /**
     * @param  array<string, mixed>  $id
     */
    private function extrairCpf(array $id): ?string
    {
        $candidatos = [
            $id['personal_number'] ?? null,
            $id['document_number'] ?? null,
        ];

        $extras = is_array($id['extra_fields'] ?? null) ? $id['extra_fields'] : [];
        foreach ($extras as $valor) {
            if (is_scalar($valor)) {
                $candidatos[] = $valor;
            }
        }

        foreach ($candidatos as $valor) {
            $digitos = preg_replace('/\D/', '', (string) $valor);
            if (strlen($digitos) === 11) {
                return $digitos;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $id
     */
    private function uf(array $id): ?string
    {
        $estado = strtoupper(trim((string) ($id['issuing_state'] ?? '')));
        if (strlen($estado) === 2) {
            return $estado;
        }

        $regiao = strtoupper(trim((string) ($id['parsed_address']['region'] ?? '')));

        return strlen($regiao) === 2 ? $regiao : null;
    }

    private function dataIso(mixed $valor): ?string
    {
        $data = trim((string) $valor);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $data)) {
            return substr($data, 0, 10);
        }

        return null;
    }

    private function confiancaQualidade(mixed $qualidade): float
    {
        if (! is_array($qualidade) || ! isset($qualidade['overall_score'])) {
            return 1.0;
        }

        return round(max(0, min(100, (float) $qualidade['overall_score'])) / 100, 2);
    }

    /**
     * @param  array<int, mixed>  $warnings
     * @return array<int, string>
     */
    private function riscos(array $warnings): array
    {
        $riscos = [];
        foreach ($warnings as $aviso) {
            if (is_array($aviso) && isset($aviso['risk'])) {
                $riscos[] = (string) $aviso['risk'];
            }
        }

        return $riscos;
    }

    /**
     * @param  array<int, mixed>  $warnings
     */
    private function mensagensAvisos(array $warnings): string
    {
        $textos = [];
        foreach ($warnings as $aviso) {
            if (! is_array($aviso)) {
                continue;
            }
            $texto = $aviso['short_description'] ?? $aviso['risk'] ?? null;
            if (is_string($texto) && $texto !== '') {
                $textos[] = $texto;
            }
        }

        $mensagem = $textos !== [] ? implode(' ', $textos) : 'Documento recusado pela Didit.';

        return mb_substr($mensagem, 0, 480);
    }

    /**
     * @return array<string, string>
     */
    private function nomeEsperado(?Estabelecimento $estabelecimento): array
    {
        if (! $estabelecimento) {
            return [];
        }

        if ($estabelecimento->pessoa_tipo === 'juridica' && filled($estabelecimento->razao_social)) {
            return [
                'expected_company_name' => (string) $estabelecimento->razao_social,
                'expected_company_country' => 'BR',
            ];
        }

        $nome = trim((string) ($estabelecimento->nome_completo ?? $estabelecimento->rep_nome ?? ''));
        $partes = preg_split('/\s+/', $nome) ?: [];
        if (count($partes) < 2) {
            return [];
        }

        $primeiro = array_shift($partes);

        return [
            'expected_first_name' => (string) $primeiro,
            'expected_last_name' => implode(' ', $partes),
        ];
    }
}
