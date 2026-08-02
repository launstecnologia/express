<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Support\DocumentoBrasil;
use App\Support\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AutomacaoPagBankService
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct()
    {
        $url = PlatformSettings::automacaoApiUrl();
        $key = PlatformSettings::automacaoApiKey();

        if (! filled($url) || ! filled($key)) {
            throw new RuntimeException(
                'Automação PagBank não configurada. '
                . 'Defina AUTOMACAO_API_URL e AUTOMACAO_API_KEY no .env'
            );
        }

        $this->apiUrl = $url;
        $this->apiKey = $key;
    }

    // ----------------------------------------------------------------
    // Verificação de saúde
    // ----------------------------------------------------------------
    public function healthOk(): bool
    {
        try {
            $resp = Http::timeout(5)
                ->get("{$this->apiUrl}/health");

            return $resp->ok() && ($resp->json('ok') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Inicia o job de automação
    // Retorna o job_id ou lança exceção
    // ----------------------------------------------------------------
    public function iniciarCadastro(Estabelecimento $estab, string $senha6): string
    {
        $payload = $this->montarPayload($estab, $senha6);

        $response = Http::timeout(15)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->post("{$this->apiUrl}/cadastrar", $payload);

        if ($response->status() === 409) {
            // Compatibilidade com API antiga — cancela e tenta de novo
            Log::warning('AutomacaoPagBank: job em andamento na API antiga — tentando novamente', [
                'estabelecimento_id' => $estab->id,
                'job_id' => $response->json('job_id'),
            ]);

            sleep(1);
            $response = Http::timeout(15)
                ->withHeaders(['X-Api-Key' => $this->apiKey])
                ->post("{$this->apiUrl}/cadastrar", $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao iniciar automação: '.$response->status().' — '.$response->body()
            );
        }

        return $response->json('job_id');
    }

    // ----------------------------------------------------------------
    // Retenta apenas a etapa de e-mail
    // ----------------------------------------------------------------
    public function retentarEmail(Estabelecimento $estab, string $senha6): string
    {
        $payload = [
            'estabelecimento_id'  => $estab->id,
            'documento'           => $this->documentoEstabelecimento($estab),
            'webmail_url'         => PlatformSettings::automacaoWebmailUrl() ?? '',
            'webmail_usuario'     => $estab->webmail_email ?? '',
            'webmail_senha'       => $estab->webmail_senha ?? '',
            'senha_6'             => $senha6,
            'headless'            => config('automacao.headless', true),
            'aguardar_email_seg'  => config('automacao.aguardar_email_seg', 90),
        ];

        $response = Http::timeout(15)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->post("{$this->apiUrl}/retentar-email", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao retentar e-mail: '.$response->status().' — '.$response->body()
            );
        }

        return $response->json('job_id');
    }

    // ----------------------------------------------------------------
    // Consulta status do job
    // ----------------------------------------------------------------
    public function consultarStatus(string $jobId): array
    {
        $response = $this->http(10)->get("{$this->apiUrl}/status/{$jobId}");

        if (! $response->successful()) {
            throw new RuntimeException(
                "Falha ao consultar job {$jobId}: ".$response->status().' — '.$response->body()
            );
        }

        return $response->json();
    }

    /**
     * Monta contexto rico para interpretação de erro (timeout, falha FV, etc.).
     *
     * @return array<string, mixed>
     */
    public function montarContextoErroJob(string $jobId, ?array $status = null): array
    {
        try {
            $status ??= $this->consultarStatus($jobId);
        } catch (\Throwable) {
            $status = [];
        }

        $screenshots = [];
        try {
            $lista = $this->listarScreenshots($jobId);
            foreach ($lista['screenshots'] ?? [] as $item) {
                if (is_array($item) && filled($item['arquivo'] ?? null)) {
                    $screenshots[] = (string) $item['arquivo'];
                }
            }
        } catch (\Throwable) {
            // ignora — screenshots podem não existir ainda
        }

        $resultado = $status['resultado'] ?? null;
        $detalheFv = is_array($resultado) ? ($resultado['etapa_fv']['detalhe'] ?? $resultado['etapa_fv'] ?? null) : null;

        return [
            'status' => $status['status'] ?? null,
            'etapa_atual' => $status['etapa_atual'] ?? null,
            'etapa_log' => $status['etapa_atual'] ?? null,
            'atualizado_em' => $status['atualizado_em'] ?? null,
            'codigo_versao' => $status['codigo_versao'] ?? null,
            'erro_tecnico' => $status['erro'] ?? null,
            'resultado' => $resultado,
            'detalhe' => array_filter([
                'etapa_falha' => is_array($detalheFv) ? ($detalheFv['etapa_falha'] ?? null) : null,
                'etapa_falha_label' => is_array($detalheFv) ? ($detalheFv['etapa_falha_label'] ?? null) : null,
                'erro' => is_array($detalheFv) ? ($detalheFv['erro'] ?? null) : ($status['erro'] ?? null),
                'erro_resumido' => is_array($detalheFv) ? ($detalheFv['erro_resumido'] ?? null) : null,
                'screenshots' => is_array($detalheFv) ? ($detalheFv['screenshots'] ?? []) : [],
            ]),
            'screenshots' => $screenshots,
        ];
    }

    public function consultarStatusESincronizarLogs(Estabelecimento $estab, string $jobId): array
    {
        $status = $this->consultarStatus($jobId);

        app(AutomacaoLogService::class)->sincronizarDoJob(
            $estab->id,
            $jobId,
            $status['logs'] ?? [],
        );

        return $status;
    }

    /**
     * Consulta status durante o polling do job Laravel.
     * Falhas transitórias de rede não abortam a automação — retorna null para tentar de novo no próximo ciclo.
     */
    public function consultarStatusParaPolling(Estabelecimento $estab, string $jobId): ?array
    {
        try {
            return $this->consultarStatusESincronizarLogs($estab, $jobId);
        } catch (\Throwable $e) {
            if (! self::erroRedeTransitario($e)) {
                throw $e;
            }

            Log::warning('AutomacaoPagBank: falha transitória ao consultar status — tentará novamente', [
                'estabelecimento_id' => $estab->id,
                'job_id' => $jobId,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function erroRedeTransitario(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'could not resolve host')
            || str_contains($msg, 'curl error')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'connection timed out')
            || str_contains($msg, 'failed to connect')
            || str_contains($msg, 'operation timed out')
            || str_contains($msg, 'name or service not known');
    }

    public function listarScreenshots(string $jobId): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->get("{$this->apiUrl}/jobs/{$jobId}/screenshots");

        if (! $response->successful()) {
            throw new RuntimeException(
                "Falha ao listar screenshots do job {$jobId}: ".$response->status().' — '.$response->body()
            );
        }

        return $response->json();
    }

    public function baixarScreenshot(string $jobId, string $filename): \Illuminate\Http\Client\Response
    {
        $arquivo = basename($filename);

        return Http::timeout(30)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->get("{$this->apiUrl}/jobs/{$jobId}/screenshots/{$arquivo}");
    }

    // ----------------------------------------------------------------
    // Consulta CPF/CNPJ no portal FV (sem cadastrar)
    // ----------------------------------------------------------------
    public function iniciarConsultaDocumento(string $documento): string
    {
        $documentoFormatado = $this->formatarDocumentoConsulta($documento);

        $response = Http::timeout(15)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->post("{$this->apiUrl}/consultar-documento", [
                'documento' => $documentoFormatado,
                'fv_usuario' => PlatformSettings::automacaoFvUsuario() ?? '',
                'fv_senha' => PlatformSettings::automacaoFvSenha() ?? '',
                'headless' => config('automacao.headless', true),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao consultar documento: '.$response->status().' — '.$response->body()
            );
        }

        return $response->json('job_id');
    }

    public function iniciarBuscaSafepayId(Estabelecimento $estab): string
    {
        $documento = $this->documentoEstabelecimento($estab);

        $response = Http::timeout(15)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->post("{$this->apiUrl}/buscar-safepay-id", [
                'estabelecimento_id' => $estab->id,
                'documento' => $documento,
                'fv_usuario' => PlatformSettings::automacaoFvUsuario() ?? '',
                'fv_senha' => PlatformSettings::automacaoFvSenha() ?? '',
                'email_suffix' => 'express.app.br',
                'headless' => config('automacao.headless', true),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao buscar Safepay ID: '.$response->status().' — '.$response->body()
            );
        }

        return $response->json('job_id');
    }

    public function iniciarAceitarProposta(Estabelecimento $estab): string
    {
        if (blank($estab->fv_senha_6)) {
            throw new RuntimeException('Senha PagBank (6 dígitos) não disponível para este estabelecimento.');
        }

        $documento = $this->documentoEstabelecimento($estab);
        $email = $estab->webmail_email ?: $estab->email;

        $response = Http::timeout(15)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->post("{$this->apiUrl}/aceitar-proposta", [
                'estabelecimento_id' => $estab->id,
                'documento' => $documento,
                'senha_6' => $estab->fv_senha_6,
                'email' => $email ?? '',
                'email_suffix' => 'express.app.br',
                'headless' => config('automacao.headless', true),
            ]);

        if ($response->status() === 404) {
            throw new RuntimeException(
                'Endpoint /aceitar-proposta não encontrado na API Python. '
                . 'Reconstrua o serviço: docker compose build automacao && docker compose up -d --force-recreate automacao'
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao iniciar aceite de proposta: '.$response->status().' — '.$response->body()
            );
        }

        return $response->json('job_id');
    }

    public function documentoEstabelecimento(Estabelecimento $estab): string
    {
        $documento = $estab->pessoa_tipo === 'juridica'
            ? ($estab->cnpj ?? '')
            : ($estab->cpf ?? '');

        return $this->formatarDocumentoConsulta($documento);
    }

    public function extrairSafepayIdDoResultado(?array $resultado): ?string
    {
        if (! is_array($resultado)) {
            return null;
        }

        $candidatos = [
            data_get($resultado, 'safepay_id'),
            data_get($resultado, 'etapa_safepay.safepay_id'),
            data_get($resultado, 'detalhe.safepay_id'),
        ];

        foreach ($candidatos as $valor) {
            if (filled($valor)) {
                return (string) $valor;
            }
        }

        return null;
    }

    public function formatarDocumentoConsulta(string $documento): string
    {
        $digits = preg_replace('/\D/', '', $documento);

        if (strlen($digits) === 11) {
            return $this->formatarCpf($digits);
        }

        if (strlen($digits) === 14) {
            return $this->formatarCnpj($digits);
        }

        throw new RuntimeException('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');
    }

    // ----------------------------------------------------------------
    // Preview dos dados enviados à automação (confirmação antes de iniciar)
    // ----------------------------------------------------------------
    public function previewConfirmacao(Estabelecimento $estab): array
    {
        $estab->loadMissing('plano');

        $payload = $this->montarPayload($estab, '000000');
        $dados   = $payload['dados'];
        $avisos  = [];

        if (blank($estab->webmail_email)) {
            $avisos[] = 'E-mail da plataforma (@express.app.br) não configurado.';
        }

        if (blank($estab->webmail_senha)) {
            $avisos[] = 'Senha do webmail não disponível — configure na aba E-mail.';
        }

        if (blank($dados['cpf_cnpj'])) {
            $avisos[] = 'CPF/CNPJ não informado.';
        }

        if (blank($dados['email'])) {
            $avisos[] = 'E-mail para cadastro no PagBank não informado.';
        }

        if (blank($dados['promocao'])) {
            $avisos[] = 'Plano sem código Força de Vendas (codigo_fv).';
        }

        if (blank($dados['segmento'])) {
            $avisos[] = 'Segmento não informado.';
        }

        if (blank($dados['faturamento'])) {
            $avisos[] = 'Faturamento mensal não informado.';
        }

        if (blank($dados['cep']) || blank($dados['endereco'])) {
            $avisos[] = 'Endereço incompleto (CEP ou logradouro).';
        }

        if ($estab->pessoa_tipo === 'juridica' && blank($dados['razao_social'] ?? null)) {
            $avisos[] = 'Razão social não informada.';
        }

        if (blank($dados['celular']) || ! DocumentoBrasil::celularValido($dados['celular'])) {
            $avisos[] = 'Celular obrigatório com DDD + 9 dígitos (ex: 62992777240).';
        }

        if ($estab->pessoa_tipo === 'juridica') {
            if (blank($dados['nome_fantasia'])) {
                $avisos[] = 'Nome fantasia não informado (use a razão social se não houver).';
            }

            if (blank($dados['cpf_socio']) || ! DocumentoBrasil::cpfValido($dados['cpf_socio'])) {
                $avisos[] = 'CPF do sócio/representante inválido ou não informado.';
            }

            if (blank($dados['nome_socio'])) {
                $avisos[] = 'Nome do sócio/representante não informado.';
            }

            if (blank($dados['nascimento'])) {
                $avisos[] = 'Data de nascimento do sócio não informada.';
            }
        }

        if (blank($payload['fv_usuario']) || blank($payload['fv_senha'])) {
            $avisos[] = 'Credenciais do portal FV não configuradas no .env.';
        }

        if (blank($payload['webmail_url'])) {
            $avisos[] = 'URL do webmail (AUTOMACAO_WEBMAIL_URL) não configurada.';
        }

        $secaoIdentificacao = [
            ['label' => 'Tipo', 'value' => $estab->pessoa_tipo === 'juridica' ? 'Pessoa Jurídica' : 'Pessoa Física'],
            ['label' => 'CPF/CNPJ', 'value' => $dados['cpf_cnpj'] ?: '—'],
        ];

        if ($estab->pessoa_tipo === 'juridica') {
            $secaoIdentificacao[] = ['label' => 'Razão social', 'value' => $dados['razao_social'] ?? '—'];
            $secaoIdentificacao[] = ['label' => 'Nome fantasia', 'value' => $dados['nome_fantasia'] ?? '—'];
            $secaoIdentificacao[] = ['label' => 'CPF do sócio', 'value' => $dados['cpf_socio'] ?? '—'];
            $secaoIdentificacao[] = ['label' => 'Nome do sócio', 'value' => $dados['nome_socio'] ?? '—'];
            $secaoIdentificacao[] = ['label' => 'Nascimento do sócio', 'value' => $dados['nascimento'] ?? '—'];
        }

        $secaoContato = [
            ['label' => 'E-mail PagBank', 'value' => $dados['email'] ?: '—', 'destaque' => true],
            ['label' => 'E-mail original (redirecionamento)', 'value' => $estab->email ?: '—'],
            ['label' => 'Celular', 'value' => $this->formatarTelefone($dados['celular'])],
            ['label' => 'Telefone', 'value' => $this->formatarTelefone($dados['telefone'])],
        ];

        $secaoEndereco = [
            ['label' => 'CEP', 'value' => $this->formatarCep($dados['cep'])],
            ['label' => 'Endereço', 'value' => trim(($dados['endereco'] ?? '').', '.($dados['numero'] ?? ''))],
            ['label' => 'Complemento', 'value' => $dados['complemento'] ?: '—'],
            ['label' => 'Bairro', 'value' => $dados['bairro'] ?: '—'],
            ['label' => 'Estado', 'value' => $dados['estado'] ?: '—'],
        ];

        $secaoPlano = [
            ['label' => 'Plano', 'value' => $estab->plano?->nome ?: '—'],
            ['label' => 'Código FV (promoção)', 'value' => $dados['promocao'] ?: '—', 'destaque' => true],
            ['label' => 'Segmento', 'value' => $dados['segmento'] ?: '—'],
            ['label' => 'Faturamento mensal', 'value' => $dados['faturamento'] ?: '—'],
            ['label' => 'Tipo de link', 'value' => $dados['tipo_link'] ?? 'Link Mobile'],
        ];

        $secaoWebmail = [
            ['label' => 'URL do webmail', 'value' => $payload['webmail_url'] ?: '—'],
            ['label' => 'Usuário webmail', 'value' => $payload['webmail_usuario'] ?: '—', 'destaque' => true],
            ['label' => 'Senha webmail', 'value' => filled($estab->webmail_senha) ? '•••••••• (configurada)' : '—'],
            ['label' => 'Senha PagBank (6 dígitos)', 'value' => 'Será gerada automaticamente ao confirmar'],
        ];

        return [
            'valido'  => empty($avisos),
            'avisos'  => $avisos,
            'secoes'  => [
                ['titulo' => 'Identificação', 'campos' => $secaoIdentificacao],
                ['titulo' => 'Contato', 'campos' => $secaoContato],
                ['titulo' => 'Endereço', 'campos' => $secaoEndereco],
                ['titulo' => 'Plano e segmento', 'campos' => $secaoPlano],
                ['titulo' => 'E-mail e senha', 'campos' => $secaoWebmail],
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Monta o payload com os dados do estabelecimento
    // ----------------------------------------------------------------
    private function montarPayload(Estabelecimento $estab, string $senha6): array
    {
        $cpfCnpj = $estab->pessoa_tipo === 'juridica'
            ? $this->formatarCnpj($estab->cnpj)
            : $this->formatarCpf($estab->cpf);

        // Usa o e-mail da plataforma (@express.app.br) para o cadastro no PagBank.
        // O e-mail original do cliente (hotmail/gmail) é só para redirecionamento interno.
        $emailPagBank = $estab->webmail_email ?: $estab->email;

        $dados = [
            'cpf_cnpj'        => $cpfCnpj,
            'email'           => $emailPagBank,
            'email_confirmar' => $emailPagBank,
            'celular'         => preg_replace('/\D/', '', $estab->celular ?? ''),
            'telefone'        => preg_replace('/\D/', '', $estab->telefone ?? ''),
            'url_site'        => '',
            'faturamento'     => $this->mapearFaturamento($estab),
            'cep'             => preg_replace('/\D/', '', $estab->cep ?? ''),
            'endereco'        => $estab->endereco ?? '',
            'bairro'          => $estab->bairro ?? '',
            'numero'          => $this->numeroAutomacao($estab),
            'complemento'     => $estab->complemento ?? '',
            'estado'          => $estab->uf ?? '',
            'segmento'        => $this->mapearSegmento($estab),
            'tipo_link'       => 'Link Mobile',
            'promocao'        => $estab->plano?->codigo_fv ?? '',
        ];

        if ($estab->pessoa_tipo === 'juridica') {
            $dados = array_merge($dados, [
                'razao_social'  => $this->razaoSocialAutomacao($estab),
                'nome_fantasia' => $this->nomeFantasiaAutomacao($estab),
                'cpf_socio'     => $this->formatarCpf($estab->rep_cpf ?? $estab->cpf ?? ''),
                'nascimento'    => $estab->rep_data_nascimento
                    ? $estab->rep_data_nascimento->format('d/m/Y')
                    : ($estab->data_nascimento ? $estab->data_nascimento->format('d/m/Y') : ''),
                'nome_socio'    => $estab->rep_nome ?? $estab->nome_completo ?? '',
            ]);
        }

        return [
            'estabelecimento_id'  => $estab->id,
            'dados'               => $dados,
            'fv_usuario'          => PlatformSettings::automacaoFvUsuario() ?? '',
            'fv_senha'            => PlatformSettings::automacaoFvSenha() ?? '',
            'webmail_url'         => PlatformSettings::automacaoWebmailUrl() ?? '',
            // Email e senha do webmail vêm do cadastro do estabelecimento na plataforma
            'webmail_usuario'     => $estab->webmail_email ?? '',
            'webmail_senha'       => $estab->webmail_senha ?? '',
            'senha_6'             => $senha6,
            'headless'            => config('automacao.headless', true),
            'aguardar_email_seg'  => config('automacao.aguardar_email_seg', 90),
        ];
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------
    private function http(int $timeout = 15): PendingRequest
    {
        $retries = (int) config('automacao.api_retry_times', 3);
        $sleepMs = (int) config('automacao.api_retry_sleep_ms', 1000);

        return Http::timeout($timeout)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->retry($retries, $sleepMs, fn (\Throwable $e) => self::erroRedeTransitario($e));
    }

    private function formatarCpf(?string $cpf): string
    {
        $n = preg_replace('/\D/', '', $cpf ?? '');
        if (strlen($n) === 11) {
            return substr($n, 0, 3).'.'.substr($n, 3, 3).'.'.substr($n, 6, 3).'-'.substr($n, 9);
        }

        return $cpf ?? '';
    }

    private function formatarCnpj(?string $cnpj): string
    {
        $n = preg_replace('/\D/', '', $cnpj ?? '');
        if (strlen($n) === 14) {
            return substr($n, 0, 2).'.'.substr($n, 2, 3).'.'.substr($n, 5, 3)
                .'/'.substr($n, 8, 4).'-'.substr($n, 12);
        }

        return $cnpj ?? '';
    }

    private function mapearFaturamento(Estabelecimento $estab): string
    {
        // Usa o faturamento cadastrado no estabelecimento, com fallback padrão
        return $estab->faturamento_mensal ?: 'De R$ 1 mil até R$ 5 mil';
    }

    private function mapearSegmento(Estabelecimento $estab): string
    {
        // Os segmentos agora são cadastrados com os nomes exatos do portal PagBank FV
        // Retorna direto o campo segmento, com fallback para "Outras atividades empresariais"
        return filled($estab->segmento) ? $estab->segmento : 'Outras atividades empresariais';
    }

    private function numeroAutomacao(Estabelecimento $estab): string
    {
        $numero = trim((string) ($estab->numero ?? ''));

        if ($numero === '' || in_array(strtoupper($numero), ['00', 'S/N', 'SN', 'S N'], true)) {
            return '00';
        }

        return $numero;
    }

    private function nomeFantasiaAutomacao(Estabelecimento $estab): string
    {
        $fantasia = $this->valorLimpo($estab->nome_fantasia);
        if ($fantasia !== '') {
            return $fantasia;
        }

        return $this->valorLimpo($estab->razao_social)
            ?: $this->valorLimpo($estab->nome_completo);
    }

    private function razaoSocialAutomacao(Estabelecimento $estab): string
    {
        $razao = $this->valorLimpo($estab->razao_social);
        if ($razao !== '') {
            return $razao;
        }

        // Sem razão social válida cai para nome fantasia / nome completo,
        // evitando enviar valores inválidos como a string "null" ao PagBank.
        return $this->valorLimpo($estab->nome_fantasia)
            ?: $this->valorLimpo($estab->nome_completo);
    }

    /**
     * Normaliza strings vindas do banco descartando lixo como "null"/"NULL".
     */
    private function valorLimpo(?string $valor): string
    {
        $valor = trim((string) ($valor ?? ''));

        if ($valor === '' || in_array(strtolower($valor), ['null', 'nulo', 'n/a', 'na', '-'], true)) {
            return '';
        }

        return $valor;
    }

    private function formatarTelefone(?string $numero): string
    {
        $n = preg_replace('/\D/', '', $numero ?? '');
        if (strlen($n) === 11) {
            return '('.substr($n, 0, 2).') '.substr($n, 2, 5).'-'.substr($n, 7);
        }
        if (strlen($n) === 10) {
            return '('.substr($n, 0, 2).') '.substr($n, 2, 4).'-'.substr($n, 6);
        }

        return filled($numero) ? $numero : '—';
    }

    private function formatarCep(?string $cep): string
    {
        $n = preg_replace('/\D/', '', $cep ?? '');
        if (strlen($n) === 8) {
            return substr($n, 0, 5).'-'.substr($n, 5);
        }

        return filled($cep) ? $cep : '—';
    }
}
