<?php
declare(strict_types=1);

return [
    'core' => 'GitHub Releases',
    'repository' => 'CajeerTeam/CajeerLogs',
    'channel' => env('UPDATE_CHANNEL', 'stable'),
    'modules_plugins_themes' => 'GitFlic Registry',
    'policy' => 'SemVer + LTS + security releases',
];
