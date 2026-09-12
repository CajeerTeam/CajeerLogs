<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class JsonResponse
{
    /** @param array<string,mixed> $payload */
public function __construct(private array $payload, private int $status = 200) {}

public function send(): void
{
    http_response_code($this->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
}
