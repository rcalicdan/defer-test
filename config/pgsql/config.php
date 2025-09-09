<?php

return [
    'connection' => [
        'host' => $_ENV['DB_HOST_PGSQL'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['DB_PORT_PGSQL'] ?? $_ENV['DB_PORT'] ?? 5432),
        'database' => $_ENV['DB_DATABASE_PGSQL'] ?? $_ENV['DB_DATABASE'] ?? 'postgres',
        'username' => $_ENV['DB_USERNAME_PGSQL'] ?? $_ENV['DB_USERNAME'] ?? 'postgres',
        'password' => $_ENV['DB_PASSWORD_PGSQL'] ?? $_ENV['DB_PASSWORD'] ?? '',
        'options' => [
            'connect_timeout' => 10,
            'application_name' => 'fiber-async-app',
        ],
    ],

    'pool_size' => (int) ($_ENV['DB_POOL_SIZE'] ?? 20),
];
