# CajeerLogs 0.9.5 — установка и обновление

Актуальная пошаговая инструкция: [Production Installer 0.9.4](Production-Installer-0.9.4.md). Для разделения PostgreSQL ролей см. [Installer & Administrator](Installer-Administrator.md) и [Database Installer](Database-Installer.md).

Установка из проверенного релизного ZIP (PHP 8.4+, PostgreSQL 17/18): `php bin/cajeer install:check`, затем `php bin/cajeer install` (или `install --non-interactive`), `php bin/cajeer install:status`, `php bin/cajeer doctor`. Первый администратор задаётся через закрытый терминал или `CAJEER_ADMIN_EMAIL` и `CAJEER_ADMIN_PASSWORD_FILE` (файл 0600).

Обновление существующего экземпляра: резервная копия из предыдущего релиза, остановка API/worker-процессов, развертывание проверенного ZIP, `php bin/cajeer upgrade:check BACKUP_ID`, `php bin/cajeer upgrade BACKUP_ID --confirm-stopped`, проверка `install:status` и `migrate:verify`. См. runbook [maintenance-upgrade](runbooks/maintenance-upgrade.md) для отката в отдельную БД.
