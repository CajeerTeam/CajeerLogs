#!/usr/bin/env bash
set -euo pipefail

# Ручная публикация исходников wiki/*.md в отдельный GitHub Wiki-репозиторий.
# Требуется локальный доступ на push в https://github.com/CajeerTeam/CajeerLogs.wiki.git.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WIKI_REMOTE="${WIKI_REMOTE:-https://github.com/CajeerTeam/CajeerLogs.wiki.git}"
WORK_DIR="${WORK_DIR:-${ROOT_DIR}/.wiki-publish}"

cd "$ROOT_DIR"
php bin/wiki-check.php

rm -rf "$WORK_DIR"
git clone "$WIKI_REMOTE" "$WORK_DIR"
find "$WORK_DIR" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R "$ROOT_DIR/wiki/." "$WORK_DIR/"

cd "$WORK_DIR"
git add .
if git diff --cached --quiet; then
  echo "Wiki без изменений."
  exit 0
fi

git commit -m "Update Cajeer Logs wiki"
git push origin master
