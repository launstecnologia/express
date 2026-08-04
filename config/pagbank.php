<?php

return [
    'token' => env('PAGBANK_TOKEN'),
    'client_id' => env('PAGBANK_CLIENT_ID'),
    'client_secret' => env('PAGBANK_CLIENT_SECRET'),
    'ambiente' => env('PAGBANK_AMBIENTE', 'producao'),
    'api_url' => env('PAGBANK_API_URL'),
    'pipefy_edi_url' => env('PAGBANK_PIPEFY_EDI_URL', 'https://app.pipefy.com/organizations/142456/interfaces/3668b8ed-d930-4bcf-8038-8a00d3ed6901'),
    'renovacao_dias_antecedencia' => (int) env('PAGBANK_RENOVACAO_DIAS', 7),

    /*
    |--------------------------------------------------------------------------
    | Chamado semanal Pipefy — replicação de token EDI (1xN)
    |--------------------------------------------------------------------------
    */
    'pipefy_edi' => [
        'page_url' => env(
            'PAGBANK_PIPEFY_EDI_PAGE_URL',
            'https://app.pipefy.com/organizations/142456/interfaces/3668b8ed-d930-4bcf-8038-8a00d3ed6901/pages/243d9728-5742-47d1-b791-ce04f061ac13'
        ),
        'email' => env('PAGBANK_PIPEFY_EDI_EMAIL', 'edi@express.app.br'),
        'desde' => env('PAGBANK_PIPEFY_EDI_DESDE', '2026-06-01'),
        'tipo_solicitacao' => env(
            'PAGBANK_PIPEFY_EDI_TIPO',
            'Replicação do token API EDI'
        ),
        'razao_social' => env('PAGBANK_PIPEFY_EDI_RAZAO', 'Expresspay Pagamentos Ltda'),
        'cnpj' => env('PAGBANK_PIPEFY_EDI_CNPJ', '22402704000123'),
        'telefone' => env('PAGBANK_PIPEFY_EDI_TELEFONE', ''),
        'representante' => env('PAGBANK_PIPEFY_EDI_REPRESENTANTE', 'Rosa Lúcia da Silva Neris'),
    ],
];
