# CajeerLogs PHP SDK 0.5.4

PHP 8.4+, PSR-3 (`psr/log` 3), необязательно Monolog 3. Существующее имя Composer-пакета `cajeer/logs-extension-sdk` сохранено для совместимости. Добавьте path repository, указывающий на `packages/php-sdk` этого релиза, затем `composer require cajeer/logs-extension-sdk:0.5.4`. SDK использует PHP HTTPS streams (allow_url_fopen=On, OpenSSL, доверенные CA); Composer нужен для автозагрузки PSR-3 и Monolog, сервер CajeerLogs не нужен внутри приложения.

```php
use Cajeer\Logs\Sdk\CajeerLogsClient;
use Cajeer\Logs\Sdk\Logger;

$client = new CajeerLogsClient(getenv('CAJEER_URL'), getenv('CAJEER_TOKEN'), signed: true);
$logger = new Logger($client, 'app', 'backend');
$logger->info('Job completed', ['job_id' => 42]);
```

Monolog 3: `composer require monolog/monolog:^3`, затем `$monolog->pushHandler(new \Cajeer\Logs\Sdk\MonologHandler($client, 'app'));`. Handler отправляет level, timestamp, message, context и extra; исключения доставки не скрываются. Для fail-open политики используйте Monolog `WhatFailureGroupHandler` с локальным резервным handler, либо пишите в файл и запускайте коллектор. Не регистрируйте этот handler для собственных логов транспорта.

Прямой вызов `$client->ingest($event, 'job-42')` или `$client->ingest([$event1,$event2], 'batch-42')` возвращает подтверждение. При неизвестном результате `DeliveryException` содержит `status`, `idempotencyKey`; повторяйте исходный payload с тем же ключом. Таймаут stream — таймаут сетевых операций, не глобальный deadline. Подробнее: [SDK & Integrations](../../docs/SDK-Integrations.md).
