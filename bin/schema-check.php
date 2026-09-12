#!/usr/bin/env php
<?php
declare(strict_types=1);

$binary = PHP_BINARY;
$entry = __DIR__ . '/cajeer';
$command = 'migrate';
passthru(escapeshellarg($binary) . ' ' . escapeshellarg($entry) . ' ' . escapeshellarg($command), $code);
exit((int) $code);
