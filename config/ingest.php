<?php
declare(strict_types=1);
return ['require_hmac'=>env('INGEST_REQUIRE_HMAC',false),'max_inflight'=>env('INGEST_MAX_INFLIGHT','16'),'budget_ms'=>env('INGEST_BUDGET_MS','25000')];
