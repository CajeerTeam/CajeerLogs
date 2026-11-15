<?php
declare(strict_types=1);

return [
    'rbac' => true,
    'permission_scopes' => true,
    'api_token_scopes' => true,
    'audit_log' => true,
    'two_factor' => true,
    'totp_key' => env_raw('SECURITY_TOTP_KEY') ?: env_raw('SECURITY_HMAC_SECRET'),
    'totp_active_key' => env_raw('SECURITY_TOTP_ACTIVE_KEY'),
    'totp_previous_key' => env_raw('SECURITY_TOTP_PREVIOUS_KEY'),
    'required_totp_roles' => env_raw('SECURITY_2FA_REQUIRED_ROLES'),
    'required_totp_permissions' => env_raw('SECURITY_2FA_REQUIRED_PERMISSIONS'),
    'session_idle_seconds' => env('SECURITY_SESSION_IDLE_SECONDS', '0'),
    'reauth_seconds' => env('SECURITY_REAUTH_SECONDS', '0'),
    'session_ttl_seconds' => env('SECURITY_SESSION_TTL_SECONDS', '28800'),
    'require_admin_totp' => env('SECURITY_2FA_REQUIRED_FOR_ADMINS', false),
    'rate_limiting' => true,
    'csrf' => true,
    'security_headers' => true,
    'signed_payloads' => true // Ingest supports optional HMAC; requirement is configured in ingest.php.
];
