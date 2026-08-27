<?php

return [
    'app' => [
        'name' => 'Meta Ads Manager',
        'base_url' => '',
        'timezone' => 'America/Sao_Paulo',
        'debug' => false,
    ],

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'NOME_DO_BANCO_PRINCIPAL',
        'username' => 'USUARIO_DO_BANCO_PRINCIPAL',
        'password' => 'SENHA_DO_BANCO_PRINCIPAL',
        'charset' => 'utf8mb4',
    ],

    'source_db' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'NOME_DO_BANCO_AREA_MEMBROS',
        'username' => 'USUARIO_DO_BANCO_AREA_MEMBROS',
        'password' => 'SENHA_DO_BANCO_AREA_MEMBROS',
        'charset' => 'utf8mb4',
    ],

    'meta' => [
        'graph_version' => 'v25.0',
        'graph_base_url' => 'https://graph.facebook.com',
        'default_time_increment' => 1,
        'default_sync_days_back' => 3,
        'sync_timeout_seconds' => 90,
    ],

    'attribution' => [
        'default_model' => 'last_touch',
        'valid_sale_statuses' => ['Completo', 'Aprovado'],
        'sync_days_back' => 365,
    ],

    'security' => [
        'admin_api_key' => 'TROQUE_POR_UMA_CHAVE_FORTE',
    ],

    'paths' => [
        'log_file' => __DIR__ . '/../logs/app.log',
    ],
];

