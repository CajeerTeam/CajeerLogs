<?php
declare(strict_types=1);

return [
    'rate_limit' => env('API_RATE_LIMIT_PER_MINUTE', '600'),
    'rate_search' => env('API_RATE_SEARCH_PER_MINUTE', '120'),
    'rate_analytics' => env('API_RATE_ANALYTICS_PER_MINUTE', '30'),
    'rate_export' => env('API_RATE_EXPORT_PER_MINUTE', '10'),
    'rate_admin_write' => env('API_RATE_ADMIN_WRITE_PER_MINUTE', '60'),
    'rate_burst_percent' => env('API_RATE_BURST_PERCENT', '100'),
    'search_timeout_ms' => env('SEARCH_TIMEOUT_MS', '5000'),
    'prefix' => env('API_PREFIX', '/api/v1'),
    'openapi' => env('OPENAPI_PATH', 'api/openapi.yaml'),
];
