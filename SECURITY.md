# Security Policy

## Поддерживаемые версии

| Версия | Статус |
|---|---|
| 0.1.2 | Public MVP; текущая версия |

## Сообщение об уязвимости

Не публикуйте детали уязвимости в публичных issue. Передайте описание, шаги воспроизведения, impact, affected versions и рекомендации по исправлению через приватный security-канал проекта.

## Базовая модель безопасности

- RBAC и permission scopes.
- API token scopes.
- Audit log.
- TOTP 2FA, одноразовые recovery-коды, обязательная настройка для администраторов при включённой политике.
- Зашифрованные TOTP-секреты, одноразовые временные интервалы; backup/restore с отзывом восстановленных credentials.
- Rate limiting.
- CSRF protection.
- Security headers.
- Signed payloads для webhooks и Event API.
- Redaction секретов в логах.

Эксплуатация, восстановление доступа, ограничения резервирования и мониторинг: [Security & Operations](docs/Security-Operations.md).
