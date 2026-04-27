# Retention

Retention удаляет старые события по уровням и источникам.

Основные параметры:

```env
RETENTION_DEBUG_DAYS=7
RETENTION_INFO_DAYS=30
RETENTION_WARN_DAYS=90
RETENTION_ERROR_DAYS=365
RETENTION_AUDIT_DAYS=730
RETENTION_ARCHIVE_BEFORE_DELETE=true
```

Если `RETENTION_ARCHIVE_BEFORE_DELETE=true`, события перед удалением сохраняются в `storage/archives` в формате NDJSON.gz.
