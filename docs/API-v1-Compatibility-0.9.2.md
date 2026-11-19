# API v1 — compatibility and executable acceptance (0.9.2)

CajeerLogs serves the API under `/api/v1`. The two published `api/openapi.yaml`
and `openapi.yaml` files are identical JSON-formatted OpenAPI 3.1 specifications.
The 0.9.1 specification is retained byte-for-byte in `api/v1-baseline-0.9.1.json.gz`;
`scripts/api_contract.py` checks its SHA-256 before making any compatibility claim.
The baseline must **not** be regenerated during a v1 maintenance release.

## Run against the shipped distribution

```sh
python3 scripts/api_contract.py
python3 tests/api_contract.py
python3 tests/sdk_wire.py
python3 tests/sdk_async_wire.py
```

The contract gate reads the current OpenAPI, resolves local `$ref` definitions,
checks every prior operation, request and response schema for incompatible changes,
then **boots the shipped PHP application** and executes its registered Router.
All operations are tested using `OPTIONS` / `Allow`, without touching a production
DB; negative-path tests cover 400, 404, 405, 422, 503 and 500, including
`X-Request-ID`, JSON error schema and no disclosure of internal exception text.
The second command deliberately mutates required fields, status codes, query
parameters, bounds and response fields and asserts the gate rejects them.
The SDK tests send real HTTP requests using PHP, Python, Node/TypeScript and
Kotlin SDKs to a disposable loopback test server; no operator credentials needed.

A green route/schema gate is not a proof of successful authorized database
operations: release CI also runs real PostgreSQL-backed API, SDK, installer,
queue, browser, upgrade and rollback tests. Production certification additionally
requires the strict supply-chain, load, DR and provenance gates.

## Stable client expectations

- A failure returns JSON with nonempty `error`, `message` and `request_id`,
  and the `X-Request-ID` header agrees with `request_id`. Some validation
  failures additionally include bounded `details`. Server errors never expose
  stack traces, raw credentials or exception messages.
- Successful synchronous ingest responds 201, or 200 for a confirmed replay.
  Asynchronous admission responds 202, or 200 on replay; 202 **does not**
  mean logs have been inserted. Poll the job endpoint until completion.
- A retry after a lost receipt uses **identical event bytes and the original
  Idempotency-Key**; signed retries use a fresh HMAC nonce. Redirects do not
  result in credential forwarding.
- For status errors, PHP `DeliveryException` and TypeScript `DeliveryError`
  provide optional `apiError` and `requestId`. The Python SDK preserves
  `urllib.error.HTTPError` and attaches the corresponding safe `api_error`
  and `request_id` attributes when the response is a valid bounded JSON
  API error. Do not use human-readable `message` to drive retry decisions.
- Only 408, 429, 500, 502, 503 and 504 responses are retryable; 4xx
  authentication and validation failures require client/operator correction.

The contract gate is intentionally conservative; changing an enum, pattern,
required property, response content type, authentication requirement or
existing field definition may require a new major API namespace instead of
silently changing v1. Any backwards-compatible additions must still have
real handler and SDK integration tests.
