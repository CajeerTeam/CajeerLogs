<?php
declare(strict_types=1);

return [
    'health' => true,
    'system_report' => true,
    'metrics' => 'prometheus',
    'prometheus_enabled' => env('PROMETHEUS_ENABLED', true),
    'prometheus_path' => env('PROMETHEUS_PATH', '/metrics'),
];
