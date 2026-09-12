# Security Policy

## Поддерживаемые версии

| Версия | Статус |
|---|---|
| 0.1.x | Preview, security fixes best-effort |
| 1.x LTS | Планируется |

## Сообщение об уязвимости

Не публикуйте детали уязвимости в публичных issue. Передайте описание, шаги воспроизведения, impact, affected versions и рекомендации по исправлению через приватный security-канал проекта.

## Базовая модель безопасности

- RBAC и permission scopes.
- API token scopes.
- Audit log.
- 2FA для административных учетных записей.
- Rate limiting.
- CSRF protection.
- Security headers.
- Signed payloads для webhooks и Event API.
- Redaction секретов в логах.
