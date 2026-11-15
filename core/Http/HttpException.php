<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class HttpException extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, public readonly string $error, string $message, public readonly array $headers = [], public readonly array $details = [])
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP error status.');
        }
        parent::__construct($message);
    }
}
