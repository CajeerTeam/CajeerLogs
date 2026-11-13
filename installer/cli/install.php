#!/usr/bin/env php
<?php
declare(strict_types=1);
$command = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/cajeer', 'install', ...array_slice($argv, 1)];
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
exit(is_resource($process) ? proc_close($process) : 1);
