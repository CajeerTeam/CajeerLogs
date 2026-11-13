<?php
declare(strict_types=1);

return [
    'rate_limit' => env('API_RATE_LIMIT_PER_MINUTE', '600'),
    'prefix' => env('API_PREFIX', '/api/v1'),
    'openapi' => env('OPENAPI_PATH', 'api/openapi.yaml'),
];
