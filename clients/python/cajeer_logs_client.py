from __future__ import annotations

import json
import time
import hmac
import hashlib
import urllib.request
from dataclasses import dataclass, field
from typing import Any


@dataclass
class CajeerLogsClient:
    base_url: str
    token: str
    hmac_secret: str | None = None
    timeout: int = 10

    def ingest(self, event: dict[str, Any] | list[dict[str, Any]]) -> dict[str, Any]:
        payload = {"events": event} if isinstance(event, list) else event
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.token}",
        }
        if self.hmac_secret:
            timestamp = str(int(time.time()))
            nonce = hashlib.sha256(f"{timestamp}:{body!r}".encode()).hexdigest()
            signature = hmac.new(self.hmac_secret.encode(), body, hashlib.sha256).hexdigest()
            headers.update({"X-Log-Timestamp": timestamp, "X-Log-Nonce": nonce, "X-Log-Signature": signature})
        request = urllib.request.Request(f"{self.base_url.rstrip('/')}/api/v1/ingest", data=body, headers=headers, method="POST")
        with urllib.request.urlopen(request, timeout=self.timeout) as response:
            return json.loads(response.read().decode("utf-8"))
