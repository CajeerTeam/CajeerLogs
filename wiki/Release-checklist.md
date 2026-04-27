# Release checklist

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
