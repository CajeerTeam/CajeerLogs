# FAQ

## Можно ли использовать SQLite?

Да, но только для локальной проверки или demo. Для production используйте PostgreSQL.

## Нужно ли включать HMAC?

Для недоверенных сетей — да. HMAC защищает от подмены тела запроса и повторной отправки.

## Почему событие не принято?

Проверьте токен, размер пачки, лимиты, project/bot/environment токена и подпись запроса.

## Почему PHP пишет `Module "pdo_pgsql" is already loaded`?

Модуль подключён дважды в конфигурации PHP. Проверьте активные ini-файлы:

```bash
/www/server/php/83/bin/php --ini
grep -R "pdo_pgsql" /www/server/php/83/etc/ 2>/dev/null
```

Оставьте одно подключение `pdo_pgsql`, второе закомментируйте и перезапустите PHP-FPM.

## Как проверить runtime после ручного обновления?

Откройте `/system/runtime`. Там видны PHP SAPI, пользователь PHP-FPM, `PATH`, `disable_functions`, PDO-драйверы и OPcache. Если браузер показывает старый интерфейс, перезапустите PHP-FPM и обновите PWA cache.
