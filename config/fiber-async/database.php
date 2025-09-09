<?php

return [
    'default' => $_ENV['DB_CONNECTION'] ?? 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => match ($path = $_ENV['DB_SQLITE_PATH'] ?? null) {
                ':memory:' => 'file::memory:?cache=shared',
                null => __DIR__.'/../database/database.sqlite',
                default => $path,
            },
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST_MYSQL'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT_MYSQL'] ?? $_ENV['DB_PORT'] ?? 3306),
            'database' => $_ENV['DB_DATABASE_MYSQL'] ?? $_ENV['DB_DATABASE'] ?? 'test',
            'username' => $_ENV['DB_USERNAME_MYSQL'] ?? $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD_MYSQL'] ?? $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => $_ENV['DB_HOST_PGSQL'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT_PGSQL'] ?? $_ENV['DB_PORT'] ?? 5432),
            'database' => $_ENV['DB_DATABASE_PGSQL'] ?? $_ENV['DB_DATABASE'] ?? 'test',
            'username' => $_ENV['DB_USERNAME_PGSQL'] ?? $_ENV['DB_USERNAME'] ?? 'postgres',
            'password' => $_ENV['DB_PASSWORD_PGSQL'] ?? $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
            ],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => $_ENV['DB_HOST_SQLSRV'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT_SQLSRV'] ?? $_ENV['DB_PORT'] ?? 1433),
            'database' => $_ENV['DB_DATABASE_SQLSRV'] ?? $_ENV['DB_DATABASE'] ?? 'test',
            'username' => $_ENV['DB_USERNAME_SQLSRV'] ?? $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD_SQLSRV'] ?? $_ENV['DB_PASSWORD'] ?? '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],

        'oracle' => [
            'driver' => 'oci',
            'host' => $_ENV['DB_HOST_ORACLE'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT_ORACLE'] ?? $_ENV['DB_PORT'] ?? 1521),
            'database' => $_ENV['DB_DATABASE_ORACLE'] ?? $_ENV['DB_DATABASE'] ?? 'xe',
            'username' => $_ENV['DB_USERNAME_ORACLE'] ?? $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD_ORACLE'] ?? $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'AL32UTF8',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],

        'ibm' => [
            'driver' => 'ibm',
            'host' => $_ENV['DB_HOST_IBM'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT_IBM'] ?? $_ENV['DB_PORT'] ?? 50000),
            'database' => $_ENV['DB_DATABASE_IBM'] ?? $_ENV['DB_DATABASE'] ?? 'test',
            'username' => $_ENV['DB_USERNAME_IBM'] ?? $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD_IBM'] ?? $_ENV['DB_PASSWORD'] ?? '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],
    ],

    'pool_size' => (int) ($_ENV['DB_POOL_SIZE'] ?? 20),
];
