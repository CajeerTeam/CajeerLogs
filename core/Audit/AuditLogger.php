<?php
declare(strict_types=1);
namespace Cajeer\Logs\Audit;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo) {}
    public function record(string $action, array $context = [], ?string $actorId = null, ?string $target = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO audit_log(actor_id,action,target,context) VALUES (:actor,:action,:target,CAST(:context AS jsonb))');
        $stmt->execute(['actor'=>$actorId,'action'=>$action,'target'=>$target,'context'=>json_encode((object)$context,JSON_THROW_ON_ERROR)]);
    }
}
