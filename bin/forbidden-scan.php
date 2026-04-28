#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$forbidden = ['Mint' . 'lify', 'Git' . 'Book', 'Bot' . 'Host', 'docs.cajeer.ru' . '/logs'];
$scanFiles = ['README.md', '.env.example', '.nginx.conf', 'SECURITY.md', 'CONTRIBUTING.md', 'openapi.yaml', '.github/PULL_REQUEST_TEMPLATE.md'];
foreach (['wiki', 'app', 'bin', 'clients', '.github/workflows'] as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && preg_match('/\.(md|php|py|yml|yaml|sh)$/i', $file->getFilename())) {
            $scanFiles[] = str_replace($root . '/', '', $file->getPathname());
        }
    }
}
foreach (array_unique($scanFiles) as $file) {
    $content = (string)@file_get_contents($root . '/' . $file);
    foreach ($forbidden as $needle) {
        if (str_contains($content, $needle)) {
            echo '[СБОЙ] запрещённое упоминание ' . $needle . ' в ' . $file . PHP_EOL;
            $failed++;
        }
    }
}
if ($failed === 0) { echo '[ОК]   Запрещённых упоминаний не найдено.' . PHP_EOL; }
exit($failed > 0 ? 1 : 0);
