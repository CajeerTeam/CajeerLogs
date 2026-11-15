<?php
declare(strict_types=1);
return ['idempotency_seconds'=>env('INGEST_IDEMPOTENCY_SECONDS','604800'),'require_hmac'=>env('INGEST_REQUIRE_HMAC',false),'max_inflight'=>env('INGEST_MAX_INFLIGHT','16'),'budget_ms'=>env('INGEST_BUDGET_MS','25000'),'max_event_age_seconds'=>env('INGEST_MAX_EVENT_AGE_SECONDS','0'),'max_event_future_seconds'=>env('INGEST_MAX_EVENT_FUTURE_SECONDS','0')];
