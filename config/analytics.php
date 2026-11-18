<?php
declare(strict_types=1);

return [
    'default'=>env_raw('ANALYTICS_DRIVER','postgres'),
    'recommended_high_load'=>'clickhouse',
    'drivers'=>['postgres','clickhouse'],
    'clickhouse'=>[
        'dsn'=>env_raw('CLICKHOUSE_DSN','https://127.0.0.1:8443'),
        'database'=>env_raw('CLICKHOUSE_DATABASE','cajeer_logs'),
        'username'=>env_raw('CLICKHOUSE_USERNAME','cajeer_logs'),
        'password'=>env_raw('CLICKHOUSE_PASSWORD',''),
        'timeout_seconds'=>env_raw('CLICKHOUSE_TIMEOUT_SECONDS','5'),
        'allow_insecure'=>env('CLICKHOUSE_ALLOW_INSECURE',false),
        'ca_file'=>env_raw('CLICKHOUSE_TLS_CA_FILE',''),
    ],
];
