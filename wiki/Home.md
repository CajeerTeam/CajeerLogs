# Cajeer Logs

Cajeer Logs — сервис для централизованного приёма, хранения и анализа журналов приложений, ботов, сайтов и инфраструктуры.

## Быстрые ссылки

- [Установка](Installation)
- [Настройка окружения](Env-configuration)
- [Развёртывание Nginx](Nginx-deployment)
- [PostgreSQL](PostgreSQL)
- [Cron-задачи](Cron-jobs)
- [API](API)
- [Подпись HMAC](HMAC-signature)
- [Интеграция Python](Python-integration)
- [Боты](Bots)
- [Журналы](Logs)
- [Инциденты](Incidents)
- [Оповещения](Alerts)
- [Retention](Retention)
- [Центр обновлений](Update-center)
- [Безопасность](Security)
- [RBAC](RBAC)
- [Чек-лист эксплуатации](Production-checklist)
- [Чек-лист релиза](Release-checklist)
- [FAQ](FAQ)
- [Changelog](Changelog)

## Базовый сценарий

1. Развернуть приложение на PHP 8.2+.
2. Подключить PostgreSQL.
3. Создать администратора.
4. Создать ingest-токен для приложения или бота.
5. Подключить клиент отправки журналов.
6. Настроить cron-задачи обработки, оповещений, импорта журналов и retention.
