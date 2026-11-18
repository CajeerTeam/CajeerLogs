# 0.6.2 — Python SDK Stable: эксплуатация и приёмка

## Реализованный путь доставки

- `CajeerLogsClient` — проверка схемы, idempotency, HMAC, HTTPS, HTTP status и
  ограничений ответов, синхронные receipts и async jobs.
- `DurableBatchSpool` — реальная SQLite очередь `entries` и неизменяемых
  `batches` на private filesystem. Enqueue подтверждается **только после COMMIT**.
  Группа (до 100 событий, не более 1 MiB) атомарно переводится в committed
  batch с ключом и точными байтами **до** первой HTTP-попытки.
- `CajeerLogsBufferedHandler` — реальная интеграция со `logging.Handler`:
  уровень, message/formatter, exception traceback, `extra` context, UTC
  timestamp, service. Background worker с настраиваемым интервалом не делает
  сетевой запрос в `emit`. Есть счётчики и последняя ошибка enqueue/delivery.
- `cajeer-logs-spool status|flush` — восстановление и ручной дренаж без
  запуска приложения. Не передавать токены в аргументах команд.
- Существующие синхронный `CajeerLogsHandler`, `DurableAsyncSpool` и
  `cajeer-logs-collector` сохранены.

## Гарантии и пределы

На стороне клиента запись считается принятой только после SQLite COMMIT.
Успешная синхронная доставка подтверждается валидным HTTP 201/200 receipt.
Сбой HTTP не удаляет батч. Повтор сохраняет тело и Idempotency-Key; HMAC nonce
обновляется. В течение окна идемпотентности сервера это позволяет устранять
дубли по ключу. Если первая попытка старше безопасного окна (по умолчанию
23 часа), SDK возвращает `SpoolExpired` без нового HTTP POST — необходима
ручная сверка. Нельзя автоматически гарантировать ровно одну запись после
истечения retention или после смены токена без сверки с сервером.

`max_events` и `max_bytes` ограничивают входящий spool, `batch_events` и
`batch_bytes` — размер отправляемой группы. Переполнение вызывает `SpoolFull`
до записи; handler с `fail_on_enqueue_error=False` использует стандартный
`logging.handleError` и **не гарантирует** приём логов при заполнении диска.
Рекомендуется `fail_on_enqueue_error=True` и мониторинг очереди.

Файлы журнала чувствительны. Для Unix владелец/права директории: 0700,
база и lock: 0600. Не удалять или пересоздавать базу для «восстановления».
`SpoolBusy` означает конкурентный flusher, а не потерю данных. Старый
`DurableAsyncSpool` остаётся POSIX-only; новый sync spool поддерживает
совместимые блокировки ОС (`fcntl`/`msvcrt`).

## Проверка перед вводом в эксплуатацию

```sh
python3 tests/python_sdk_stable.py
python3 tests/python_ingest.py
python3 tests/sdk_async_wire.py
python3 -m pip install build
python3 -m build --sdist --wheel --outdir /tmp/cajeer-python-dist clients/python
python3 -m pip install --no-index --find-links /tmp/cajeer-python-dist cajeer-logs-client==0.6.2
cajeer-logs-spool --help
```

CI дополнительно проверяет пакеты в Python 3.10/3.11/3.12/3.13 и реальный
PostgreSQL E2E со стороны действующего PHP приложения. Публикацию в PyPI
выполнять отдельно после проверки собственника пакета, секретов и лицензий.
**Локальные loopback HTTP-тесты не заменяют load/Docker/PostgreSQL
сертификацию и не обозначают stable production proof без CI.**

Установка поверх 0.6.1 не изменяет PostgreSQL schema (001–0025). Новый spool
имеет собственный SQLite state и не модифицирует существующий async outbox.
