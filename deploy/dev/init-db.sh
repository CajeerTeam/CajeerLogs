#!/bin/sh
set -eu
if [ "${CAJEER_DB_PASSWORD}" = "${POSTGRES_PASSWORD}" ] || [ "${#CAJEER_DB_PASSWORD}" -lt 16 ] || [ "${#POSTGRES_PASSWORD}" -lt 16 ]; then
    echo 'Use separate PostgreSQL passwords, each at least 16 characters.' >&2; exit 1
fi
case "$CAJEER_DB_PASSWORD:$POSTGRES_PASSWORD" in *REPLACE_WITH*|*change_me*) echo 'Replace placeholder PostgreSQL passwords.' >&2; exit 1;; esac
# psql variable quoting protects arbitrary password characters.
PGPASSWORD="$POSTGRES_PASSWORD" psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
\getenv app_password CAJEER_DB_PASSWORD
CREATE ROLE cajeer_logs LOGIN PASSWORD :'app_password';
ALTER DATABASE cajeer_logs OWNER TO cajeer_logs;
SQL
