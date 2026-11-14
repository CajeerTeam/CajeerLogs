"""Real HTTP integration checks against the shipped PHP development router."""
import contextlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
COUNT = 0


def check(condition, label):
    global COUNT
    if not condition:
        raise AssertionError(label)
    COUNT += 1
    print(f"PASS {label}")


def request(port, method, path, body=None, headers=None):
    connection = http.client.HTTPConnection("127.0.0.2", port, timeout=5)
    try:
        connection.request(method, path, body, headers or {})
        response = connection.getresponse()
        return response.status, {k.lower(): v for k, v in response.getheaders()}, response.read()
    finally:
        connection.close()


@contextlib.contextmanager
def server(root=ROOT, **overrides):
    with socket.socket() as sock:
        sock.bind(("127.0.0.2", 0))
        port = sock.getsockname()[1]
    env = {k: v for k, v in os.environ.items() if not k.startswith(("APP_", "API_", "PROMETHEUS_", "OPENAPI_"))}
    env["APP_ENV"] = "test"
    env.update(overrides)
    with tempfile.TemporaryFile() as log:
        process = subprocess.Popen(["php", "-S", f"127.0.0.2:{port}", "-t", "public", "public/router.php"], cwd=root, env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError("PHP server exited")
                try:
                    with socket.create_connection(("127.0.0.2", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError("PHP server did not start")
            yield port
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()


with server() as port:
    for path in ("/", "/health", "/api/v1/health?probe=1"):
        status, headers, body = request(port, "GET", path)
        data = json.loads(body)
        check(status == 200 and data["version"] == "0.1.1" and data["scope"] == "runtime", f"GET {path}")
        check(len(headers["x-request-id"]) == 32 and headers["cache-control"] == "no-store" and headers["x-content-type-options"] == "nosniff", f"headers {path}")
    status, headers, body = request(port, "POST", "/health")
    check(status == 405 and headers["allow"] == "GET, HEAD, OPTIONS" and json.loads(body)["request_id"] == headers["x-request-id"], "405 + Allow + correlation")
    status, headers, body = request(port, "HEAD", "/health")
    check(status == 200 and not body, "HEAD without body")
    status, headers, body = request(port, "OPTIONS", "/health")
    check(status == 204 and not body and "GET" in headers["allow"], "OPTIONS without body")
    for path in ("/does-not-exist",):
        status, _, body = request(port, "GET", path)
        check(status == 404 and json.loads(body)["error"] == "not_found", f"404 {path}")
    for path in ("/api/v1/logs", "/api/v1/logs/stats", "/api/v1/logs/export", "/api/v1/logs/00000000-0000-0000-0000-000000000001"):
        check(request(port, "GET", path)[0] == 401, f"protected {path}")
    check(request(port, "POST", "/api/v1/logs")[0] == 405, "search method restricted")
    check(request(port, "GET", "/api/v1/logs?project_id=bad", headers={"Authorization":"Bearer cl_"+"a"*64})[0] == 422, "invalid project query")
    check(request(port, "GET", "/api/v1/ingest")[0] == 405, "ingest method restricted")
    check(request(port, "POST", "/api/v1/ingest", b'{}', {"Content-Type":"application/json"})[0] == 401, "ingest requires bearer")
    check(request(port, "OPTIONS", "/api/v1/ingest")[0] == 204, "ingest OPTIONS")
    check(request(port, "POST", "/api/v1/ingest/async", b'{}', {"Content-Type":"application/json"})[0] == 401, "async requires bearer")
    check(request(port, "POST", "/api/v1/ingest/async", b'{}', {"Authorization":"Bearer cl_"+"a"*64,"Content-Type":"application/json"})[0] == 422, "async requires key")
    check(request(port, "GET", "/api/v1/ingest/jobs/1")[0] == 401, "job status protected")
    check(request(port, "GET", "/api/v1/ingest/jobs/9223372036854775808", headers={"Authorization":"Bearer cl_"+"a"*64})[0] == 422, "job ID bounds")
    check(request(port, "GET", "/api/v1/ingest/async")[0] == 405, "async method restricted")

    check(request(port, "GET", "/api/v1/system/report")[0] == 401, "system report requires authentication")
    status, headers, body = request(port, "GET", "/api/v1/auth/me")
    check(status == 401 and headers["www-authenticate"].startswith("Bearer") and headers["cache-control"] == "no-store", "identity unauthorized + challenge")
    check(request(port, "GET", "/api/v1/auth/token", headers={"Authorization":"Bearer invalid"})[0] == 401, "malformed bearer rejected over HTTP")
    check(request(port, "POST", "/api/v1/users", b'{}', {"Content-Type":"application/json"})[0] == 401, "unauthenticated user mutation rejected")
    check(request(port, "GET", "/api/v1/auth/login")[0] == 405, "login method rejected")
    status, headers, body = request(port, "GET", "/metrics")
    check(status == 200 and headers["content-type"].startswith("text/plain") and b"cajeerlogs_up 1" in body and b"cajeerlogs_php_memory_bytes " in body, "Prometheus metrics")
    status, headers, body = request(port, "GET", "/admin")
    check(status == 308 and headers["location"] == "/admin/", "admin canonical redirect")
    status, headers, body = request(port, "GET", "/admin/")
    check(status == 200 and headers["content-type"].startswith("text/html") and b'content="0.1.1"' in body and b'content="/api/v1"' in body, "admin HTML runtime version and API prefix")
    check("script-src 'self'" in headers["content-security-policy"] and "frame-ancestors 'none'" in headers["content-security-policy"] and headers["cache-control"] == "no-store", "admin CSP and no-store")
    check(request(port, "HEAD", "/admin/")[2] == b"", "admin HEAD without body")
    check(request(port, "POST", "/admin/")[0] == 405, "admin unsupported method")
    for path, mime in [("/admin/assets/panel.js", "javascript"), ("/admin/assets/panel.css", "text/css")]:
        st, hdr, content = request(port, "GET", path)
        check(st == 200 and mime in hdr["content-type"] and len(content) > 100, "admin asset " + path)
    check(request(port, "GET", "/admin/index.html")[0] == 404, "private admin template not directly served")
    check(request(port, "GET", "/admin/not-a-route")[0] == 404, "no generic admin PHP fallback")
    status, _, body = request(port, "GET", "/assets/css/app.css")
    check(status == 200 and len(body) > 0, "static asset delivery")
    status, _, body = request(port, "GET", "/bad%ZZ")
    check(status == 400 and json.loads(body)["error"] == "invalid_path", "malformed URI")
    status, _, body = request(port, "GET", "/.env")
    check(status == 404 and json.loads(body)["error"] == "not_found", "private environment path not served")

with server(APP_MAX_BODY_BYTES="16", PROMETHEUS_ENABLED="false", API_PREFIX="/custom/v1") as port:
    status, _, body = request(port, "POST", "/health", b"x" * 17)
    check(status == 413 and json.loads(body)["error"] == "payload_too_large", "oversized body rejected")
    check(request(port, "GET", "/metrics")[0] == 404, "metrics disabled by config")
    check(request(port, "GET", "/custom/v1/health")[0] == 200 and request(port, "GET", "/api/v1/health")[0] == 404, "configurable API prefix")
    check(b'content="/custom/v1"' in request(port, "GET", "/admin/")[2], "admin uses configured API prefix")

with server(APP_ENV="production", APP_DEBUG="true") as port:
    status, headers, body = request(port, "GET", "/health")
    check(status == 500 and json.loads(body)["error"] == "internal_error" and b"APP_DEBUG" not in body and json.loads(body)["request_id"] == headers["x-request-id"], "invalid production configuration: safe bootstrap error")

with tempfile.TemporaryDirectory() as temp:
    copy = Path(temp) / "project"
    shutil.copytree(ROOT, copy, ignore=shutil.ignore_patterns("vendor", ".git", "storage", ".env", "node_modules"))
    (copy / ".env").write_text("APP_NAME=FromFile\nAPP_ENV=production\nAPP_DEBUG=false\n", encoding="utf-8")
    with server(copy, APP_NAME="FromProcess") as port:
        status, _, body = request(port, "GET", "/health")
        check(status == 503 and json.loads(body)["status"] == "down" and json.loads(body)["service"] == "FromProcess", "readiness failure + process env precedence")
        check(b"cajeerlogs_up 0" in request(port, "GET", "/metrics")[2], "unhealthy metrics")
    (copy / "storage").mkdir()
    with server(copy) as port:
        status, _, body = request(port, "GET", "/health")
        check(status == 200 and json.loads(body)["service"] == "FromFile", "dotenv loaded at HTTP bootstrap")

with server(APP_ENV="production", APP_URL="https://logs.example.test") as port:
    check(request(port, "GET", "/admin/")[0] == 403, "production admin refuses insecure login page")
    check(request(port, "GET", "/admin/", headers={"X-Forwarded-Proto":"https"})[0] == 403, "admin rejects spoofed HTTPS")
    check(request(port, "POST", "/api/v1/ingest", b'{}', {"Authorization":"Bearer cl_"+"a"*64,"X-Forwarded-Proto":"https"})[0] == 403, "ingest rejects insecure production request")
    check(request(port, "POST", "/api/v1/auth/login", b'{}', {"Content-Type":"application/json", "X-Forwarded-Proto":"https"})[0] == 403, "production rejects HTTP and spoofed forwarded proto")

with server(DB_CONNECTION="unsupported") as port:
    check(request(port, "POST", "/api/v1/auth/login", b'{"email":"admin@example.test","password":"secret-test"}', {"Content-Type":"application/json"})[0] == 503, "identity fails closed without database")
    check(request(port, "POST", "/api/v1/ingest", b'{}', {"Authorization":"Bearer cl_"+"a"*64,"Content-Type":"application/json"})[0] == 503, "ingest fails closed without database")
    check(request(port, "POST", "/api/v1/ingest/async", b'{}', {"Authorization":"Bearer cl_"+"a"*64,"Content-Type":"application/json","Idempotency-Key":"async-test"})[0] == 503, "async fails closed without database")
    check(request(port, "GET", "/api/v1/ingest/jobs/1", headers={"Authorization":"Bearer cl_"+"a"*64})[0] == 503, "job status fails closed without database")
    metrics=request(port,"GET","/metrics")[2]
    check(b"cajeerlogs_queue_available 0" in metrics and b"cajeerlogs_postgres_available 0" in metrics and b"cajeerlogs_postgres_sessions_total" not in metrics, "unavailable queue and PostgreSQL metrics without fabricated counters")

    check(request(port, "GET", "/api/v1/logs?project_id=00000000-0000-0000-0000-000000000001", headers={"Authorization":"Bearer cl_"+"a"*64})[0] == 503, "search database failure")
    status, _, body = request(port, "GET", "/ready")
    check(status == 503 and json.loads(body)["scope"] == "database" and json.loads(body)["status"] == "down", "database readiness failure")
    check(request(port, "GET", "/health")[0] == 200, "runtime liveness independent of database")

print(f"HTTP integration: {COUNT} checks passed")
