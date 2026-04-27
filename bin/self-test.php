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

$docsJsonPath = $root . '/docs.json';
$docs = is_file($docsJsonPath) ? json_decode((string)file_get_contents($docsJsonPath), true) : null;
$check('Mintlify docs.json существует и валиден', is_array($docs), $docsJsonPath);
$check('Mintlify docs.json содержит navigation', is_array($docs['navigation'] ?? null));
$check('OpenAPI-спецификация существует', is_file($root . '/openapi.yaml'));
$openapi = is_file($root . '/openapi.yaml') ? (string)file_get_contents($root . '/openapi.yaml') : '';
$check('OpenAPI описывает POST /api/v1/ingest', str_contains($openapi, '/api/v1/ingest:') && str_contains($openapi, 'post:'));

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

$ci = is_file($root . '/.github/workflows/ci.yml') ? (string)file_get_contents($root . '/.github/workflows/ci.yml') : '';
$check('CI запускает self-test', str_contains($ci, 'php bin/self-test.php'));
$check('CI проверяет Mintlify', str_contains($ci, 'mint validate') && str_contains($ci, 'mint broken-links'));

exit($failed > 0 ? 1 : 0);
