<?php
declare(strict_types=1);
return [
    'default' => 'pgsql',
    'max_jobs' => env_raw('QUEUE_MAX_JOBS', '1000'),
];
