<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class JsonResponse extends Response
{
    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    public function __construct(array $payload, int $status = 200, array $headers = [])
    {
        parent::__construct(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }
}
