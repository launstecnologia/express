<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Automação PagBank — Força de Vendas
    |--------------------------------------------------------------------------
    | URL e chave da API FastAPI (automacao/api.py) rodando no VPS.
    | Configure AUTOMACAO_API_URL e AUTOMACAO_API_KEY no .env.
    */
    'api_url' => env('AUTOMACAO_API_URL', 'http://127.0.0.1:8001'),
    'api_key' => env('AUTOMACAO_API_KEY'),

    /*
    | Credenciais do portal Força de Vendas PagBank
    */
    'fv_usuario' => env('AUTOMACAO_FV_USUARIO'),
    'fv_senha'   => env('AUTOMACAO_FV_SENHA'),

    /*
    | URL do webmail Roundcube (igual para todos os estabelecimentos)
    | Email e senha de cada estabelecimento ficam no banco (webmail_email / webmail_senha)
    */
    'webmail_url' => env('AUTOMACAO_WEBMAIL_URL'),

    /*
    | Tempo de espera (em segundos) para o email de confirmação chegar na caixa de entrada
    */
    'aguardar_email_seg' => (int) env('AUTOMACAO_AGUARDAR_EMAIL_SEG', 90),

    /*
    | Configuração do job de polling (AutomacaoPagBankJob)
    | Intervalo e máximo de tentativas para consultar o status do job na API Python
    */
    'polling_intervalo_seg' => (int) env('AUTOMACAO_POLLING_INTERVALO', 20),
    'polling_max_tentativas' => (int) env('AUTOMACAO_POLLING_MAX', 50), // 50 * 20s ≈ 16 minutos

    /*
    | Retentativas em falhas transitórias de rede (DNS, timeout, connection refused)
    */
    'api_retry_times' => (int) env('AUTOMACAO_API_RETRY_TIMES', 3),
    'api_retry_sleep_ms' => (int) env('AUTOMACAO_API_RETRY_SLEEP_MS', 1000),

    /*
    | Rodar Chrome em modo headless (true em produção, false para debug)
    */
    'headless' => (bool) env('AUTOMACAO_HEADLESS', true),
];
