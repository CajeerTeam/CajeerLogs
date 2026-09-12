# Участие в разработке CajeerLogs

Спасибо за интерес к проекту. Основной язык проекта — русский. Технические идентификаторы, namespaces, API paths и внешние стандарты могут быть на английском.

## Правила вклада

1. Сначала создайте issue или обсуждение в GitFlic.
2. Для изменений API сначала обновляйте `api/openapi.yaml`.
3. Для изменений схемы добавляйте миграции в `database/migrations/`.
4. Для расширений указывайте manifest, permissions и compatibility constraints.
5. Для пользовательских изменений обновляйте страницы `wiki/`.

## Проверки перед merge request

```bash
composer validate
composer test
php bin/cajeer doctor
php bin/cajeer openapi:lint
```

## Кодстайл

- PHP: strict types, PSR-12 как база.
- TypeScript: strict mode.
- Документация: русский язык.
- Комментарии: только там, где они объясняют архитектурное решение.
