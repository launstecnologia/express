<?php

namespace App\Services\Didit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DiditVerificationClient
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function verificarIdentidade(string $frente, ?string $verso, string $vendorData, array $metadata): array
    {
        $arquivos = ['front_image' => $frente];
        if ($verso) {
            $arquivos['back_image'] = $verso;
        }

        return $this->enviar('/v3/id-verification/', $arquivos, [
            'perform_document_liveness' => 'true',
            'save_api_request' => 'true',
            'expiration_date_not_detected_action' => 'NO_ACTION',
            'preferred_characters' => 'latin',
            'vendor_data' => $vendorData,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, string>  $extras
     * @return array<string, mixed>
     */
    public function extrairDocumento(string $arquivo, array $fields, array $extras = []): array
    {
        return $this->enviar('/v3/document-ai/', [
            'document' => $arquivo,
        ], array_merge([
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'save_api_request' => 'true',
            'document_ai_name_mismatch_action' => 'NO_ACTION',
        ], $extras));
    }

    /**
     * @param  array<string, string>  $arquivos
     * @param  array<string, string>  $campos
     * @return array<string, mixed>
     */
    private function enviar(string $caminho, array $arquivos, array $campos): array
    {
        $chave = config('services.didit.key');
        if (! filled($chave)) {
            throw new DiditApiException('DIDIT_API_KEY não configurada.');
        }

        $request = Http::timeout(75)
            ->acceptJson()
            ->withHeaders(['x-api-key' => $chave]);

        foreach ($arquivos as $campo => $arquivo) {
            $conteudo = file_get_contents($arquivo);
            if ($conteudo === false) {
                throw new DiditApiException('Não foi possível ler o arquivo do documento.');
            }

            $request = $request->attach($campo, $conteudo, $this->nomeUpload($arquivo));
        }

        $response = $request->post($this->url($caminho), $campos);

        return $this->interpretar($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function interpretar(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        $erro = (string) ($response->json('error') ?? $response->json('detail') ?? '');
        $retryable = $response->status() === 429 || $response->serverError();

        if ($response->status() === 403 && str_contains(strtolower($erro), 'credit')) {
            throw new DiditApiException('Saldo insuficiente na conta Didit.', false, 403);
        }

        if ($response->status() === 403) {
            throw new DiditApiException('Chave DIDIT_API_KEY inválida ou sem permissão na Didit.', false, 403);
        }

        if ($response->status() === 429) {
            throw new DiditApiException('Limite de requisições da Didit atingido. A análise será tentada de novo.', true, 429);
        }

        if (str_contains($erro, 'COULD_NOT_RECOGNIZE_DOCUMENT')) {
            throw new DiditApiException('A Didit não reconheceu um documento de identidade na imagem.', false, 400);
        }

        $mensagem = $erro !== ''
            ? 'Falha na Didit: '.$erro
            : 'Falha na Didit (HTTP '.$response->status().').';

        throw new DiditApiException(mb_substr($mensagem, 0, 480), $retryable, $response->status());
    }

    private function url(string $caminho): string
    {
        return rtrim((string) config('services.didit.base_url', 'https://verification.didit.me'), '/').$caminho;
    }

    private function nomeUpload(string $arquivo): string
    {
        $nome = basename($arquivo);
        if (pathinfo($nome, PATHINFO_EXTENSION) !== '') {
            return $nome;
        }

        return $nome.'.jpg';
    }
}
