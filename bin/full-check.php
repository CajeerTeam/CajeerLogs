#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$commands = [
    ['php', 'bin/self-test.php'],
    ['php', 'bin/wiki-check.php'],
    ['php', 'bin/schema-check.php'],
    ['php', 'bin/release-check.php'],
    ['php', 'bin/forbidden-scan.php'],
];
$failed = 0;
foreach ($commands as $cmd) {
    echo '==> ' . implode(' ', $cmd) . PHP_EOL;
    $line = implode(' ', array_map('escapeshellarg', $cmd));
    passthru('cd ' . escapeshellarg($root) . ' && ' . $line, $code);
    if ($code !== 0) { $failed++; }
}
exit($failed > 0 ? 1 : 0);
