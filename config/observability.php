<?php
declare(strict_types=1);

return [
    'monitor_backup' => env('MONITOR_BACKUP_ENABLED', false),
    'health' => true,
    'system_report' => true,
    'metrics' => 'prometheus',
    'prometheus_enabled' => env('PROMETHEUS_ENABLED', true),
    'prometheus_path' => env('PROMETHEUS_PATH', '/metrics'),
];
