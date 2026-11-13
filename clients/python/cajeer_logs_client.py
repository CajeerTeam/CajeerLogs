from __future__ import annotations

import hashlib
import hmac
import json
import secrets
import time
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Never forward a bearer credential to a redirect destination."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


@dataclass
class CajeerLogsClient:
    base_url: str
    token: str
    hmac_secret: str | None = None  # compatibility: must equal token for the v1 signing protocol
    timeout: int = 10
    signed: bool = False
    api_prefix: str = "/api/v1"

    def ingest(self, event: dict[str, Any] | list[dict[str, Any]], *, idempotency_key: str | None = None) -> dict[str, Any]:
        payload = {"events": event} if isinstance(event, list) else event
        body = json.dumps(payload, ensure_ascii=False, allow_nan=False, separators=(",", ":")).encode("utf-8")
        url = f"{self.base_url.rstrip('/')}{self.api_prefix}/ingest"
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError("Invalid CajeerLogs endpoint URL")
        headers = {"Content-Type": "application/json", "Authorization": f"Bearer {self.token}"}
        if idempotency_key is not None:
            headers["Idempotency-Key"] = idempotency_key
        if self.hmac_secret is not None and self.hmac_secret != self.token:
            raise ValueError("Ingest HMAC v1 uses the scoped bearer token as its signing key")
        if self.signed or self.hmac_secret is not None:
            timestamp = str(int(time.time()))
            nonce = secrets.token_hex(16)
            canonical = "\n".join(("cajeerlogs-ingest-v1", "POST", parsed.path, timestamp, nonce,
                                   idempotency_key or "", hashlib.sha256(body).hexdigest()))
            signature = hmac.new(self.token.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest()
            headers.update({"X-Log-Timestamp": timestamp, "X-Log-Nonce": nonce, "X-Log-Signature": signature})
        request = urllib.request.Request(url, data=body, headers=headers, method="POST")
        with urllib.request.build_opener(_NoRedirect()).open(request, timeout=self.timeout) as response:
            return json.loads(response.read().decode("utf-8"))
