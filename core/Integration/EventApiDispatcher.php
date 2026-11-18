<?php
declare(strict_types=1);
namespace Cajeer\Logs\Integration;

use PDO;

/** Transactional producer: notifications are committed or rolled back with their source event. */
final class EventApiDispatcher
{
    public function __construct(private readonly PDO $pdo) {}
    /** @param list<string> $eventIds */
    public function publishCommittedLogs(string $project, array $eventIds): void
    {
        if ($eventIds===[]) throw new \LogicException('Empty committed log event set.');
        $this->publish($project,'logs.ingested',['event_ids'=>$eventIds]);
    }
    /** @param array<string,mixed> $metadata No log content or credentials are allowed. */
    public function publish(string $project,string $event,array $metadata=[]): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Outbox requires an existing database transaction.');
        if (!in_array($event,WebhookOutbox::EVENTS,true)) throw new \InvalidArgumentException('Unknown webhook event.');
        $allowed=$event==='logs.ingested'?['event_ids']:['source_id','status'];
        if (array_diff(array_keys($metadata),$allowed)!==[]) throw new \InvalidArgumentException('Unexpected webhook payload fields.');
        $payload=json_encode(['event'=>$event,'project_id'=>$project]+$metadata,JSON_THROW_ON_ERROR);
        $stmt=$this->pdo->prepare('INSERT INTO webhook_deliveries(webhook_id,payload) SELECT id,CAST(:payload AS jsonb) FROM integration_webhooks WHERE project_id=:project AND enabled AND :event=ANY(events)');
        $stmt->execute(['payload'=>$payload,'project'=>$project,'event'=>$event]);
    }
}
