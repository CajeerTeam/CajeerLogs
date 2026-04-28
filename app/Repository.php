<?php
declare(strict_types=1);

namespace CajeerLogs;

use PDO;
use Throwable;

final class Repository
{
    private const LEVELS = ['TRACE', 'DEBUG', 'INFO', 'WARNING', 'WARN', 'ERROR', 'CRITICAL', 'AUDIT', 'SECURITY'];

    public function __construct(private readonly PDO $pdo) {}

    public function findBotByToken(string $rawToken): ?array
    {
        $hash = Security::tokenHash($rawToken);
        $stmt = $this->pdo->prepare('SELECT * FROM bot_tokens WHERE token_hash = :hash AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $this->touchBot((int)$row['id']);
        return $row;
    }

    public function createBotToken(string $bot, string $project, string $environment, ?string $description = null, array $options = []): array
    {
        $raw = Security::randomToken();
        $hash = Security::tokenHash($raw);
        $rateLimit = max(0, (int)($options['rate_limit_per_minute'] ?? 120));
        $maxBatch = max(1, min(500, (int)($options['max_batch_size'] ?? 100)));
        $eventsLimit = max(0, (int)($options['events_limit_per_minute'] ?? Env::int('INGEST_MAX_EVENTS_PER_MINUTE', 3000)));
        $bytesLimit = max(0, (int)($options['bytes_limit_per_minute'] ?? Env::int('INGEST_MAX_BYTES_PER_MINUTE', 10485760)));
        $allowedLevels = $this->normalizeAllowedLevels($options['allowed_levels'] ?? null);
        $requireSignature = array_key_exists('require_signature', $options) ? (!empty($options['require_signature']) ? 1 : 0) : (Env::bool('INGEST_REQUIRE_SIGNATURE', true) ? 1 : 0);
        $driver = Database::driver();
        $now = $driver === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        $returning = $driver === 'pgsql' ? ' RETURNING id' : '';
        $sql = "INSERT INTO bot_tokens (project, bot, environment, description, token_hash, is_active, rate_limit_per_minute, max_batch_size, events_limit_per_minute, bytes_limit_per_minute, allowed_levels, require_signature, created_at, updated_at) VALUES (:project, :bot, :environment, :description, :token_hash, 1, :rate_limit_per_minute, :max_batch_size, :events_limit_per_minute, :bytes_limit_per_minute, :allowed_levels, :require_signature, {$now}, {$now}){$returning}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'project' => $project,
            'bot' => $bot,
            'environment' => $environment,
            'description' => $description,
            'token_hash' => $hash,
            'rate_limit_per_minute' => $rateLimit,
            'max_batch_size' => $maxBatch,
            'events_limit_per_minute' => $eventsLimit,
            'bytes_limit_per_minute' => $bytesLimit,
            'allowed_levels' => $allowedLevels,
            'require_signature' => $requireSignature,
        ]);
        $id = $driver === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
        return ['id' => $id, 'raw_token' => $raw, 'token_hash' => $hash];
    }

    public function rotateBotToken(int $id): ?array
    {
        $bot = $this->botTokenById($id);
        if (!$bot) {
            return null;
        }
        $raw = Security::randomToken();
        $hash = Security::tokenHash($raw);
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE bot_tokens SET token_hash = :hash, is_active = 1, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL'
            : 'UPDATE bot_tokens SET token_hash = :hash, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL';
        $this->pdo->prepare($sql)->execute(['hash' => $hash, 'id' => $id]);
        return array_merge($bot, ['id' => $id, 'raw_token' => $raw, 'token_hash' => $hash]);
    }

    public function setBotTokenActive(int $id, bool $active): bool
    {
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE bot_tokens SET is_active = :active, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL'
            : 'UPDATE bot_tokens SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function softDeleteBotToken(int $id): bool
    {
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE bot_tokens SET is_active = 0, deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL'
            : 'UPDATE bot_tokens SET is_active = 0, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function botTokenById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bot_tokens WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function touchBot(int $id): void
    {
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE bot_tokens SET last_used_at = NOW(), updated_at = NOW() WHERE id = :id'
            : 'UPDATE bot_tokens SET last_used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }

    public function isRateLimited(array $botToken): bool
    {
        return $this->ingestRateLimitViolation($botToken, 0, 0) !== null;
    }

    /** @return array{error:string,message:string}|null */
    public function ingestRateLimitViolation(array $botToken, int $eventCount, int $bodyBytes): ?array
    {
        $tokenId = (int)$botToken['id'];
        $requestLimit = (int)($botToken['rate_limit_per_minute'] ?? 0);
        if ($requestLimit > 0) {
            if (Database::driver() === 'pgsql') {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ingest_batches WHERE bot_token_id = :id AND received_at >= NOW() - INTERVAL '60 seconds'");
            } else {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ingest_batches WHERE bot_token_id = :id AND datetime(received_at) >= datetime('now', '-60 seconds')");
            }
            $stmt->execute(['id' => $tokenId]);
            if ((int)$stmt->fetchColumn() >= $requestLimit) {
                return ['error' => 'rate_limited', 'message' => 'Превышен лимит запросов для токена.'];
            }
        }

        $eventsLimit = (int)($botToken['events_limit_per_minute'] ?? Env::int('INGEST_MAX_EVENTS_PER_MINUTE', 3000));
        if ($eventsLimit > 0) {
            if (Database::driver() === 'pgsql') {
                $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(event_count), 0) FROM ingest_batches WHERE bot_token_id = :id AND received_at >= NOW() - INTERVAL '60 seconds'");
            } else {
                $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(event_count), 0) FROM ingest_batches WHERE bot_token_id = :id AND datetime(received_at) >= datetime('now', '-60 seconds')");
            }
            $stmt->execute(['id' => $tokenId]);
            if (((int)$stmt->fetchColumn() + max(0, $eventCount)) > $eventsLimit) {
                return ['error' => 'events_rate_limited', 'message' => 'Превышен лимит событий в минуту для токена.'];
            }
        }

        $bytesLimit = (int)($botToken['bytes_limit_per_minute'] ?? Env::int('INGEST_MAX_BYTES_PER_MINUTE', 10485760));
        if ($bytesLimit > 0) {
            $used = 0;
            if (Database::driver() === 'pgsql') {
                $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(COALESCE((meta->>'body_bytes')::integer, 0)), 0) FROM ingest_batches WHERE bot_token_id = :id AND received_at >= NOW() - INTERVAL '60 seconds'");
                $stmt->execute(['id' => $tokenId]);
                $used = (int)$stmt->fetchColumn();
            } else {
                try {
                    $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(COALESCE(CAST(json_extract(meta, '$.body_bytes') AS INTEGER), 0)), 0) FROM ingest_batches WHERE bot_token_id = :id AND datetime(received_at) >= datetime('now', '-60 seconds')");
                    $stmt->execute(['id' => $tokenId]);
                    $used = (int)$stmt->fetchColumn();
                } catch (Throwable) {
                    $stmt = $this->pdo->prepare("SELECT meta FROM ingest_batches WHERE bot_token_id = :id AND datetime(received_at) >= datetime('now', '-60 seconds')");
                    $stmt->execute(['id' => $tokenId]);
                    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $metaRaw) {
                        $meta = json_decode((string)$metaRaw, true);
                        if (is_array($meta)) {
                            $used += max(0, (int)($meta['body_bytes'] ?? 0));
                        }
                    }
                }
            }
            if (($used + max(0, $bodyBytes)) > $bytesLimit) {
                return ['error' => 'bytes_rate_limited', 'message' => 'Превышен лимит объёма ingest-данных в минуту для токена.'];
            }
        }

        return null;
    }

    public function rememberNonce(array $botToken, string $nonce, string $timestamp): bool
    {
        $hash = (string)$botToken['token_hash'];
        $ts = is_numeric($timestamp) ? gmdate('Y-m-d H:i:s', (int)$timestamp) : gmdate('Y-m-d H:i:s', strtotime($timestamp) ?: time());
        try {
            if (Database::driver() === 'pgsql') {
                $this->pdo->prepare("DELETE FROM ingest_nonces WHERE created_at < NOW() - INTERVAL '20 minutes'")->execute();
                $stmt = $this->pdo->prepare('INSERT INTO ingest_nonces (token_hash, nonce, timestamp_at, created_at) VALUES (:hash, :nonce, :ts, NOW())');
            } else {
                $this->pdo->prepare("DELETE FROM ingest_nonces WHERE datetime(created_at) < datetime('now', '-20 minutes')")->execute();
                $stmt = $this->pdo->prepare('INSERT INTO ingest_nonces (token_hash, nonce, timestamp_at, created_at) VALUES (:hash, :nonce, :ts, CURRENT_TIMESTAMP)');
            }
            $stmt->execute(['hash' => $hash, 'nonce' => $nonce, 'ts' => $ts]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function validateEventsForBot(array $botToken, array $events): array
    {
        $allowedLevelsRaw = trim((string)($botToken['allowed_levels'] ?? ''));
        $allowedLevels = $allowedLevelsRaw !== '' ? array_fill_keys(explode(',', $allowedLevelsRaw), true) : [];
        foreach ($events as $index => $event) {
            $level = $this->normalizeLevel((string)($event['level'] ?? 'INFO'));
            if ($allowedLevels && !isset($allowedLevels[$level])) {
                return [false, 'Событие #' . ($index + 1) . ": уровень {$level} не разрешён для токена."];
            }
            foreach (['project' => 'project', 'bot' => 'bot'] as $field => $label) {
                if (isset($event[$field]) && (string)$event[$field] !== (string)$botToken[$field]) {
                    return [false, 'Событие #' . ($index + 1) . ": поле {$label} не совпадает с токеном."];
                }
            }
            $env = (string)($event['environment'] ?? $event['env'] ?? $botToken['environment']);
            if ($env !== (string)$botToken['environment']) {
                return [false, 'Событие #' . ($index + 1) . ': окружение не совпадает с токеном.'];
            }
        }
        return [true, null];
    }

    public function insertBatch(array $botToken, array $events, array $meta = []): int
    {
        $this->pdo->beginTransaction();
        try {
            $batchId = $this->insertBatchRow((int)$botToken['id'], count($events), $meta);
            if (Database::driver() === 'pgsql' && count($events) > 1 && Env::bool('INGEST_BULK_INSERT_ENABLED', true)) {
                $rows = [];
                foreach ($events as $event) {
                    $rows[] = $this->buildLogEventRow($batchId, $botToken, $event);
                }
                $ids = $this->insertPreparedLogEventRowsPgsql($rows);
                foreach ($rows as $i => $row) {
                    if (in_array($row['level'], ['ERROR', 'CRITICAL', 'SECURITY'], true)) {
                        $this->upsertIncident($row['fingerprint'], $row['project'], $row['bot'], $row['environment'], $row['level'], $row['message'], $row['exception'], (int)($ids[$i] ?? 0));
                    }
                }
                $this->pdo->commit();
                return count($rows);
            }

            $inserted = 0;
            foreach ($events as $event) {
                $this->insertLogEvent($batchId, $botToken, $event);
                $inserted++;
            }
            $this->pdo->commit();
            return $inserted;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function insertBatchRow(int $botTokenId, int $count, array $meta): int
    {
        $metaJson = json_encode($this->limitJsonPayload($meta, 8192), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (Database::driver() === 'pgsql') {
            $stmt = $this->pdo->prepare('INSERT INTO ingest_batches (bot_token_id, event_count, meta, received_at) VALUES (:bot_token_id, :event_count, :meta, NOW()) RETURNING id');
            $stmt->execute(['bot_token_id' => $botTokenId, 'event_count' => $count, 'meta' => $metaJson]);
            return (int)$stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare('INSERT INTO ingest_batches (bot_token_id, event_count, meta, received_at) VALUES (:bot_token_id, :event_count, :meta, CURRENT_TIMESTAMP)');
        $stmt->execute(['bot_token_id' => $botTokenId, 'event_count' => $count, 'meta' => $metaJson]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertLogEvent(int $batchId, array $botToken, array $event): void
    {
        $row = $this->buildLogEventRow($batchId, $botToken, $event);
        $logId = $this->insertPreparedLogEventRow($row);
        if (in_array($row['level'], ['ERROR', 'CRITICAL', 'SECURITY'], true)) {
            $this->upsertIncident($row['fingerprint'], $row['project'], $row['bot'], $row['environment'], $row['level'], $row['message'], $row['exception'], $logId);
        }
    }

    /** @return array<string,mixed> */
    private function buildLogEventRow(int $batchId, array $botToken, array $event): array
    {
        $level = $this->normalizeLevel((string)($event['level'] ?? 'INFO'));
        $project = (string)$botToken['project'];
        $bot = (string)$botToken['bot'];
        $environment = (string)$botToken['environment'];
        $logger = isset($event['logger']) ? Redactor::redactString((string)$event['logger']) : null;
        $message = Redactor::redactString((string)($event['message'] ?? ''));
        $exception = isset($event['exception']) ? Redactor::redactString((string)$event['exception']) : null;
        $traceId = isset($event['trace_id']) ? Redactor::redactString((string)$event['trace_id']) : null;
        $version = isset($event['version']) ? Redactor::redactString((string)$event['version']) : null;
        $host = isset($event['host']) ? Redactor::redactString((string)$event['host']) : null;
        $ts = $this->normalizeTimestamp($event['ts'] ?? null);
        $context = isset($event['context']) && is_array($event['context']) ? Redactor::redactMixed($event['context']) : [];
        $hashes = $this->privacyHashes($event, $context);
        if (Env::bool('PRIVACY_HASH_IDENTIFIERS', true)) {
            foreach (['user_id', 'chat_id', 'guild_id'] as $key) {
                if (array_key_exists($key, $context)) {
                    unset($context[$key]);
                }
            }
            foreach (['user_id_hash', 'chat_id_hash', 'guild_id_hash'] as $key) {
                if (!empty($hashes[$key])) {
                    $context[$key] = $hashes[$key];
                }
            }
        }
        $contextJson = json_encode($this->limitJsonPayload($context, Env::int('INGEST_MAX_CONTEXT_BYTES', 65536)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fingerprint = $this->fingerprint($project, $bot, $environment, $level, $message, $exception, $logger);
        return [
            'batch_id' => $batchId,
            'created_at' => $ts,
            'level' => $level,
            'project' => $this->cut($project, 120),
            'bot' => $this->cut($bot, 120),
            'environment' => $this->cut($environment, 60),
            'version' => $version ? $this->cut($version, 80) : null,
            'host' => $host ? $this->cut($host, 120) : null,
            'logger' => $logger ? $this->cut($logger, 190) : null,
            'message' => $this->cut($message, Env::int('INGEST_MAX_MESSAGE_CHARS', 8000)),
            'exception' => $exception ? $this->cut($exception, Env::int('INGEST_MAX_EXCEPTION_CHARS', 32768)) : null,
            'trace_id' => $traceId ? $this->cut($traceId, 120) : null,
            'context' => $contextJson,
            'fingerprint' => $fingerprint,
            'user_id_hash' => $hashes['user_id_hash'],
            'chat_id_hash' => $hashes['chat_id_hash'],
            'guild_id_hash' => $hashes['guild_id_hash'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function insertPreparedLogEventRow(array $row): int
    {
        $columns = '(batch_id, created_at, received_at, level, project, bot, environment, version, host, logger, message, exception, trace_id, context, fingerprint, user_id_hash, chat_id_hash, guild_id_hash)';
        $values = '(:batch_id, :created_at, ' . (Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP') . ', :level, :project, :bot, :environment, :version, :host, :logger, :message, :exception, :trace_id, :context, :fingerprint, :user_id_hash, :chat_id_hash, :guild_id_hash)';
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $stmt = $this->pdo->prepare('INSERT INTO log_events ' . $columns . ' VALUES ' . $values . $returning);
        $stmt->execute($row);
        return Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertPreparedLogEventRowsPgsql(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $columns = ['batch_id','created_at','level','project','bot','environment','version','host','logger','message','exception','trace_id','context','fingerprint','user_id_hash','chat_id_hash','guild_id_hash'];
        $values = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $parts = [];
            foreach ($columns as $column) {
                $key = $column . '_' . $i;
                $parts[] = ':' . $key;
                $params[$key] = $row[$column];
            }
            $values[] = '(' . implode(',', [$parts[0], $parts[1], 'NOW()', ...array_slice($parts, 2)]) . ')';
        }
        $sql = 'INSERT INTO log_events (batch_id, created_at, received_at, level, project, bot, environment, version, host, logger, message, exception, trace_id, context, fingerprint, user_id_hash, chat_id_hash, guild_id_hash) VALUES ' . implode(',', $values) . ' RETURNING id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function upsertIncident(string $fingerprint, string $project, string $bot, string $environment, string $level, string $message, ?string $exception, int $logId): void
    {
        $title = $this->cut($this->incidentTitle($message, $exception), 500);
        if (Database::driver() === 'pgsql') {
            $sql = "INSERT INTO incidents (fingerprint, project, bot, environment, level, title, sample_message, sample_exception, event_count, first_seen_at, last_seen_at, last_log_event_id, status) VALUES (:fingerprint, :project, :bot, :environment, :level, :title, :message, :exception, 1, NOW(), NOW(), :log_id, 'open') ON CONFLICT (project, bot, environment, level, fingerprint) DO UPDATE SET event_count = incidents.event_count + 1, last_seen_at = NOW(), last_log_event_id = EXCLUDED.last_log_event_id, sample_message = EXCLUDED.sample_message, sample_exception = EXCLUDED.sample_exception";
        } else {
            $sql = "INSERT INTO incidents (fingerprint, project, bot, environment, level, title, sample_message, sample_exception, event_count, first_seen_at, last_seen_at, last_log_event_id, status) VALUES (:fingerprint, :project, :bot, :environment, :level, :title, :message, :exception, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :log_id, 'open') ON CONFLICT(project, bot, environment, level, fingerprint) DO UPDATE SET event_count = event_count + 1, last_seen_at = CURRENT_TIMESTAMP, last_log_event_id = excluded.last_log_event_id, sample_message = excluded.sample_message, sample_exception = excluded.sample_exception";
        }
        $this->pdo->prepare($sql)->execute([
            'fingerprint' => $fingerprint,
            'project' => $project,
            'bot' => $bot,
            'environment' => $environment,
            'level' => $level,
            'title' => $title,
            'message' => $this->cut($message, 4000),
            'exception' => $exception ? $this->cut($exception, 8000) : null,
            'log_id' => $logId,
        ]);
    }

    public function recentLogs(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildLogWhere($filters);
        $limit = max(1, min(Env::int('LOGS_MAX_QUERY_LIMIT', 5000), $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM log_events ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countLogs(array $filters): int
    {
        [$where, $params] = $this->buildLogWhere($filters);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM log_events ' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function findLog(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT le.*, ib.bot_token_id, ib.meta AS batch_meta, ib.received_at AS batch_received_at FROM log_events le LEFT JOIN ingest_batches ib ON ib.id = le.batch_id WHERE le.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function filterOptions(): array
    {
        return [
            'projects' => $this->distinct('project'),
            'bots' => $this->distinct('bot'),
            'environments' => $this->distinct('environment'),
            'levels' => $this->distinct('level'),
        ];
    }

    private function distinct(string $field): array
    {
        $allowed = ['project', 'bot', 'environment', 'level'];
        if (!in_array($field, $allowed, true)) {
            return [];
        }
        $stmt = $this->pdo->query("SELECT DISTINCT {$field} AS value FROM log_events WHERE {$field} IS NOT NULL AND {$field} <> '' ORDER BY {$field} ASC LIMIT 200");
        return array_map(static fn(array $row): string => (string)$row['value'], $stmt->fetchAll());
    }

    private function buildLogWhere(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (['project', 'bot', 'environment', 'level', 'trace_id', 'fingerprint'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = $field . ' = :' . $field;
                $params[$field] = (string)$filters[$field];
            }
        }
        if (!empty($filters['q'])) {
            $op = Database::driver() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $where[] = "(message {$op} :q OR exception {$op} :q OR logger {$op} :q)";
            $params['q'] = '%' . (string)$filters['q'] . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from_dt';
            $params['from_dt'] = (string)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to_dt';
            $params['to_dt'] = (string)$filters['to'];
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function stats(): array
    {
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM log_events')->fetchColumn();
        $errors = (int)$this->pdo->query("SELECT COUNT(*) FROM log_events WHERE level IN ('ERROR','CRITICAL')")->fetchColumn();
        $bots = (int)$this->pdo->query('SELECT COUNT(*) FROM bot_tokens WHERE is_active = 1 AND deleted_at IS NULL')->fetchColumn();
        $incidents = (int)$this->pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'open'")->fetchColumn();
        $last = $this->pdo->query('SELECT MAX(created_at) FROM log_events')->fetchColumn() ?: null;
        return compact('total', 'errors', 'bots', 'incidents', 'last');
    }

    public function levels(): array
    {
        return $this->pdo->query('SELECT level, COUNT(*) AS cnt FROM log_events GROUP BY level ORDER BY cnt DESC')->fetchAll();
    }

    public function botTokens(): array
    {
        if (Database::driver() === 'pgsql') {
            $sql = "SELECT bt.id, bt.project, bt.bot, bt.environment, bt.description, bt.is_active, bt.rate_limit_per_minute, bt.max_batch_size, bt.events_limit_per_minute, bt.bytes_limit_per_minute, bt.allowed_levels, bt.require_signature, bt.created_at, bt.updated_at, bt.last_used_at, COUNT(le.id) AS total_logs, COUNT(le.id) FILTER (WHERE le.received_at >= NOW() - INTERVAL '24 hours') AS logs_24h, COUNT(le.id) FILTER (WHERE le.level IN ('ERROR','CRITICAL') AND le.received_at >= NOW() - INTERVAL '24 hours') AS errors_24h, MAX(le.received_at) FILTER (WHERE le.level IN ('ERROR','CRITICAL')) AS last_error_at FROM bot_tokens bt LEFT JOIN ingest_batches ib ON ib.bot_token_id = bt.id LEFT JOIN log_events le ON le.batch_id = ib.id WHERE bt.deleted_at IS NULL GROUP BY bt.id ORDER BY bt.project, bt.bot, bt.environment";
        } else {
            $sql = "SELECT bt.id, bt.project, bt.bot, bt.environment, bt.description, bt.is_active, bt.rate_limit_per_minute, bt.max_batch_size, bt.events_limit_per_minute, bt.bytes_limit_per_minute, bt.allowed_levels, bt.require_signature, bt.created_at, bt.updated_at, bt.last_used_at, COUNT(le.id) AS total_logs, SUM(CASE WHEN datetime(le.received_at) >= datetime('now','-24 hours') THEN 1 ELSE 0 END) AS logs_24h, SUM(CASE WHEN le.level IN ('ERROR','CRITICAL') AND datetime(le.received_at) >= datetime('now','-24 hours') THEN 1 ELSE 0 END) AS errors_24h, MAX(CASE WHEN le.level IN ('ERROR','CRITICAL') THEN le.received_at ELSE NULL END) AS last_error_at FROM bot_tokens bt LEFT JOIN ingest_batches ib ON ib.bot_token_id = bt.id LEFT JOIN log_events le ON le.batch_id = ib.id WHERE bt.deleted_at IS NULL GROUP BY bt.id ORDER BY bt.project, bt.bot, bt.environment";
        }
        return $this->pdo->query($sql)->fetchAll();
    }

    public function botHealth(): array
    {
        $rows = $this->botTokens();
        $now = time();
        foreach ($rows as &$row) {
            $last = isset($row['last_used_at']) && $row['last_used_at'] ? strtotime((string)$row['last_used_at']) : 0;
            $age = $last > 0 ? $now - $last : null;
            $row['age_seconds'] = $age;
            $row['health_status'] = $age === null ? 'never' : ($age <= 600 ? 'online' : ($age <= 3600 ? 'stale' : 'offline'));
        }
        unset($row);
        return $rows;
    }

    public function audit(string $action, ?string $entityType = null, ?string $entityId = null, ?string $message = null, array $meta = []): void
    {
        $metaJson = json_encode($this->limitJsonPayload($meta, 8192), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = 'INSERT INTO audit_events (actor, action, entity_type, entity_id, message, meta, ip, user_agent, created_at) VALUES (:actor, :action, :entity_type, :entity_id, :message, :meta, :ip, :user_agent, ' . (Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP') . ')';
        $this->pdo->prepare($sql)->execute([
            'actor' => Security::currentActor(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'message' => $message,
            'meta' => $metaJson,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function recentAudit(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query('SELECT * FROM audit_events ORDER BY created_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
    }

    public function incidents(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query('SELECT * FROM incidents ORDER BY last_seen_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
    }

    public function findIncident(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incidents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setIncidentStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare('UPDATE incidents SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function alertRules(): array
    {
        return $this->pdo->query('SELECT * FROM alert_rules ORDER BY is_active DESC, id DESC')->fetchAll();
    }

    public function findAlertRule(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM alert_rules WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createAlertRule(array $data): int
    {
        $now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $stmt = $this->pdo->prepare("INSERT INTO alert_rules (name, channel, webhook_url, project, bot, environment, levels, threshold_count, window_seconds, cooldown_seconds, is_active, created_at, updated_at) VALUES (:name, :channel, :webhook_url, :project, :bot, :environment, :levels, :threshold_count, :window_seconds, :cooldown_seconds, 1, {$now}, {$now}){$returning}");
        $stmt->execute([
            'name' => $data['name'],
            'channel' => $data['channel'],
            'webhook_url' => $data['webhook_url'],
            'project' => ($data['project'] ?? null) ?: null,
            'bot' => ($data['bot'] ?? null) ?: null,
            'environment' => ($data['environment'] ?? null) ?: null,
            'levels' => $this->normalizeAllowedLevels($data['levels'] ?? 'ERROR,CRITICAL,SECURITY') ?? 'ERROR,CRITICAL,SECURITY',
            'threshold_count' => max(1, (int)($data['threshold_count'] ?? 1)),
            'window_seconds' => max(60, (int)($data['window_seconds'] ?? 300)),
            'cooldown_seconds' => max(60, (int)($data['cooldown_seconds'] ?? 900)),
        ]);
        return Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    public function setAlertRuleActive(int $id, bool $active): bool
    {
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE alert_rules SET is_active = :active, updated_at = NOW() WHERE id = :id'
            : 'UPDATE alert_rules SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function deleteAlertRule(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM alert_rules WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function testAlertRule(int $id): array
    {
        $rule = $this->findAlertRule($id);
        if (!$rule) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'Правило не найдено.'];
        }

        $message = 'Cajeer Logs: проверка оповещения «' . (string)$rule['name'] . '». Канал доставки настроен.';
        [$ok, $response] = $this->sendAlert((string)$rule['channel'], (string)$rule['webhook_url'], $message);
        $this->recordAlertDelivery($id, $ok ? 'test_sent' : 'test_failed', $response, ['test' => true]);

        return [
            'ok' => $ok,
            'status' => $ok ? 'test_sent' : 'test_failed',
            'message' => $response,
            'rule' => (string)$rule['name'],
        ];
    }

    public function alertDeliveries(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT ad.*, ar.name AS rule_name, ar.channel AS channel FROM alert_deliveries ad LEFT JOIN alert_rules ar ON ar.id = ad.alert_rule_id ORDER BY ad.delivered_at DESC, ad.id DESC LIMIT ' . $limit;
        return $this->pdo->query($sql)->fetchAll();
    }

    public function evaluateAlertRules(): array
    {
        $deliveries = [];
        foreach ($this->alertRules() as $rule) {
            if ((int)$rule['is_active'] !== 1 || !$this->alertCooldownPassed($rule)) {
                continue;
            }
            $count = $this->countLogsForAlert($rule);
            if ($count < (int)$rule['threshold_count']) {
                continue;
            }
            $message = 'Cajeer Logs: правило «' . $rule['name'] . '» сработало. Событий: ' . $count . ' за ' . $rule['window_seconds'] . ' сек.';
            [$ok, $response] = $this->sendAlert((string)$rule['channel'], (string)$rule['webhook_url'], $message);
            $this->recordAlertDelivery((int)$rule['id'], $ok ? 'sent' : 'failed', $response, ['count' => $count]);
            if ($ok) {
                $this->touchAlertRule((int)$rule['id']);
            }
            $deliveries[] = ['rule' => $rule['name'], 'status' => $ok ? 'sent' : 'failed', 'count' => $count, 'response' => $response];
        }
        return $deliveries;
    }

    private function countLogsForAlert(array $rule): int
    {
        $where = [];
        $params = [];
        $levels = array_filter(explode(',', (string)$rule['levels']));
        if ($levels) {
            $placeholders = [];
            foreach ($levels as $i => $level) {
                $key = 'level' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $this->normalizeLevel($level);
            }
            $where[] = 'level IN (' . implode(',', $placeholders) . ')';
        }
        foreach (['project', 'bot', 'environment'] as $field) {
            if (!empty($rule[$field])) {
                $where[] = $field . ' = :' . $field;
                $params[$field] = (string)$rule[$field];
            }
        }
        if (Database::driver() === 'pgsql') {
            $where[] = "received_at >= NOW() - (:seconds || ' seconds')::interval";
        } else {
            $where[] = "datetime(received_at) >= datetime('now', '-' || :seconds || ' seconds')";
        }
        $params['seconds'] = (string)max(60, (int)$rule['window_seconds']);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM log_events WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function alertCooldownPassed(array $rule): bool
    {
        if (empty($rule['last_fired_at'])) {
            return true;
        }
        $last = strtotime((string)$rule['last_fired_at']);
        return $last === false || (time() - $last) >= (int)$rule['cooldown_seconds'];
    }

    private function sendAlert(string $channel, string $url, string $message): array
    {
        [$allowed, $reason] = Security::validateExternalWebhookUrl($url);
        if (!$allowed) {
            return [false, 'Вебхук заблокирован: ' . $reason];
        }
        $payload = $channel === 'discord' ? ['content' => $message] : ['text' => $message];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nConnection: close\r\n", 'content' => $body, 'timeout' => Env::int('ALERT_WEBHOOK_TIMEOUT_SECONDS', 5), 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0]]);
        $resp = @file_get_contents($url, false, $ctx);
        $statusLine = $http_response_header[0] ?? '';
        $code = 0;
        if (is_string($statusLine) && preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
            $code = (int)$m[1];
        }
        $ok = $code >= 200 && $code < 300;
        $prefix = $code > 0 ? 'HTTP ' . $code . ': ' : '';
        return [$ok, $prefix . (is_string($resp) && $resp !== '' ? $this->cut($resp, 1000) : 'нет ответа')];
    }

    public function recordAlertDelivery(int $ruleId, string $status, string $message, array $meta = []): void
    {
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare('INSERT INTO alert_deliveries (alert_rule_id, status, message, meta, delivered_at) VALUES (:id, :status, :message, :meta, ' . (Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP') . ')');
        $stmt->execute(['id' => $ruleId, 'status' => $status, 'message' => $message, 'meta' => $metaJson]);
    }

    private function touchAlertRule(int $id): void
    {
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE alert_rules SET last_fired_at = NOW(), updated_at = NOW() WHERE id = :id'
            : 'UPDATE alert_rules SET last_fired_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }


    public function aaPanelOffsets(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM aapanel_log_offsets ORDER BY site ASC, log_type ASC, file_path ASC');
        return $stmt->fetchAll();
    }

    public function aaPanelOffset(string $site, string $logType, string $filePath): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM aapanel_log_offsets WHERE site = :site AND log_type = :log_type AND file_path = :file_path LIMIT 1');
        $stmt->execute(['site' => $site, 'log_type' => $logType, 'file_path' => $filePath]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function saveAaPanelOffset(string $site, string $logType, string $filePath, int $offsetBytes, int $addedLines, array $meta = []): void
    {
        $offsetBytes = max(0, $offsetBytes);
        $addedLines = max(0, $addedLines);
        $inode = isset($meta['inode']) ? (int)$meta['inode'] : null;
        $fileSize = isset($meta['file_size']) ? (int)$meta['file_size'] : null;
        $firstLineHash = isset($meta['first_line_hash']) ? (string)$meta['first_line_hash'] : null;
        $lastSeenMtime = isset($meta['mtime']) ? gmdate('Y-m-d H:i:s', (int)$meta['mtime']) : null;
        $rotationDetected = !empty($meta['rotation_detected']) ? 1 : 0;
        if (Database::driver() === 'pgsql') {
            $sql = "INSERT INTO aapanel_log_offsets (site, log_type, file_path, offset_bytes, imported_lines, last_import_at, inode, file_size, first_line_hash, last_seen_mtime, rotation_detected, updated_at)
                    VALUES (:site, :log_type, :file_path, :offset_bytes, :added_lines, NOW(), :inode, :file_size, :first_line_hash, :last_seen_mtime, :rotation_detected, NOW())
                    ON CONFLICT (site, log_type, file_path)
                    DO UPDATE SET offset_bytes = EXCLUDED.offset_bytes,
                                  imported_lines = aapanel_log_offsets.imported_lines + EXCLUDED.imported_lines,
                                  last_import_at = NOW(),
                                  inode = EXCLUDED.inode,
                                  file_size = EXCLUDED.file_size,
                                  first_line_hash = EXCLUDED.first_line_hash,
                                  last_seen_mtime = EXCLUDED.last_seen_mtime,
                                  rotation_detected = EXCLUDED.rotation_detected,
                                  updated_at = NOW()";
        } else {
            $sql = "INSERT INTO aapanel_log_offsets (site, log_type, file_path, offset_bytes, imported_lines, last_import_at, inode, file_size, first_line_hash, last_seen_mtime, rotation_detected, updated_at)
                    VALUES (:site, :log_type, :file_path, :offset_bytes, :added_lines, CURRENT_TIMESTAMP, :inode, :file_size, :first_line_hash, :last_seen_mtime, :rotation_detected, CURRENT_TIMESTAMP)
                    ON CONFLICT(site, log_type, file_path)
                    DO UPDATE SET offset_bytes = excluded.offset_bytes,
                                  imported_lines = imported_lines + excluded.imported_lines,
                                  last_import_at = CURRENT_TIMESTAMP,
                                  inode = excluded.inode,
                                  file_size = excluded.file_size,
                                  first_line_hash = excluded.first_line_hash,
                                  last_seen_mtime = excluded.last_seen_mtime,
                                  rotation_detected = excluded.rotation_detected,
                                  updated_at = CURRENT_TIMESTAMP";
        }
        $this->pdo->prepare($sql)->execute([
            'site' => $this->cut($site, 190),
            'log_type' => $this->cut($logType, 20),
            'file_path' => $filePath,
            'offset_bytes' => $offsetBytes,
            'added_lines' => $addedLines,
            'inode' => $inode,
            'file_size' => $fileSize,
            'first_line_hash' => $firstLineHash,
            'last_seen_mtime' => $lastSeenMtime,
            'rotation_detected' => $rotationDetected,
        ]);
    }

    public function insertAaPanelSiteLog(string $site, string $logType, string $rawLine, array $event): int
    {
        $project = Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'));
        $bot = $site;
        $environment = Env::get('NGINX_LOG_ENVIRONMENT', Env::get('AAPANEL_LOG_ENVIRONMENT', 'production'));
        $level = $this->normalizeLevel((string)($event['level'] ?? 'INFO'));
        $logger = $this->cut((string)($event['logger'] ?? ('nginx.' . $logType)), 190);
        $message = Redactor::redactString((string)($event['message'] ?? $rawLine));
        $exception = isset($event['exception']) ? Redactor::redactString((string)$event['exception']) : null;
        $context = isset($event['context']) && is_array($event['context']) ? Redactor::redactMixed($event['context']) : [];
        $context['source'] = 'aapanel';
        $contextJson = json_encode($this->limitJsonPayload($context, Env::int('INGEST_MAX_CONTEXT_BYTES', 65536)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = $this->normalizeTimestamp($event['ts'] ?? null);
        $fingerprint = $this->fingerprint($project, $bot, $environment, $level, $message, $exception, $logger);

        $columns = '(batch_id, created_at, received_at, level, project, bot, environment, version, host, logger, message, exception, trace_id, context, fingerprint, user_id_hash, chat_id_hash, guild_id_hash)';
        $values = '(:batch_id, :created_at, ' . (Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP') . ', :level, :project, :bot, :environment, :version, :host, :logger, :message, :exception, :trace_id, :context, :fingerprint, :user_id_hash, :chat_id_hash, :guild_id_hash)';
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $stmt = $this->pdo->prepare('INSERT INTO log_events ' . $columns . ' VALUES ' . $values . $returning);
        $stmt->execute([
            'batch_id' => null,
            'created_at' => $ts,
            'level' => $level,
            'project' => $this->cut($project, 120),
            'bot' => $this->cut($bot, 120),
            'environment' => $this->cut($environment, 60),
            'version' => null,
            'host' => gethostname() ?: null,
            'logger' => $logger,
            'message' => $this->cut($message, Env::int('INGEST_MAX_MESSAGE_CHARS', 8000)),
            'exception' => $exception ? $this->cut($exception, Env::int('INGEST_MAX_EXCEPTION_CHARS', 32768)) : null,
            'trace_id' => null,
            'context' => $contextJson,
            'fingerprint' => $fingerprint,
            'user_id_hash' => null,
            'chat_id_hash' => null,
            'guild_id_hash' => null,
        ]);
        $logId = Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
        if (in_array($level, ['ERROR', 'CRITICAL', 'SECURITY'], true)) {
            $this->upsertIncident($fingerprint, $project, $bot, $environment, $level, $message, $exception, $logId);
        }
        return $logId;
    }

    public function aaPanelSiteStats(): array
    {
        if (Database::driver() === 'pgsql') {
            $sql = "SELECT bot AS site,
                           COUNT(*) AS total,
                           SUM(CASE WHEN level IN ('ERROR','CRITICAL') THEN 1 ELSE 0 END) AS errors,
                           MAX(created_at) AS last_at
                    FROM log_events
                    WHERE project = :project
                    GROUP BY bot
                    ORDER BY last_at DESC NULLS LAST, bot ASC";
        } else {
            $sql = "SELECT bot AS site,
                           COUNT(*) AS total,
                           SUM(CASE WHEN level IN ('ERROR','CRITICAL') THEN 1 ELSE 0 END) AS errors,
                           MAX(created_at) AS last_at
                    FROM log_events
                    WHERE project = :project
                    GROUP BY bot
                    ORDER BY last_at DESC, bot ASC";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['project' => Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'))]);
        return $stmt->fetchAll();
    }

    public function deleteOlderThan(string $level, int $days): int
    {
        $days = max(1, $days);
        if (Database::driver() === 'pgsql') {
            $stmt = $this->pdo->prepare("DELETE FROM log_events WHERE level = :level AND created_at < (NOW() - (:days || ' days')::interval)");
            $stmt->execute(['level' => $level, 'days' => (string)$days]);
            return $stmt->rowCount();
        }
        $stmt = $this->pdo->prepare("DELETE FROM log_events WHERE level = :level AND datetime(created_at) < datetime('now', :modifier)");
        $stmt->execute(['level' => $level, 'modifier' => '-' . $days . ' days']);
        return $stmt->rowCount();
    }



    public function users(): array
    {
        return $this->pdo->query('SELECT id, username, role, is_active, last_login_at, created_at, updated_at FROM users ORDER BY username ASC')->fetchAll();
    }

    public function saveUser(?int $id, string $username, ?string $password, string $role, bool $active): int
    {
        $role = in_array($role, ['admin','operator','security','viewer'], true) ? $role : 'viewer';
        $username = $this->cut(trim($username), 120);
        if ($username === '') {
            throw new \InvalidArgumentException('username is empty');
        }
        $now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        if ($id !== null && $id > 0) {
            $sets = ['username = :username', 'role = :role', 'is_active = :active', "updated_at = {$now}"];
            $params = ['id' => $id, 'username' => $username, 'role' => $role, 'active' => $active ? 1 : 0];
            if ($password !== null && $password !== '') {
                $sets[] = 'password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $this->pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            return $id;
        }
        if ($password === null || $password === '') {
            throw new \InvalidArgumentException('password is required for new user');
        }
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $stmt = $this->pdo->prepare("INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (:username, :password_hash, :role, :active, {$now}, {$now}){$returning}");
        $stmt->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'active' => $active ? 1 : 0,
        ]);
        return Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    public function setUserActive(int $id, bool $active): bool
    {
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE users SET is_active = :active, updated_at = NOW() WHERE id = :id'
            : 'UPDATE users SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function deleteUser(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function recordCronRun(string $task, string $status, string $message = '', array $meta = [], ?float $startedAt = null): void
    {
        $startedAt ??= microtime(true);
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $metaJson = json_encode($this->limitJsonPayload($meta, 16384), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (Database::driver() === 'pgsql') {
            $sql = "INSERT INTO cron_runs (task, status, message, meta, started_at, finished_at, duration_ms) VALUES (:task, :status, :message, :meta, to_timestamp(:started_at), NOW(), :duration_ms)";
        } else {
            $sql = "INSERT INTO cron_runs (task, status, message, meta, started_at, finished_at, duration_ms) VALUES (:task, :status, :message, :meta, :started_at_text, CURRENT_TIMESTAMP, :duration_ms)";
        }
        $params = [
            'task' => $this->cut($task, 120),
            'status' => $this->cut($status, 30),
            'message' => $this->cut($message, 4000),
            'meta' => $metaJson,
            'duration_ms' => $durationMs,
        ];
        if (Database::driver() === 'pgsql') {
            $params['started_at'] = $startedAt;
        } else {
            $params['started_at_text'] = gmdate('Y-m-d H:i:s', (int)$startedAt);
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    public function cronRuns(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query('SELECT * FROM cron_runs ORDER BY started_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
    }

    public function errorsOverview(): array
    {
        if (Database::driver() === 'pgsql') {
            $sql = "SELECT COALESCE(fingerprint, '') AS fingerprint, project, bot, environment, level, COUNT(*) AS cnt, MAX(created_at) AS last_at, MIN(created_at) AS first_at, MAX(message) AS sample_message
                    FROM log_events
                    WHERE level IN ('ERROR','CRITICAL','SECURITY') AND created_at >= NOW() - INTERVAL '24 hours'
                    GROUP BY COALESCE(fingerprint, ''), project, bot, environment, level
                    ORDER BY cnt DESC, last_at DESC LIMIT 100";
        } else {
            $sql = "SELECT COALESCE(fingerprint, '') AS fingerprint, project, bot, environment, level, COUNT(*) AS cnt, MAX(created_at) AS last_at, MIN(created_at) AS first_at, MAX(message) AS sample_message
                    FROM log_events
                    WHERE level IN ('ERROR','CRITICAL','SECURITY') AND datetime(created_at) >= datetime('now','-24 hours')
                    GROUP BY COALESCE(fingerprint, ''), project, bot, environment, level
                    ORDER BY cnt DESC, last_at DESC LIMIT 100";
        }
        return $this->pdo->query($sql)->fetchAll();
    }

    public function savedViews(): array
    {
        return $this->pdo->query('SELECT * FROM saved_views ORDER BY created_at DESC, id DESC')->fetchAll();
    }

    public function createSavedView(string $name, string $route, string $query): int
    {
        $now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $stmt = $this->pdo->prepare("INSERT INTO saved_views (name, route, query, is_shared, created_by, created_at, updated_at) VALUES (:name, :route, :query, 1, :created_by, {$now}, {$now}){$returning}");
        $stmt->execute([
            'name' => $this->cut($name, 160),
            'route' => $this->cut($route ?: '/logs', 120),
            'query' => $query,
            'created_by' => Security::currentActor(),
        ]);
        return Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    public function deleteSavedView(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM saved_views WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function setIncidentMute(int $id, ?int $hours, ?string $reason): bool
    {
        if ($hours === null || $hours <= 0) {
            $sql = 'UPDATE incidents SET muted_until_at = NULL, muted_reason = NULL WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount() > 0;
        }
        if (Database::driver() === 'pgsql') {
            $sql = "UPDATE incidents SET muted_until_at = NOW() + (:hours || ' hours')::interval, muted_reason = :reason WHERE id = :id";
        } else {
            $sql = "UPDATE incidents SET muted_until_at = datetime('now', '+' || :hours || ' hours'), muted_reason = :reason WHERE id = :id";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id, 'hours' => (string)$hours, 'reason' => $reason]);
        return $stmt->rowCount() > 0;
    }

    public function siteDetailStats(string $site): array
    {
        $project = Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'));
        $stmt = $this->pdo->prepare('SELECT * FROM log_events WHERE project = :project AND bot = :site ORDER BY created_at DESC LIMIT 500');
        $stmt->execute(['project' => $project, 'site' => $site]);
        $rows = $stmt->fetchAll();
        $top404 = [];
        $top500 = [];
        $topIp = [];
        $topUa = [];
        foreach ($rows as $row) {
            $ctx = json_decode((string)($row['context'] ?? ''), true);
            if (!is_array($ctx)) {
                continue;
            }
            $path = (string)($ctx['path'] ?? '');
            $status = (int)($ctx['status'] ?? 0);
            $ip = (string)($ctx['remote_addr'] ?? '');
            $ua = (string)($ctx['user_agent'] ?? '');
            if ($status === 404 && $path !== '') { $top404[$path] = ($top404[$path] ?? 0) + 1; }
            if ($status >= 500 && $path !== '') { $top500[$path] = ($top500[$path] ?? 0) + 1; }
            if ($ip !== '') { $topIp[$ip] = ($topIp[$ip] ?? 0) + 1; }
            if ($ua !== '') { $topUa[$ua] = ($topUa[$ua] ?? 0) + 1; }
        }
        arsort($top404); arsort($top500); arsort($topIp); arsort($topUa);
        return ['recent' => $rows, 'top404' => array_slice($top404, 0, 20, true), 'top500' => array_slice($top500, 0, 20, true), 'topIp' => array_slice($topIp, 0, 20, true), 'topUa' => array_slice($topUa, 0, 20, true)];
    }

    public function createJob(string $type, array $payload = []): int
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $stmt = $this->pdo->prepare("INSERT INTO jobs (type, status, payload, created_by, created_at) VALUES (:type, 'queued', :payload, :created_by, {$now}){$returning}");
        $stmt->execute(['type' => $this->cut($type, 120), 'payload' => $payloadJson, 'created_by' => Security::currentActor()]);
        return Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    public function jobs(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query('SELECT * FROM jobs ORDER BY created_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
    }


    public function nextQueuedJob(?string $type = null): ?array
    {
        $where = "status = 'queued'";
        $params = [];
        if ($type !== null) {
            $where .= ' AND type = :type';
            $params['type'] = $type;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE ' . $where . ' ORDER BY created_at ASC, id ASC LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markJobStarted(int $id): void
    {
        $sql = Database::driver() === 'pgsql'
            ? "UPDATE jobs SET status = 'running', started_at = NOW() WHERE id = :id"
            : "UPDATE jobs SET status = 'running', started_at = CURRENT_TIMESTAMP WHERE id = :id";
        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }

    public function finishJob(int $id, string $status, array $result = [], ?string $error = null): void
    {
        $status = in_array($status, ['done','failed'], true) ? $status : 'failed';
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = Database::driver() === 'pgsql'
            ? "UPDATE jobs SET status = :status, result = :result, error = :error, finished_at = NOW() WHERE id = :id"
            : "UPDATE jobs SET status = :status, result = :result, error = :error, finished_at = CURRENT_TIMESTAMP WHERE id = :id";
        $this->pdo->prepare($sql)->execute(['id' => $id, 'status' => $status, 'result' => $json, 'error' => $error]);
    }

    public function dbOwnershipReport(): array
    {
        if (Database::driver() !== 'pgsql') {
            return [['kind' => 'info', 'name' => 'SQLite', 'owner' => 'файловая БД', 'expected' => Env::get('DB_USERNAME', '')]];
        }
        $stmt = $this->pdo->query("SELECT 'table' AS kind, tablename AS name, tableowner AS owner FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        $rows = $stmt->fetchAll();
        $expected = (string)Env::get('DB_USERNAME', '');
        foreach ($rows as &$row) {
            $row['expected'] = $expected;
            $row['ok'] = $expected === '' || (string)$row['owner'] === $expected;
        }
        unset($row);
        return $rows;
    }

    public function retentionBySource(): array
    {
        $rules = [
            ['name' => 'nginx access', 'where' => "project = :project AND logger = 'nginx.access'", 'days' => Env::int('RETENTION_AAPANEL_ACCESS_DAYS', 14), 'params' => ['project' => Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'))]],
            ['name' => 'nginx error', 'where' => "project = :project AND logger = 'nginx.error'", 'days' => Env::int('RETENTION_AAPANEL_ERROR_DAYS', 90), 'params' => ['project' => Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'))]],
        ];
        $result = [];
        foreach ($rules as $rule) {
            $days = max(1, (int)$rule['days']);
            if (Database::driver() === 'pgsql') {
                $sql = 'DELETE FROM log_events WHERE ' . $rule['where'] . " AND created_at < (NOW() - (:days || ' days')::interval)";
            } else {
                $sql = 'DELETE FROM log_events WHERE ' . $rule['where'] . " AND datetime(created_at) < datetime('now', :modifier)";
            }
            $params = $rule['params'];
            if (Database::driver() === 'pgsql') {
                $params['days'] = (string)$days;
            } else {
                $params['modifier'] = '-' . $days . ' days';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result[] = ['rule' => $rule['name'], 'deleted' => $stmt->rowCount(), 'keep_days' => $days];
        }
        return $result;
    }

    public function searchLogs(string $query, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $like = '%' . $query . '%';
        if (Database::driver() === 'pgsql') {
            $sql = 'SELECT * FROM log_events WHERE message ILIKE :q OR exception ILIKE :q OR logger ILIKE :q OR trace_id ILIKE :q OR project ILIKE :q OR bot ILIKE :q OR environment ILIKE :q OR context::text ILIKE :q ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        } else {
            $sql = 'SELECT * FROM log_events WHERE message LIKE :q OR exception LIKE :q OR logger LIKE :q OR trace_id LIKE :q OR project LIKE :q OR bot LIKE :q OR environment LIKE :q OR context LIKE :q ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['q' => $like]);
        return $stmt->fetchAll();
    }

    public function resetJob(int $id): bool
    {
        $sql = Database::driver() === 'pgsql'
            ? "UPDATE jobs SET status = 'queued', started_at = NULL, finished_at = NULL, error = NULL, result = NULL WHERE id = :id"
            : "UPDATE jobs SET status = 'queued', started_at = NULL, finished_at = NULL, error = NULL, result = NULL WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function cancelJob(int $id): bool
    {
        $sql = Database::driver() === 'pgsql'
            ? "UPDATE jobs SET status = 'cancelled', finished_at = NOW() WHERE id = :id AND status IN ('queued','running','failed')"
            : "UPDATE jobs SET status = 'cancelled', finished_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN ('queued','running','failed')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }



    public function botTokenRuntimeStats(int $id): array
    {
        $bot = $this->botTokenById($id);
        if (!$bot) {
            return ['bot' => null, 'logs_24h' => 0, 'errors_24h' => 0, 'last_log' => null, 'last_error' => null, 'recent_logs' => []];
        }
        $filters = [
            'project' => (string)$bot['project'],
            'bot' => (string)$bot['bot'],
            'environment' => (string)$bot['environment'],
        ];
        $recent = $this->recentLogs($filters, 20);
        $lastLog = $recent[0] ?? null;
        $lastError = $this->recentLogs(array_merge($filters, ['level' => 'ERROR']), 1)[0] ?? null;
        if (Database::driver() === 'pgsql') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM log_events WHERE project = :project AND bot = :bot AND environment = :environment AND received_at >= NOW() - INTERVAL '24 hours'");
            $err = $this->pdo->prepare("SELECT COUNT(*) FROM log_events WHERE project = :project AND bot = :bot AND environment = :environment AND level IN ('ERROR','CRITICAL','SECURITY') AND received_at >= NOW() - INTERVAL '24 hours'");
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM log_events WHERE project = :project AND bot = :bot AND environment = :environment AND datetime(received_at) >= datetime('now', '-24 hours')");
            $err = $this->pdo->prepare("SELECT COUNT(*) FROM log_events WHERE project = :project AND bot = :bot AND environment = :environment AND level IN ('ERROR','CRITICAL','SECURITY') AND datetime(received_at) >= datetime('now', '-24 hours')");
        }
        $stmt->execute($filters);
        $err->execute($filters);
        return [
            'bot' => $bot,
            'logs_24h' => (int)$stmt->fetchColumn(),
            'errors_24h' => (int)$err->fetchColumn(),
            'last_log' => $lastLog,
            'last_error' => $lastError,
            'recent_logs' => $recent,
        ];
    }

    public function jobStats(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM jobs GROUP BY status')->fetchAll();
        $result = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $result[(string)$row['status']] = (int)$row['cnt'];
        }
        return $result;
    }

    public function cleanupJobs(int $olderThanDays = 7): int
    {
        $olderThanDays = max(1, $olderThanDays);
        if (Database::driver() === 'pgsql') {
            $stmt = $this->pdo->prepare("DELETE FROM jobs WHERE status IN ('done','cancelled') AND COALESCE(finished_at, created_at) < NOW() - (:days || ' days')::interval");
            $stmt->execute(['days' => (string)$olderThanDays]);
            return $stmt->rowCount();
        }
        $stmt = $this->pdo->prepare("DELETE FROM jobs WHERE status IN ('done','cancelled') AND datetime(COALESCE(finished_at, created_at)) < datetime('now', :modifier)");
        $stmt->execute(['modifier' => '-' . $olderThanDays . ' days']);
        return $stmt->rowCount();
    }

    public function retryFailedJobs(): int
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'queued', started_at = NULL, finished_at = NULL, error = NULL WHERE status = 'failed'");
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function archiveLogsOlderThan(int $days, string $source = 'all', int $limit = 50000): array
    {
        $days = max(1, $days);
        $limit = max(100, min(200000, $limit));
        $archiveDir = dirname(__DIR__) . '/storage/archives';
        if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0775, true) && !is_dir($archiveDir)) {
            throw new \RuntimeException('Не удалось создать каталог архивов: ' . $archiveDir);
        }
        $where = [];
        $params = [];
        if (Database::driver() === 'pgsql') {
            $where[] = "created_at < (NOW() - (:days || ' days')::interval)";
            $params['days'] = (string)$days;
        } else {
            $where[] = "datetime(created_at) < datetime('now', :modifier)";
            $params['modifier'] = '-' . $days . ' days';
        }
        if ($source === 'aapanel_access') {
            $where[] = "project = :project AND logger = 'nginx.access'";
            $params['project'] = Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'));
        } elseif ($source === 'aapanel_error') {
            $where[] = "project = :project AND logger = 'nginx.error'";
            $params['project'] = Env::get('NGINX_LOG_PROJECT', Env::get('AAPANEL_LOG_PROJECT', 'Web Sites'));
        } elseif ($source === 'bot_errors') {
            $where[] = "level IN ('ERROR','CRITICAL','SECURITY')";
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare('SELECT * FROM log_events' . $whereSql . ' ORDER BY created_at ASC, id ASC LIMIT ' . $limit);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return ['archived' => 0, 'deleted' => 0, 'file' => null];
        }
        $file = $archiveDir . '/logs-' . $source . '-' . gmdate('Ymd-His') . '.ndjson.gz';
        if (!function_exists('gzopen')) {
            $file = substr($file, 0, -3);
            $fh = fopen($file, 'wb');
            foreach ($rows as $row) {
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }
            fclose($fh);
        } else {
            $fh = gzopen($file, 'wb9');
            foreach ($rows as $row) {
                gzwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }
            gzclose($fh);
        }
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $deleted = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $placeholders = [];
            $deleteParams = [];
            foreach ($chunk as $i => $id) {
                $key = 'id' . $i;
                $placeholders[] = ':' . $key;
                $deleteParams[$key] = $id;
            }
            $del = $this->pdo->prepare('DELETE FROM log_events WHERE id IN (' . implode(',', $placeholders) . ')');
            $del->execute($deleteParams);
            $deleted += $del->rowCount();
        }
        return ['archived' => count($rows), 'deleted' => $deleted, 'file' => $file];
    }

    public function siteLogPermissionReport(): array
    {
        $dir = Env::get('NGINX_LOG_DIR', Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs'));
        $result = ['dir' => $dir, 'exists' => is_dir($dir), 'readable' => is_readable($dir), 'files' => []];
        if (!$result['exists'] || !$result['readable']) {
            return $result;
        }
        foreach (glob(rtrim($dir, '/') . '/*.log') ?: [] as $file) {
            $result['files'][] = ['file' => $file, 'readable' => is_readable($file), 'size' => is_file($file) ? filesize($file) : null];
            if (count($result['files']) >= 20) {
                break;
            }
        }
        return $result;
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtoupper(trim($level));
        if ($level === 'WARN') {
            return 'WARNING';
        }
        return in_array($level, self::LEVELS, true) ? $level : 'INFO';
    }

    private function normalizeAllowedLevels(mixed $levels): ?string
    {
        if ($levels === null) {
            return null;
        }
        $parts = is_array($levels) ? $levels : (preg_split('/[\s,;]+/', (string)$levels) ?: []);
        $result = [];
        foreach ($parts as $part) {
            $level = $this->normalizeLevel((string)$part);
            if (!in_array($level, $result, true)) {
                $result[] = $level;
            }
        }
        return $result ? implode(',', $result) : null;
    }

    private function privacyHashes(array $event, array $context): array
    {
        $result = ['user_id_hash' => null, 'chat_id_hash' => null, 'guild_id_hash' => null];
        foreach (['user_id', 'chat_id', 'guild_id'] as $key) {
            $value = $event[$key] ?? $context[$key] ?? null;
            if ($value !== null && $value !== '') {
                $result[$key . '_hash'] = Security::privacyHash($key, is_scalar($value) ? (string)$value : json_encode($value));
            }
        }
        return $result;
    }

    private function fingerprint(string $project, string $bot, string $environment, string $level, string $message, ?string $exception, ?string $logger): string
    {
        $base = $exception ?: $message;
        $base = preg_replace('/\b\d+\b/', '#', $base) ?? $base;
        $base = preg_replace('/0x[0-9a-f]+/i', '0x#', $base) ?? $base;
        $base = preg_replace('/\s+/', ' ', trim($base)) ?? $base;
        $base = $this->cut($base, 500);
        return hash('sha256', implode('|', [$project, $bot, $environment, $level, (string)$logger, $base]));
    }

    private function incidentTitle(string $message, ?string $exception): string
    {
        $text = trim((string)($exception ?: $message));
        $first = strtok($text, "\n") ?: $message;
        return $first !== '' ? $first : 'Ошибка без сообщения';
    }

    private function limitJsonPayload(mixed $value, int $maxBytes): mixed
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) <= $maxBytes) {
            return $value;
        }
        return ['_truncated' => true, '_max_bytes' => $maxBytes, '_preview' => $this->cut($json, min($maxBytes, 4096))];
    }

    private function cut(string $value, int $limit): string
    {
        if ($limit < 1) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            $t = strtotime($value);
            if ($t !== false) {
                return gmdate('Y-m-d H:i:s', $t);
            }
        }
        return gmdate('Y-m-d H:i:s');
    }

    public function createUpdateRun(array $data): int
    {
        $now = Database::driver() === 'pgsql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        $returning = Database::driver() === 'pgsql' ? ' RETURNING id' : '';
        $sql = "INSERT INTO update_runs (actor, action, status, repo_url, branch, from_version, to_version, from_commit, to_commit, backup_path, output_log, error_message, started_at) VALUES (:actor, :action, :status, :repo_url, :branch, :from_version, :to_version, :from_commit, :to_commit, :backup_path, :output_log, :error_message, {$now}){$returning}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'actor' => Security::currentActor(),
            'action' => $this->cut((string)($data['action'] ?? 'update'), 40),
            'status' => $this->cut((string)($data['status'] ?? 'running'), 30),
            'repo_url' => (string)($data['repo_url'] ?? ''),
            'branch' => $this->cut((string)($data['branch'] ?? ''), 120),
            'from_version' => $this->cut((string)($data['from_version'] ?? ''), 80),
            'to_version' => $this->cut((string)($data['to_version'] ?? ''), 80),
            'from_commit' => $this->cut((string)($data['from_commit'] ?? ''), 80),
            'to_commit' => $this->cut((string)($data['to_commit'] ?? ''), 80),
            'backup_path' => (string)($data['backup_path'] ?? ''),
            'output_log' => (string)($data['output_log'] ?? ''),
            'error_message' => $data['error_message'] ?? null,
        ]);
        return Database::driver() === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    public function finishUpdateRun(int $id, string $status, string $outputLog, ?string $error, ?string $toVersion, ?string $toCommit, int $durationMs): void
    {
        $status = in_array($status, ['success', 'failed', 'rolled_back'], true) ? $status : 'failed';
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE update_runs SET status = :status, output_log = :output_log, error_message = :error_message, to_version = COALESCE(:to_version, to_version), to_commit = COALESCE(:to_commit, to_commit), finished_at = NOW(), duration_ms = :duration_ms WHERE id = :id'
            : 'UPDATE update_runs SET status = :status, output_log = :output_log, error_message = :error_message, to_version = COALESCE(:to_version, to_version), to_commit = COALESCE(:to_commit, to_commit), finished_at = CURRENT_TIMESTAMP, duration_ms = :duration_ms WHERE id = :id';
        $this->pdo->prepare($sql)->execute([
            'id' => $id,
            'status' => $status,
            'output_log' => $this->cut($outputLog, 200000),
            'error_message' => $error !== null ? $this->cut($error, 8000) : null,
            'to_version' => $toVersion !== null ? $this->cut($toVersion, 80) : null,
            'to_commit' => $toCommit !== null ? $this->cut($toCommit, 80) : null,
            'duration_ms' => $durationMs,
        ]);
    }

    public function updateRuns(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->pdo->query('SELECT * FROM update_runs ORDER BY started_at DESC, id DESC LIMIT ' . $limit)->fetchAll();
    }

    public function latestRollbackableUpdateRun(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM update_runs WHERE from_commit IS NOT NULL AND from_commit <> '' ORDER BY started_at DESC, id DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    }

}
