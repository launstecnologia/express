<?php

return [
    /** Domínio base da plataforma (ex: expresspay.com.br) */
    'base_domain' => env('TENANT_BASE_DOMAIN', 'expresspay.com.br'),

    /** Hosts que representam o painel admin global (sem tenant) */
    'platform_hosts' => array_filter(array_map('trim', explode(',', env('TENANT_PLATFORM_HOSTS', 'localhost,127.0.0.1')))),

    /** Criar subdomínio no DirectAdmin ao cadastrar marketplace */
    'provision_subdomain' => (bool) env('TENANT_PROVISION_SUBDOMAIN', false),

    /** Em local: query string para simular tenant em localhost (ex: ?tenant=meu-slug) */
    'local_query' => env('TENANT_LOCAL_QUERY', 'tenant'),

    /** IP público do servidor (validação DNS de domínio próprio) */
    'server_ip' => env('TENANT_SERVER_IP'),

    /** E-mail para registro Let's Encrypt / Certbot */
    'certbot_email' => env('TENANT_CERTBOT_EMAIL', env('MAIL_FROM_ADDRESS', 'admin@express.app.br')),

    /** Habilita execução automática do script de SSL pelo painel admin */
    'ssl_auto_provision' => (bool) env('TENANT_SSL_AUTO_PROVISION', true),
];
