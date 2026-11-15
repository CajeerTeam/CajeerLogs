"""SDK wire-protocol tests, not a substitute for the PostgreSQL integration suite."""
import hashlib
import hmac
import json
import sys
import threading
import urllib.error
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "clients" / "python"))
from cajeer_logs_client import CajeerLogsClient

TOKEN = "cl_" + "a" * 64
received = []


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        raw = self.rfile.read(int(self.headers["Content-Length"]))
        received.append((self.path, self.headers, raw))
        if self.path.startswith("/redirect/"):
            self.send_response(307)
            self.send_header("Location", "/capture")
            self.end_headers()
            return
        payload = json.loads(raw)
        count = len(payload["events"]) if "events" in payload else 1
        body = json.dumps({"accepted": True, "received": count, "stored": count, "rejected": 0, "replayed": False, "event_ids": [f"00000000-0000-4000-8000-{i+1:012x}" for i in range(count)]}).encode()
        self.send_response(201)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
worker = threading.Thread(target=server.serve_forever, daemon=True)
worker.start()
try:
    url = f"http://127.0.0.1:{server.server_port}"
    event = {"timestamp":"2026-01-01T00:00:00Z","source":"app","level":"info","message":"Привет"}
    client = CajeerLogsClient(url, TOKEN, signed=True)
    assert client.ingest(event, idempotency_key="key-1")["accepted"]
    path, headers, raw = received[-1]
    assert path == "/api/v1/ingest" and headers["Authorization"] == "Bearer " + TOKEN
    assert json.loads(raw) == event and headers["Idempotency-Key"] == "key-1"
    print("PASS signed client HTTP request")
    canonical = "\n".join(["cajeerlogs-ingest-v1", "POST", path, headers["X-Log-Timestamp"], headers["X-Log-Nonce"], "key-1", hashlib.sha256(raw).hexdigest()])
    assert hmac.compare_digest(headers["X-Log-Signature"], hmac.new(TOKEN.encode(), canonical.encode(), hashlib.sha256).hexdigest())
    print("PASS canonical HMAC wire format")
    client.ingest(event, idempotency_key="key-1")
    assert received[-1][2] == raw and received[-1][1]["X-Log-Nonce"] != headers["X-Log-Nonce"]
    print("PASS same body and fresh nonce on retry")
    CajeerLogsClient(url, TOKEN).ingest([event, event])
    assert json.loads(received[-1][2]) == {"events": [event, event]} and received[-1][1].get("X-Log-Signature") is None
    print("PASS unsigned batch")
    CajeerLogsClient(url, TOKEN, signed=True, api_prefix="/custom/v1").ingest(event)
    assert received[-1][0] == "/custom/v1/ingest"
    print("PASS configurable API prefix")
    try:
        CajeerLogsClient(url, TOKEN, hmac_secret="legacy").ingest(event)
        raise AssertionError("legacy HMAC key accepted")
    except ValueError:
        print("PASS incompatible legacy key rejected")
    try:
        CajeerLogsClient(url, TOKEN, api_prefix="/redirect/api/v1").ingest(event)
        raise AssertionError("redirect followed")
    except urllib.error.HTTPError as exc:
        assert exc.code == 307 and received[-1][0] == "/redirect/api/v1/ingest"
        print("PASS credentials not forwarded on redirect")
    print("Python SDK: 7 checks passed")
finally:
    server.shutdown()
    server.server_close()
    worker.join(timeout=5)
