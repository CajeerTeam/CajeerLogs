#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/bin/_env_guard.php';

use CajeerLogs\Security;

$options = getopt('', ['url:', 'token:', 'signed', 'project::', 'bot::', 'environment::', 'timeout::']);
$url = trim((string)($options['url'] ?? ''));
$token = trim((string)($options['token'] ?? ''));
$signed = array_key_exists('signed', $options);
$timeout = max(1, min(30, (int)($options['timeout'] ?? 10)));

if ($url === '' || $token === '') {
    fwrite(STDERR, "Использование: php bin/ingest-smoke.php --url=https://logs.example.com/api/v1/ingest --token=RAW_TOKEN [--signed] [--project=ExampleProject] [--bot=ExampleBot] [--environment=production]\n");
    exit(1);
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "Некорректный URL ingest API.\n");
    exit(1);
}

$payload = [
    'logs' => [[
        'level' => 'INFO',
        'project' => (string)($options['project'] ?? 'ExampleProject'),
        'bot' => (string)($options['bot'] ?? 'ExampleBot'),
        'environment' => (string)($options['environment'] ?? 'production'),
        'logger' => 'ingest-smoke',
        'message' => 'Проверочное событие ingest smoke test',
        'context' => ['source' => 'bin/ingest-smoke.php'],
        'created_at' => gmdate('c'),
    ]],
];
$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($body)) {
    fwrite(STDERR, "Не удалось сформировать JSON payload.\n");
    exit(1);
}

$headers = [
    'Content-Type: application/json',
    'X-Log-Token: ' . $token,
];
if ($signed) {
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', Security::signatureCanonicalPayload($timestamp, $nonce, $body), $token);
    $headers[] = 'X-Log-Timestamp: ' . $timestamp;
    $headers[] = 'X-Log-Nonce: ' . $nonce;
    $headers[] = 'X-Log-Signature: ' . $signature;
}

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
    ],
]);
$response = @file_get_contents($url, false, $context);
$statusLine = $http_response_header[0] ?? '';
$code = 0;
if (is_string($statusLine) && preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
    $code = (int)$m[1];
}
$decoded = is_string($response) ? json_decode($response, true) : null;
$ok = $code === 200 && is_array($decoded) && (($decoded['ok'] ?? false) === true) && ((int)($decoded['inserted'] ?? 0) > 0);

echo 'HTTP: ' . ($code ?: 'нет ответа') . PHP_EOL;
echo 'Ответ: ' . (is_string($response) ? $response : 'нет ответа') . PHP_EOL;
if (!$ok) {
    fwrite(STDERR, "Smoke test не прошёл. Проверь URL, токен, подпись и сетевой доступ.\n");
    exit(2);
}

echo "Smoke test прошёл: событие принято.\n";
