<?php
declare(strict_types=1);
namespace Cajeer\Logs\Integration;

use PDO;

/** Atomic outbox producer. Payload includes identifiers, never original log content. */
final class EventApiDispatcher
{
    public function __construct(private readonly PDO $pdo) {}
    /** @param list<string> $eventIds */
    public function publishCommittedLogs(string $project, array $eventIds): void
    {
        if (!$this->pdo->inTransaction() || $eventIds===[]) { throw new \LogicException('Outbox requires ingest transaction.'); }
        $payload=json_encode(['event'=>'logs.ingested','project_id'=>$project,'event_ids'=>$eventIds],JSON_THROW_ON_ERROR);
        $stmt=$this->pdo->prepare('INSERT INTO webhook_deliveries(webhook_id,payload) SELECT id,CAST(:payload AS jsonb) FROM integration_webhooks WHERE project_id=:project AND enabled');
        $stmt->execute(['payload'=>$payload,'project'=>$project]);
    }
}
