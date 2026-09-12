<?php
declare(strict_types=1);

return [
    'health' => true,
    'system_report' => true,
    'diagnostics' => ['queue', 'cache', 'storage'],
    'metrics' => 'prometheus',
    'tracing' => 'opentelemetry'
];
