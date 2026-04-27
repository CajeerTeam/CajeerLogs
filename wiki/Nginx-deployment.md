# Развёртывание Nginx

Document root должен указывать на каталог `public/`.

```nginx
server_name logs.example.com;
root /var/www/cajeer-logs/public;
```

В публичный доступ не должны попадать каталоги:

- `app/`
- `bin/`
- `storage/`
- `.env`
- служебные файлы репозитория

## PWA

Для корректной установки на домашний экран нужны отдельные location-блоки:

```nginx
location = /sw.js {
    default_type application/javascript;
    try_files $uri =404;
    expires -1;
    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
}

location = /manifest.json {
    default_type application/json;
    try_files $uri =404;
    expires 1h;
}
```
