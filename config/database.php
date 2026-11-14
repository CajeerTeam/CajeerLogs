<?php
declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'host' => env_raw('DB_HOST', '127.0.0.1'),
    'port' => env_raw('DB_PORT', '5432'),
    'database' => env_raw('DB_DATABASE', 'cajeer_logs'),
    'username' => env_raw('DB_USERNAME', 'cajeer_logs'),
    'password' => env_raw('DB_PASSWORD', ''),
    'schema' => env_raw('DB_SCHEMA', 'public'),
    'sslmode' => env_raw('DB_SSLMODE', 'prefer'),
    'sslrootcert' => env_raw('DB_SSLROOTCERT', ''),
    'connect_timeout' => env_raw('DB_CONNECT_TIMEOUT', '5'),
    'statement_timeout_ms' => env_raw('DB_STATEMENT_TIMEOUT_MS', '30000'),
    'application_name' => env_raw('DB_APPLICATION_NAME', 'cajeerlogs'),
    'connect_attempts' => env_raw('DB_CONNECT_ATTEMPTS', '2'),
    'connect_retry_ms' => env_raw('DB_CONNECT_RETRY_MS', '250'),
    'idle_transaction_timeout_ms' => env_raw('DB_IDLE_TRANSACTION_TIMEOUT_MS', '60000'),
    'keepalives_idle' => env_raw('DB_KEEPALIVES_IDLE', '30'),
    'keepalives_interval' => env_raw('DB_KEEPALIVES_INTERVAL', '10'),
    'keepalives_count' => env_raw('DB_KEEPALIVES_COUNT', '3'),
    'tcp_user_timeout_ms' => env_raw('DB_TCP_USER_TIMEOUT_MS', '30000'),
    'lock_timeout_ms' => env_raw('DB_LOCK_TIMEOUT_MS', '10000'),
];
