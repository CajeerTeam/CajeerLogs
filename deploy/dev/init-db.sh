#!/bin/sh
set -eu
# psql variable quoting protects arbitrary password characters.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
\getenv app_password CAJEER_DB_PASSWORD
CREATE ROLE cajeer_logs LOGIN PASSWORD :'app_password';
ALTER DATABASE cajeer_logs OWNER TO cajeer_logs;
SQL
