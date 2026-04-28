#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WIKI_REMOTE="${WIKI_REMOTE:-https://github.com/CajeerTeam/CajeerLogs.wiki.git}"
WORK_DIR="${WORK_DIR:-${ROOT_DIR}/.wiki-publish}"
DEFAULT_BRANCH="${WIKI_BRANCH:-master}"
DRY_RUN=0
DELETE_REMOVED=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --delete-removed) DELETE_REMOVED=1 ;;
    --remote=*) WIKI_REMOTE="${arg#--remote=}" ;;
    --branch=*) DEFAULT_BRANCH="${arg#--branch=}" ;;
    *) echo "Неизвестный аргумент: $arg" >&2; exit 2 ;;
  esac
done

cd "$ROOT_DIR"
php bin/wiki-check.php

if find wiki -type f -name '*.md' | grep -P '[^\x00-\x7F]' >/dev/null; then
  echo "Wiki содержит не-ASCII имена файлов; переименуй страницы перед публикацией." >&2
  exit 1
fi

rm -rf "$WORK_DIR"
if git clone "$WIKI_REMOTE" "$WORK_DIR"; then
  cd "$WORK_DIR"
  BRANCH="$(git symbolic-ref --short HEAD 2>/dev/null || git branch --show-current 2>/dev/null || true)"
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

if [ "$DELETE_REMOVED" -eq 1 ]; then
  find "$WORK_DIR" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
fi
cp -R "$ROOT_DIR/wiki/." "$WORK_DIR/"

echo "Изменения Wiki:" >&2
git status --short

if [ "$DRY_RUN" -eq 1 ]; then
  echo "Dry-run: push не выполняется." >&2
  exit 0
fi

git add .
if git diff --cached --quiet; then
  echo "Wiki без изменений."
  exit 0
fi

git commit -m "Update Cajeer Logs wiki"
git push origin "HEAD:${BRANCH}"
