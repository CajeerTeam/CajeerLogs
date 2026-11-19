<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

final class DeliveryException extends \RuntimeException
{
    public function __construct(public readonly ?int $status, public readonly string $idempotencyKey,
        public readonly ?string $apiError = null, public readonly ?string $requestId = null)
    {
        parent::__construct('CajeerLogs delivery failed (HTTP '.($status ?? 'unknown').'); retry with the same body and key.');
    }
}
