#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Auth;
use CajeerLogs\Redactor;
use CajeerLogs\Security;

$root = dirname(__DIR__);
$failed = 0;

$check = static function (string $name, bool $ok, string $message = '') use (&$failed): void {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
};

$check('safeInternalPath принимает внутренний путь', Security::safeInternalPath('/logs?level=ERROR', '/') === '/logs?level=ERROR');
$check('safeInternalPath запрещает //host', Security::safeInternalPath('//evil.example', '/') === '/');
$check('safeInternalPath запрещает управляющие символы', Security::safeInternalPath("/logs\nLocation: //evil", '/') === '/');

$redacted = Redactor::redactString('Authorization: Bearer secret-token password=supersecret ghp_abcdefghijklmnopqrstuvwxyz');
$check('Redactor скрывает Authorization', str_contains($redacted, '[REDACTED]') && !str_contains($redacted, 'supersecret'));
$redactedContext = Redactor::redactMixed(['token' => 'abc', 'nested' => ['api_key' => 'def'], 'safe' => 'ok']);
$check('Redactor скрывает чувствительные context-ключи', ($redactedContext['token'] ?? '') === '[REDACTED]' && ($redactedContext['nested']['api_key'] ?? '') === '[REDACTED]' && ($redactedContext['safe'] ?? '') === 'ok');

$body = '{"logs":[{"message":"ok"}]}';
$timestamp = (string)time();
$nonce = 'nonce-' . bin2hex(random_bytes(8));
$canonical = Security::signatureCanonicalPayload($timestamp, $nonce, $body);
$signature = hash_hmac('sha256', $canonical, 'raw-token');
[$signatureOk, $signatureReason] = Security::verifyRequestSignature('raw-token', $body, $timestamp, $nonce, $signature);
$check('HMAC-подпись проходит с корректными данными', $signatureOk, (string)$signatureReason);
[$badSignatureOk] = Security::verifyRequestSignature('raw-token', $body, $timestamp, $nonce, str_repeat('0', 64));
$check('HMAC-подпись отклоняет неверную подпись', !$badSignatureOk);

$authReflection = new ReflectionClass(Auth::class);
$roles = $authReflection->getReflectionConstant('ROLE_PERMISSIONS')->getValue();
$viewerPerms = $roles['viewer'] ?? [];
$check('RBAC viewer не может управлять токенами', !in_array('bots.manage', $viewerPerms, true));
$check('RBAC operator может управлять оповещениями', in_array('alerts.manage', $roles['operator'] ?? [], true));

$wikiUrl = 'https://github.com/CajeerTeam/CajeerLogs/wiki';
$envExample = is_file($root . '/.env.example') ? (string)file_get_contents($root . '/.env.example') : '';
$readme = is_file($root . '/README.md') ? (string)file_get_contents($root . '/README.md') : '';
$ci = is_file($root . '/.github/workflows/ci.yml') ? (string)file_get_contents($root . '/.github/workflows/ci.yml') : '';
$check('DOCS_URL указывает на GitHub Wiki', str_contains($envExample, 'DOCS_URL=' . $wikiUrl));
$check('INGEST_REQUIRE_SIGNATURE включён в .env.example', str_contains($envExample, 'INGEST_REQUIRE_SIGNATURE=true'));
$check('README ссылается на GitHub Wiki', str_contains($readme, $wikiUrl));
$check('CI запускает wiki-check', str_contains($ci, 'php bin/wiki-check.php'));
$check('CI запускает schema-check', str_contains($ci, 'php bin/schema-check.php'));
$check('CI не содержит устаревшие команды проверки документации', !str_contains($ci, 'mint' . ' validate') && !str_contains($ci, 'mint' . ' broken-links'));
$check('OpenAPI-спецификация существует', is_file($root . '/openapi.yaml'));
$openapi = is_file($root . '/openapi.yaml') ? (string)file_get_contents($root . '/openapi.yaml') : '';
$check('OpenAPI описывает POST /api/v1/ingest', str_contains($openapi, '/api/v1/ingest:') && str_contains($openapi, 'post:'));
$check('OpenAPI описывает фактический ответ 200/inserted', str_contains($openapi, "'200':") && str_contains($openapi, 'inserted:'));
$check('Wiki-исходники существуют', is_file($root . '/wiki/Home.md') && is_file($root . '/wiki/API.md') && is_file($root . '/wiki/Release-checklist.md') && is_file($root . '/wiki/Security.md') && is_file($root . '/wiki/Repository-settings.md'));
$check('workflow публикации Wiki существует', is_file($root . '/.github/workflows/wiki-publish.yml'));
$check('Wiki использует ASCII-имена файлов', count(glob($root . '/wiki/*.md') ?: []) >= 21 && !preg_grep('/[^A-Za-z0-9_\-.\/]/', glob($root . '/wiki/*.md') ?: []));
[$webhookOk] = Security::validateExternalWebhookUrl('https://127.0.0.1/hook');
$check('SSRF-защита блокирует loopback-webhook', !$webhookOk);
[$httpsOk] = Security::validateExternalWebhookUrl('http://example.com/hook');
$check('SSRF-защита требует HTTPS-webhook', !$httpsOk);

$schema = (string)file_get_contents($root . '/app/Internal/schema.pgsql.sql');
foreach ([
    'idx_bot_tokens_deleted',
    'idx_ingest_nonces_cleanup',
    'idx_incidents_status',
    'idx_aapanel_log_offsets_site',
    'idx_aapanel_offsets_rotation',
    'idx_incidents_muted_until',
    'idx_log_events_message_trgm',
    'idx_log_events_exception_trgm',
] as $indexName) {
    $check('schema содержит ' . $indexName, str_contains($schema, $indexName));
}

$check('schema содержит лимит событий ingest', str_contains($schema, 'events_limit_per_minute'));
$check('schema содержит лимит байт ingest', str_contains($schema, 'bytes_limit_per_minute'));
$check('Schema-check существует', is_file($root . '/bin/schema-check.php'));
$check('Ingest smoke test существует', is_file($root . '/bin/ingest-smoke.php'));
$check('Release-check существует', is_file($root . '/bin/release-check.php'));
$check('Версия ops-hardening установлена', trim((string)file_get_contents($root . '/VERSION')) === '0.8.2-ops-hardening');
[$ipv6WebhookOk] = Security::validateExternalWebhookUrl('https://[::1]/hook');
$check('SSRF-защита блокирует IPv6 loopback-webhook', !$ipv6WebhookOk);


exit($failed > 0 ? 1 : 0);
