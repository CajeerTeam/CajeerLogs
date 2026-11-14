#!/bin/sh
# Finite operations are separately supervised; no ignored failures.
set -eu
case "${1:-}" in
  scheduler) shift; set -- scheduler:run; delay=30 ;;
  webhook) shift; set -- webhook:work 20; delay=5 ;;
  *) echo 'Usage: cajeer-maintenance scheduler|webhook' >&2; exit 64 ;;
esac
child=
stop() { if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || :; wait "$child" 2>/dev/null || :; fi; exit 0; }
trap stop TERM INT
while :; do
  php /app/bin/cajeer "$@" & child=$!; wait "$child"; child=
  sleep "$delay" & child=$!; wait "$child"; child=
done
