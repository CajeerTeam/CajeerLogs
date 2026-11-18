"""CajeerLogs v1 client. No third-party dependencies."""
from __future__ import annotations

import hashlib
import http.client
import hmac
import json
import logging
import math
import re
import secrets
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class DeliveryError(Exception):
    """Delivery failed or its outcome is unknown. Retry the SAME body and key."""
    def __init__(self, status: int | None, key: str):
        self.status, self.idempotency_key = status, key
        super().__init__(f"CajeerLogs delivery failed (HTTP {status if status is not None else 'unknown'}); key={key}")



class JobPending(Exception):
    """An accepted job is not yet confirmed complete; keep durable body/key."""
    def __init__(self, job_id: str):
        self.job_id = job_id
        super().__init__(f"Async job {job_id} remains unconfirmed")


class JobFailed(Exception):
    """A terminal dead/cancelled job requires operator action; never auto-resubmit."""
    def __init__(self, job_id: str, status: str):
        self.job_id, self.status = job_id, status
        super().__init__(f"Async job {job_id} reached {status}")


_JOB_ID = re.compile(r"[1-9][0-9]{0,18}")
_UUID = re.compile(r"[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}")
_JOB_STATES = {"pending", "running", "completed", "dead", "cancelled"}


def valid_job_id(value: Any) -> bool:
    return isinstance(value, str) and _JOB_ID.fullmatch(value) is not None and int(value) <= 9223372036854775807


def validate_async_receipt(receipt: Any, count: int, status: int) -> dict[str, Any]:
    if (not isinstance(receipt, dict) or status not in (200, 202)
            or receipt.get("accepted") is not True or type(receipt.get("received")) is not int
            or receipt["received"] != count or receipt.get("replayed") is not (status == 200)
            or not valid_job_id(receipt.get("job_id")) or receipt.get("status") not in _JOB_STATES):
        raise ValueError("Invalid async admission receipt")
    return receipt


def validate_job_status(value: Any, expected_id: str, expected_count: int | None = None) -> dict[str, Any]:
    if (not isinstance(value, dict) or value.get("id") != expected_id
            or value.get("status") not in _JOB_STATES
            or type(value.get("attempts")) is not int or type(value.get("max_attempts")) is not int
            or value["attempts"] < 0 or value["max_attempts"] < 1
            or value["attempts"] > value["max_attempts"]):
        raise ValueError("Invalid job status")
    result = value.get("result")
    if value["status"] == "completed":
        if (not isinstance(result, dict) or not isinstance(result.get("event_ids"), list)
                or not 1 <= len(result["event_ids"]) <= 100
                or type(result.get("stored")) is not int or result["stored"] != len(result["event_ids"])
                or (expected_count is not None and result["stored"] != expected_count)
                or any(not isinstance(i, str) or _UUID.fullmatch(i) is None for i in result["event_ids"])
                or len(set(result["event_ids"])) != len(result["event_ids"])):
            raise ValueError("Invalid completed job result")
    elif result is not None:
        raise ValueError("Unexpected result for unfinished job")
    return value


_FIELDS = {"timestamp", "source", "level", "message", "context", "service", "host", "trace_id", "request_id", "tags"}
_LEVELS = {"debug", "info", "notice", "warning", "error", "critical", "alert", "emergency"}
_TIMESTAMP = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])")


def validate_event(event: dict[str, Any]) -> None:
    def text(value, limit):
        return isinstance(value, str) and bool(value.strip(" \t\r\n\0\v")) and len(value.encode("utf-8")) <= limit
    if not isinstance(event, dict) or event.keys() - _FIELDS:
        raise ValueError("Invalid event fields")
    source, level, message = (event.get(k) for k in ("source", "level", "message"))
    if not text(source, 100) or not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", source) or not text(level, 32) or level.lower() not in _LEVELS or not text(message, 16384):
        raise ValueError("Invalid required event field")
    stamp = event.get("timestamp")
    if not isinstance(stamp, str) or not _TIMESTAMP.fullmatch(stamp):
        raise ValueError("Invalid timestamp")
    try:
        datetime.fromisoformat(stamp.replace("Z", "+00:00")).astimezone(timezone.utc)
    except (ValueError, OverflowError):
        raise ValueError("Invalid timestamp") from None
    for field, limit in (("service",255),("host",255),("trace_id",128),("request_id",128)):
        if event.get(field) is not None and not text(event[field], limit):
            raise ValueError("Invalid optional event field")
    if "context" in event and not isinstance(event["context"], dict):
        raise ValueError("Invalid context")
    if "tags" in event and (not isinstance(event["tags"], list) or len(event["tags"]) > 32 or any(not text(t,128) for t in event["tags"])):
        raise ValueError("Invalid tags")
    nodes = 0
    def walk(value, depth):
        nonlocal nodes
        nodes += 1
        if depth > 24 or nodes > 8192:
            raise ValueError("Event structure exceeds limits")
        if isinstance(value, str):
            value.encode("utf-8")
            if re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", value):
                raise ValueError("Invalid string")
        elif isinstance(value, (dict,list)):
            if len(value) > 2048:
                raise ValueError("Event structure exceeds limits")
            if isinstance(value, dict):
                for key, child in value.items():
                    if not isinstance(key, str) or len(key.encode("utf-8")) > 256 or re.search(r"[\x00-\x1f\x7f]",key):
                        raise ValueError("Invalid key")
                    walk(child, depth+1)
            else:
                for child in value: walk(child, depth+1)
        elif value is not None and (not isinstance(value,(bool,int,float)) or isinstance(value,float) and not math.isfinite(value) or type(value) is int and len(str(value))>1024):
            raise ValueError("Unsupported JSON value")
    walk(event, 0)
    def size(v): return len(json.dumps(v,ensure_ascii=False,allow_nan=False,separators=(",",":")).encode("utf-8"))
    if size(event) > 65536 or size(event.get("context",{})) > 32768:
        raise ValueError("Event or context exceeds size limit")


def encode(event: dict[str, Any] | list[dict[str, Any]]) -> bytes:
    payload = {"events": event} if isinstance(event, list) else event
    if not isinstance(payload, dict):
        raise ValueError("Expected event or events")
    if "events" in payload and len(payload) != 1:
        raise ValueError("Invalid batch wrapper")
    events = payload.get("events", [payload])
    if not isinstance(events, list) or not 1 <= len(events) <= 100 or any(not isinstance(e, dict) for e in events):
        raise ValueError("Expected 1..100 events")
    for item in events: validate_event(item)
    body = json.dumps(payload, ensure_ascii=False, allow_nan=False, separators=(",", ":")).encode("utf-8")
    if len(body) > 1048576:
        raise ValueError("Ingest body exceeds 1 MiB")
    return body


def validate_receipt(receipt: Any, count: int, status: int) -> dict[str, Any]:
    replay = status == 200
    if (not isinstance(receipt, dict) or receipt.get("accepted") is not True
            or type(receipt.get("received")) is not int or receipt.get("received") != count
            or type(receipt.get("stored")) is not int or receipt.get("stored") != (0 if replay else count)
            or type(receipt.get("rejected")) is not int or receipt.get("rejected") != 0 or receipt.get("replayed") is not replay
            or not isinstance(receipt.get("event_ids"), list) or len(receipt["event_ids"]) != count
            or any(not isinstance(i, str) or not re.fullmatch(r"[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}", i) for i in receipt["event_ids"])
            or len(set(receipt["event_ids"])) != count):
        raise ValueError("Invalid ingest receipt")
    return receipt


@dataclass
class CajeerLogsClient:
    base_url: str
    token: str = field(repr=False)
    hmac_secret: str | None = field(default=None, repr=False)
    timeout: float = 10
    signed: bool = False
    api_prefix: str = "/api/v1"
    attempts: int = 3
    allow_insecure_http: bool = False
    signing_key_id: str | None = None
    ca_file: str | None = None

    def __post_init__(self):
        parsed = urllib.parse.urlsplit(self.base_url)
        if (parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password
                or parsed.query or parsed.fragment or parsed.path not in ("", "/")
                or not re.fullmatch(r"/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*", self.api_prefix)):
            raise ValueError("Invalid CajeerLogs base URL or API prefix")
        if parsed.scheme == "http" and parsed.hostname not in ("localhost", "127.0.0.1", "::1") and not self.allow_insecure_http:
            raise ValueError("HTTPS required outside loopback")
        if not re.fullmatch(r"[A-Za-z0-9._~-]{1,512}", self.token):
            raise ValueError("Invalid bearer token")
        if self.signing_key_id is not None:
            if not re.fullmatch(r"[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}", self.signing_key_id) or not re.fullmatch(r"sk_[a-f0-9]{64}", self.hmac_secret or ""):
                raise ValueError("Invalid separate signing configuration")
        elif self.hmac_secret is not None and self.hmac_secret != self.token:
            raise ValueError("Ingest HMAC v1 uses the scoped bearer token as its signing key")
        if not isinstance(self.attempts, int) or not 1 <= self.attempts <= 10 or not math.isfinite(self.timeout) or not 0 < self.timeout <= 120:
            raise ValueError("Invalid delivery limits")

    def ingest(self, event: dict[str, Any] | list[dict[str, Any]], *, idempotency_key: str | None = None) -> dict[str, Any]:
        return self.send_body(encode(event), idempotency_key or secrets.token_hex(16))

    def ingest_async(self, event: dict[str, Any] | list[dict[str, Any]], *, idempotency_key: str | None = None) -> dict[str, Any]:
        """Durably enqueue; HTTP 202 confirms the job, not the event INSERT."""
        return self.send_async_body(encode(event), idempotency_key or secrets.token_hex(16))

    def send_body(self, body: bytes, key: str) -> dict[str, Any]:
        """Synchronous ingest; immutable bytes are safe to retry after a lost response."""
        return self._post_body(body, key, False)

    def send_async_body(self, body: bytes, key: str) -> dict[str, Any]:
        """Async enqueue; persist body/key before calling if recovery across restarts is required."""
        return self._post_body(body, key, True)

    def _post_body(self, body: bytes, key: str, async_mode: bool) -> dict[str, Any]:
        if not isinstance(body, bytes) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}", key) or not 0 < len(body) <= 1048576:
            raise ValueError("Invalid body or idempotency key")
        payload = json.loads(body)
        if not isinstance(payload, dict) or ("events" in payload and len(payload) != 1):
            raise ValueError("Invalid serialized event batch")
        events = payload.get("events", [payload])
        if not isinstance(events, list) or not 1 <= len(events) <= 100:
            raise ValueError("Invalid serialized event batch")
        count = len(events)
        url = f"{self.base_url.rstrip('/')}{self.api_prefix}/ingest" + ("/async" if async_mode else "")
        path = urllib.parse.urlsplit(url).path
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=self.ca_file)))
        for attempt in range(self.attempts):
            headers = {"Content-Type": "application/json", "Authorization": f"Bearer {self.token}", "Idempotency-Key": key}
            if self.signed or self.hmac_secret is not None:
                timestamp, nonce = str(int(time.time())), secrets.token_hex(16)
                prefix = ("cajeerlogs-ingest-v1",) if self.signing_key_id is None else ("cajeerlogs-ingest-v2", self.signing_key_id)
                canonical = "\n".join((*prefix, "POST", path, timestamp, nonce, key, hashlib.sha256(body).hexdigest()))
                if self.signing_key_id is not None: headers["X-Log-Key-Id"] = self.signing_key_id
                headers.update({"X-Log-Timestamp": timestamp, "X-Log-Nonce": nonce,
                                "X-Log-Signature": hmac.new((self.hmac_secret or self.token).encode(), canonical.encode(), hashlib.sha256).hexdigest()})
            request = urllib.request.Request(url, data=body, headers=headers, method="POST")
            status, wait = None, 0.25 * 2 ** attempt
            try:
                with opener.open(request, timeout=self.timeout) as response:
                    status = response.status
                    raw = response.read(65537)
                    if len(raw) > 65536: raise ValueError("Oversized ingest response")
                    receipt = json.loads(raw)
                    if async_mode and status in (200, 202): return validate_async_receipt(receipt, count, status)
                    if not async_mode and status in (200, 201): return validate_receipt(receipt, count, status)
            except urllib.error.HTTPError as exc:
                status = exc.code
                retry_after = exc.headers.get("Retry-After", "")
                exc.close()
                if status not in (408, 429, 500, 502, 503, 504):
                    raise exc
                if retry_after.isdigit(): wait = max(wait, int(retry_after))
            except (urllib.error.URLError, TimeoutError, OSError, ValueError, http.client.HTTPException):
                pass
            if attempt + 1 == self.attempts or wait > 30:
                raise DeliveryError(status, key)
            time.sleep(wait + secrets.randbelow(100) / 1000)
        raise AssertionError("unreachable")

    def job_status(self, job_id: str, *, expected_count: int | None = None) -> dict[str, Any]:
        """Read authenticated durable state. No redirect, no unbounded body."""
        if not valid_job_id(job_id): raise ValueError("Invalid job ID")
        url = f"{self.base_url.rstrip('/')}{self.api_prefix}/ingest/jobs/{job_id}"
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=self.ca_file)))
        request = urllib.request.Request(url, headers={"Authorization": f"Bearer {self.token}"}, method="GET")
        with opener.open(request, timeout=self.timeout) as response:
            if response.status != 200: raise ValueError("Unexpected job response")
            raw = response.read(65537)
            if len(raw) > 65536: raise ValueError("Oversized job response")
            return validate_job_status(json.loads(raw), job_id, expected_count)

    def wait_for_job(self, receipt: dict[str, Any], *, timeout: float = 30, poll_interval: float = 0.5) -> dict[str, Any]:
        """Confirm committed event IDs. On timeout keep the receipt and retry polling later."""
        if not 0 < timeout <= 3600 or not 0.05 <= poll_interval <= 30:
            raise ValueError("Invalid polling limits")
        job_id = receipt.get("job_id") if isinstance(receipt, dict) else None
        count = receipt.get("received") if isinstance(receipt, dict) else None
        if not valid_job_id(job_id) or type(count) is not int or not 1 <= count <= 100:
            raise ValueError("Invalid async receipt")
        deadline = time.monotonic() + timeout
        while True:
            try:
                state = self.job_status(job_id, expected_count=count)
                if state["status"] == "completed": return state
                if state["status"] in ("dead", "cancelled"):
                    raise JobFailed(job_id, state["status"])
            except urllib.error.HTTPError as exc:
                code = exc.code
                exc.close()
                if code not in (408, 429, 500, 502, 503, 504): raise
            except (urllib.error.URLError, TimeoutError, OSError, http.client.HTTPException):
                pass
            remaining = deadline - time.monotonic()
            if remaining <= 0: raise JobPending(job_id)
            time.sleep(min(poll_interval, remaining))


class CajeerLogsHandler(logging.Handler):
    """Synchronous stdlib logging adapter. Errors use logging.handleError."""
    def __init__(self, client: CajeerLogsClient, source: str, service: str | None = None):
        super().__init__()
        self.client, self.source, self.service = client, source, service

    def emit(self, record: logging.LogRecord):
        try:
            level = "critical" if record.levelno >= 50 else "error" if record.levelno >= 40 else "warning" if record.levelno >= 30 else "info" if record.levelno >= 20 else "debug"
            event = {"timestamp": datetime.fromtimestamp(record.created, timezone.utc).isoformat(timespec="microseconds"),
                     "source": self.source, "level": level, "message": self.format(record),
                     "context": {"logger": record.name}}
            if self.service:
                event["service"] = self.service
            self.client.ingest(event)
        except Exception:
            self.handleError(record)
