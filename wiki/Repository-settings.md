# Настройки репозитория

Рекомендуемые настройки GitHub-репозитория Cajeer Logs для публичной разработки.

## Общие настройки

- Default branch: `main`.
- Description: `Сервис Cajeer для централизованного сбора, хранения и анализа журналов от ботов, сайтов и инфраструктурных компонентов.`
- Website: `https://github.com/CajeerTeam/CajeerLogs/wiki`.
- Topics: `cajeer`, `logs`, `logging`, `observability`, `php`, `postgresql`, `sqlite`, `nginx`, `aapanel`, `webhooks`, `incidents`, `alerts`, `audit`, `rbac`, `pwa`, `self-hosted`, `monitoring`.

## Features

Включить:

- Wikis.
- Restrict editing to users in teams with push access only.
- Issues.
- Preserve this repository.
- Pull requests.

Отключить, если не используются:

- Sponsorships.
- Discussions.
- Projects.
- Packages.
- Deployments.

## Pull requests

Рекомендуемая модель merge:

- Отключить merge commits.
- Включить squash merging.
- Включить rebase merging.
- Не включать auto-merge до появления обязательных status checks.
- Включить automatic delete head branches.

## Branch protection для `main`

Минимальный набор правил:

- Require a pull request before merging.
- Require approvals: `1` или больше.
- Require status checks to pass before merging.
- Require branches to be up to date before merging — по необходимости.
- Restrict who can push to matching branches.
- Do not allow force pushes.
- Do not allow deletions.

Обязательные checks:

- `php bin/self-test.php`.
- `php bin/wiki-check.php`.
- `php bin/schema-check.php`.
- PHP syntax lint.
- Python syntax check for `clients/bot.py`.

## Releases

- Включить release immutability.
- Не изменять опубликованные assets вручную.
- Для каждого release обновлять `VERSION`, `wiki/Changelog.md` и release notes.

## Wiki

Исходники Wiki хранятся в каталоге `wiki/` основного репозитория. Публикация выполняется вручную workflow `Publish Wiki` или локально командой:

```bash
bash bin/wiki-publish.sh
```


## Рекомендуемые флаги

- `Release immutability` — включить.
- `Sponsorships` — выключить, если проект не принимает финансовую поддержку через GitHub.
- `Packages` и `Deployments` — выключить, если они не используются.
- `Discussions` — выключить, пока обсуждения ведутся через Issues.
- `Automatically delete head branches` — включить.


## Автоматизация репозитория

- `.github/workflows/labels-sync.yml` синхронизирует labels из `.github/labels.yml`.
- `.github/workflows/release.yml` вручную собирает ZIP, SHA256SUMS и создаёт GitHub Release.
- `.github/workflows/wiki-publish.yml` публикует каталог `wiki/` в GitHub Wiki.
