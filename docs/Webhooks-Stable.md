# CajeerLogs 0.6.7 — Webhooks Stable

The 0.6.7 release extends the existing transactional PostgreSQL outbox and HTTPS delivery worker. It adds a functional project-scoped Admin Panel page and API for managing subscriptions, event filters, test delivery, attempt history, retry, and audit. It does **not** replace the worker with a synchronous web request or claim exactly-once delivery.

## Upgrade

1. Back up the database using the existing trusted backup workflow. Apply the 0.6.7 changed-files archive **over the complete 0.6.6 installation**, preserving environment files, private storage and installed dependencies.
2. On the target server run `php bin/cajeer migrate`. This applies `migrations/pgsql/0026_webhook_subscriptions.sql`, retaining existing subscriptions as enabled with `events=['logs.ingested']` and `revision=1`.
3. Load the same `CAJEER_WEBHOOK_*` environment secret variables in the API/CLI and the worker (minimum 32 bytes). Do not place secret values in the Admin Panel or API payloads; only send their variable names. Use private environment storage (0600).
4. Keep the deployed `webhook:work` process running through the documented systemd/cron unit. API operations enqueue delivery records but **do not send immediately**. Rotate workers after changing signing secrets.
5. Open **Admin Panel → Webhooks** and choose a project. The page requires `services:read`; writes require `services:manage`, a valid session, CSRF token, and reauthentication when required by the existing identity policy. Audit records track management actions without recording secret values.

## Event types

| Event | When generated | Metadata |
|---|---|---|
| `logs.ingested` | A committed, non-duplicate ingestion batch | `project_id`, `event_ids` |
| `source.created` | A project source is created through the management API | `project_id`, `source_id` |
| `source.disabled` | A source is disabled through the management API | `project_id`, `source_id`, `status` |
| `source.enabled` | A source is enabled through the management API | `project_id`, `source_id`, `status` |
| `source.archived` | A source is archived through the management API | `project_id`, `source_id`, `status` |
| `source.restored` | A source is restored through the management API | `project_id`, `source_id`, `status` |
| `webhook.test` | A test notification is explicitly enqueued | `project_id`, `sent_at` |

`webhook.test` is a special operator probe, not a selectable event filter. Existing CLI-created subscriptions are migrated to only `logs.ingested`. Source lifecycle events occur on management API mutations, not when administrators directly modify database rows. The outbox producer writes inside the event transaction: failure to persist required notifications rolls back the originating transaction.

Only metadata, not original log messages, contexts, bearer tokens or HMAC secrets, leaves the API in webhook events. Consumers needing details should use their own scoped read token.

## API

All endpoints are prefixed with `/api/v1`; `{project}`, `{webhook}` and `{delivery}` are UUIDs. All responses are JSON with `Cache-Control: no-store`, except documented 404/409/422 errors; do not cache. Read-only calls need a session with `services:read`; mutating calls require `services:manage`, session CSRF protection and fresh authentication when configured.

- `GET /projects/{project}/webhooks`: list up to 100 subscriptions and supported events.
- `POST /projects/{project}/webhooks`: `{ "url": "https://receiver.example.com/hook", "secret_env": "CAJEER_WEBHOOK_RECEIVER", "events": ["logs.ingested"] }`.
- `GET /projects/{project}/webhooks/{webhook}`: project-scoped detail.
- `PATCH /projects/{project}/webhooks/{webhook}`: `{ "revision": 1, "enabled": false }`, or update `url`, `secret_env`, `events`. A stale revision returns 409. Disabling cancels pending deliveries, not already in-flight requests; reenable does not automatically revive cancelled deliveries.
- `GET /projects/{project}/webhooks/stats`: bounded aggregate status counts per subscription.
- `POST /projects/{project}/webhooks/{webhook}/test`: queues a real signed `webhook.test` delivery, returns 202 with `delivery_id`; does not contact the receiver during the API call.
- `GET /projects/{project}/webhooks/{webhook}/deliveries?limit=50`: last 1–100 delivery summaries (no body or credentials).
- `GET /projects/{project}/webhooks/{webhook}/attempts`: last 100 transport attempts, outcomes and HTTP response codes.
- `POST /projects/{project}/webhooks/{webhook}/deliveries/{delivery}/retry`: retries **failed** deliveries of this project and subscription using the same delivery ID and original stored payload; success and pending deliveries cannot be replayed.

Subscriptions accept HTTPS DNS names on 443 only, with no credentials, query or fragment. Delivery uses pinned public IPv4 DNS resolution, TLS certificate verification, no redirects and no proxies. Configure a suitable outbound firewall. Duplicate URL within a project is rejected. Up to 20 subscriptions can be active per project, with 100 total retained per project. No more than 10 test deliveries per subscription may be in flight or pending.

## Receiving and deduplication

POST headers: `X-Cajeer-Delivery` (UUID), `X-Cajeer-Timestamp` (Unix seconds), `X-Cajeer-Signature` (lowercase hex SHA-256 HMAC). Compute HMAC over:

```
cajeerlogs-webhook-v1\nDELIVERY_ID\nTIMESTAMP\nSHA256_HEX_OF_EXACT_BODY
```

The signing key is the raw value of `secret_env`. Enforce a 300-second timestamp window and constant-time signature comparison. Deduplicate `X-Cajeer-Delivery` with the receiver's own persistent side effect **before** returning 2xx. The worker is at-least-once: after a network response loss it may send the same ID again. Response 2xx completes delivery; 408, 425, 429, selected 5xx and transport errors are retried up to a bounded limit with exponential backoff. Others become failed. Manual retry retains the delivery ID, attempt history and original event body.

When changing URL or signing secret, verify the new receiver first and coordinate with operators: **queued deliveries use the current subscription endpoint and key**, not historical endpoint credentials. Draining the queue before changing endpoints avoids unintended routing. Do not delete previous secrets until the receiver and pending deliveries are reconciled. Disable is not a request-cancellation mechanism for already running network requests.

## Operations and acceptance

- Monitor the Webhooks page or `php bin/cajeer webhook:list`, `webhook:history UUID`, `webhook:work 20`, `webhook:cleanup`.
- A scheduler/timer is required for `webhook:work`; watch `webhook_pending`, `webhook_failed` metrics, worker heartbeat and audit errors.
- CI runs `php tests/webhooks.php`, `CAJEER_TEST_DATABASE=1 php tests/webhook_database.php`, and `CAJEER_TEST_DATABASE=1 php tests/webhook_subscriptions_database.php`, plus upgrade checks from a real 0.6.6 source tree.
- Production readiness additionally requires actual PostgreSQL 17+, the supported PHP matrix, a reachable HTTPS receiver, egress tests and the full release certification pipeline. The local unit tests alone do not certify external delivery.
