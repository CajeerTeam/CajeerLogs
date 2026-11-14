#!/bin/bash
# Disposable CI PostgreSQL only. Configure TLS and a local session-mode PgBouncer.
set -euo pipefail
[[ ${CAJEER_TEST_DATABASE:-} == 1 && ${CAJEER_TEST_POSTGRES_CONTAINER:-} =~ ^[a-f0-9]{12,64}$ ]] || exit 64
root=/tmp/cajeer-pg-runtime
mkdir -m 700 "$root"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=localhost \
  -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
container=$CAJEER_TEST_POSTGRES_CONTAINER
docker cp "$root/cert.pem" "$container:/tmp/cajeer-pg-cert.pem"
docker cp "$root/key.pem" "$container:/tmp/cajeer-pg-key.pem"
docker exec -u root "$container" sh -c 'chown postgres:postgres /tmp/cajeer-pg-cert.pem /tmp/cajeer-pg-key.pem; chmod 600 /tmp/cajeer-pg-key.pem'
for setting in "ssl_cert_file='/tmp/cajeer-pg-cert.pem'" "ssl_key_file='/tmp/cajeer-pg-key.pem'" 'ssl=on'; do
  psql -h 127.0.0.1 -U postgres -d cajeer_logs -v ON_ERROR_STOP=1 -c "ALTER SYSTEM SET $setting"
done
docker restart --time 5 "$container" >/dev/null
for i in $(seq 1 60); do
  if pg_isready -h 127.0.0.1 -p 5432 -U postgres -d cajeer_logs >/dev/null; then break; fi
  sleep 1
done
psql -h 127.0.0.1 -U postgres -d cajeer_logs -v ON_ERROR_STOP=1 -Atc 'SHOW ssl' | grep -qx on
if ! command -v pgbouncer >/dev/null; then
  sudo apt-get update
  sudo apt-get install -y pgbouncer
fi
sudo systemctl stop pgbouncer 2>/dev/null || true
cat > "$root/users.txt" <<'EOF'
"cajeer_logs" "ci-app-role-password-only"
EOF
chmod 600 "$root/users.txt"
cat > "$root/pgbouncer.ini" <<EOF
[databases]
cajeer_logs = host=127.0.0.1 port=5432 dbname=cajeer_logs
[pgbouncer]
listen_addr = 127.0.0.1
listen_port = 6432
unix_socket_dir = $root
pool_mode = session
auth_type = scram-sha-256
auth_file = $root/users.txt
pidfile = $root/pgbouncer.pid
logfile = $root/pgbouncer.log
server_reset_query = DISCARD ALL
max_client_conn = 20
default_pool_size = 5
EOF
pgbouncer -d "$root/pgbouncer.ini"
for i in $(seq 1 30); do
  if pg_isready -h 127.0.0.1 -p 6432 -d cajeer_logs -U cajeer_logs >/dev/null; then exit 0; fi
  sleep 1
done
exit 1
