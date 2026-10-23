<?php

return [
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],

    'ppid' => [
        'url' => env('PPID_API_URL', 'https://api.ppid.com.br'),
        'email' => env('PPID_EMAIL'),
        'senha' => env('PPID_SENHA'),
        'limite_mensal' => (int) env('PPID_LIMITE_MENSAL', 490),
    ],

    'didit' => [
        'key' => env('DIDIT_API_KEY'),
        'base_url' => env('DIDIT_API_URL', 'https://verification.didit.me'),
    ],

    'brasilapi' => [
        'url' => env('BRASILAPI_URL', 'https://brasilapi.com.br/api'),
    ],
];
