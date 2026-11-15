<?php
declare(strict_types=1);

return [
    'rbac' => true,
    'permission_scopes' => true,
    'api_token_scopes' => true,
    'audit_log' => true,
    'two_factor' => true,
    'totp_key' => env_raw('SECURITY_TOTP_KEY') ?: env_raw('SECURITY_HMAC_SECRET'),
    'session_ttl_seconds' => env('SECURITY_SESSION_TTL_SECONDS', '28800'),
    'require_admin_totp' => env('SECURITY_2FA_REQUIRED_FOR_ADMINS', false),
    'rate_limiting' => true,
    'csrf' => true,
    'security_headers' => true,
    'signed_payloads' => true // Ingest supports optional HMAC; requirement is configured in ingest.php.
];
