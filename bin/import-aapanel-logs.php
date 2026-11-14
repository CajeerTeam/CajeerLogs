#!/usr/bin/env php
<?php
declare(strict_types=1);

$binary = PHP_BINARY;
$entry = __DIR__ . '/cajeer';
$command = 'import:aapanel';
passthru(escapeshellarg($binary) . ' ' . escapeshellarg($entry) . ' ' . escapeshellarg($command) . ' ' . implode(' ', array_map('escapeshellarg', array_slice($argv, 1))), $code);
exit((int) $code);
