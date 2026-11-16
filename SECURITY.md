# Security Policy

## Поддерживаемые версии

| Версия | Статус |
|---|---|
| 0.4.7 | HTTPS & Deployment Security; текущая версия (Public Preview до CI) |
| 0.4.5 | Rate Limiting & Abuse Protection; обновите до 0.4.7 |
| 0.4.4 | Audit Log & Accountability; обновите до 0.4.7 |
| 0.4.3 | Identity & 2FA Security; обновите до 0.4.7 |
| 0.4.2 | RBAC & Authorization; обновите до 0.4.7 |
| 0.4.1 | Admin Panel Release; обновите до 0.4.7 |
| 0.4.0 | Admin Panel Release; обновите до 0.4.7 |
| 0.3.9 | Token Management & Security; обновите до 0.4.7 |
| 0.3.8 | Project & Source Management; обновите до 0.4.7 |
| 0.3.7 | User & Access Management; обновите до 0.4.7 |
| 0.3.6 | Event Details & JSON Viewer; обновите до 0.4.7 |
| 0.3.3 | Dashboard & Analytics; обновите до 0.4.7 |
| 0.3.1 | Admin UI Foundation; предыдущая версия |
| 0.3.0 | Log Ingestion & Search Release; предыдущая версия |
| 0.2.9 | Pagination & Log Explorer |
| 0.2.5 | HMAC & Replay Protection |

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
- Signed payloads для webhooks и Event API; [HMAC v1/v2 для ingest](docs/HMAC-signature.md), обязательные политики, зашифрованные signing keys и транзакционная защита nonce.
- Redaction секретов в логах.

Эксплуатация, восстановление доступа, ограничения резервирования и мониторинг: [Security & Operations](docs/Security-Operations.md).
