<?php

return [
    'connection' => [
        'host' => $_ENV['DB_HOST_MYSQL'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['DB_PORT_MYSQL'] ?? $_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_DATABASE_MYSQL'] ?? $_ENV['DB_DATABASE'] ?? 'test',
        'username' => $_ENV['DB_USERNAME_MYSQL'] ?? $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD_MYSQL'] ?? $_ENV['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
        'socket' => $_ENV['DB_SOCKET_MYSQL'] ?? $_ENV['DB_SOCKET'] ?? '',
    ],

    'pool_size' => (int) ($_ENV['DB_POOL_SIZE'] ?? 20),
];
