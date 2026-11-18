# SDK

TypeScript API SDK является основным SDK. Дополнительно: Python client и Java/Kotlin plugin.


## 0.5.8 Delivery Guarantees & SDK Recovery

Четыре SDK (PHP, TS, Python, JVM) теперь умеют durable async enqueue (`202`), подтверждение `completed` через авторизованный job GET, проверку event IDs и bounded polling. Опциональный приватный Python SQLite spool и файловый collector с `--async` сохраняют данные между перезапусками. [Полный runbook](SDK-Delivery-Recovery.md).
