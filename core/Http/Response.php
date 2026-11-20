<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @param array<string,string> $headers */
    public function __construct(public readonly string $body = '', public readonly int $status = 200, array $headers = [])
    {
        if ($status < 200 || $status > 599) {
            throw new \InvalidArgumentException('Invalid response status.');
        }
        foreach ($headers as $name => $value) {
            $this->validateHeader($name, $value);
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function withHeader(string $name, string $value): self
    {
        $this->validateHeader($name, $value);
        $copy = clone $this;
        $copy->headers[strtolower($name)] = $value;
        return $copy;
    }

    private function validateHeader(string $name, string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Invalid response header.');
        }
    }

    public function header(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }

    public function send(bool $head = false): void
    {
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        // Location headers can implicitly change the status to 302.
        http_response_code($this->status);
        if (!$head && !in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
