#!/usr/bin/env bash
set -euo pipefail

# Ручная публикация исходников wiki/*.md в отдельный GitHub Wiki-репозиторий.
# Требуется push-доступ к https://github.com/CajeerTeam/CajeerLogs.wiki.git.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WIKI_REMOTE="${WIKI_REMOTE:-https://github.com/CajeerTeam/CajeerLogs.wiki.git}"
WORK_DIR="${WORK_DIR:-${ROOT_DIR}/.wiki-publish}"
DEFAULT_BRANCH="${WIKI_BRANCH:-master}"

cd "$ROOT_DIR"
php bin/wiki-check.php

rm -rf "$WORK_DIR"
if git clone "$WIKI_REMOTE" "$WORK_DIR"; then
  cd "$WORK_DIR"
  BRANCH="$(git symbolic-ref --short HEAD 2>/dev/null || true)"
  if [ -z "$BRANCH" ]; then
    BRANCH="$(git branch --show-current 2>/dev/null || true)"
  fi
  if [ -z "$BRANCH" ]; then
    BRANCH="$DEFAULT_BRANCH"
    git checkout -B "$BRANCH"
  fi
else
  echo "Wiki-репозиторий ещё не инициализирован или недоступен: $WIKI_REMOTE" >&2
  echo "Будет создана локальная история Wiki. Если push не пройдёт, создай первую страницу Wiki в GitHub UI и повтори публикацию." >&2
  mkdir -p "$WORK_DIR"
  cd "$WORK_DIR"
  git init
  BRANCH="$DEFAULT_BRANCH"
  git checkout -B "$BRANCH"
  git remote add origin "$WIKI_REMOTE"
fi

find "$WORK_DIR" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R "$ROOT_DIR/wiki/." "$WORK_DIR/"

git add .
if git diff --cached --quiet; then
  echo "Wiki без изменений."
  exit 0
fi

git commit -m "Update Cajeer Logs wiki"
git push origin "HEAD:${BRANCH}"
