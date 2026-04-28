# Чек-лист релиза

Перед релизом:

- [ ] Обновить `VERSION`.
- [ ] Обновить `wiki/Changelog.md`.
- [ ] Обновить `openapi.yaml`, если менялся API.
- [ ] Обновить Wiki-страницы при изменении поведения.
- [ ] Запустить `php bin/self-test.php`.
- [ ] Запустить `php bin/wiki-check.php`.
- [ ] Проверить PHP syntax check.
- [ ] Проверить `python3 -m py_compile clients/bot.py`.
- [ ] Проверить центр обновлений на staging-контуре.
- [ ] Проверить откат из резервной копии.
- [ ] Убедиться, что в релиз не попали `.env`, дампы БД, runtime-журналы и секреты.

## Проверки перед release

- [ ] `php bin/self-test.php`
- [ ] `php bin/wiki-check.php`
- [ ] `php bin/schema-check.php`
- [ ] `python3 -m py_compile clients/bot.py`
- [ ] Проверить ingest smoke test на staging.


## Артефакты релиза

- [ ] `cajeerlogs-v0.8.2-ops-hardening.zip` или исходный архив GitHub Release.
- [ ] `SHA256SUMS` для опубликованных архивов.
- [ ] выдержка из `wiki/Changelog.md`.
- [ ] актуальный `openapi.yaml`.
- [ ] результат `php bin/release-check.php`.
