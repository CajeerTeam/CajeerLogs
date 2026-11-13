<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class Request
{
    /** @var array<string,string> */
    private array $headers = [];
    /** @var array<string,string> */
    private array $parameters = [];
    public readonly string $method;
    public readonly string $path;
    public readonly string $requestId;

    /** @param array<string,string> $headers @param array<string,mixed> $query */
    public function __construct(string $method, string $path, array $headers = [], public readonly array $query = [], private readonly string $body = '')
    {
        $this->method = strtoupper($method);
        if (!preg_match('/^[A-Z!#$%&\'*+.^_`|~0-9-]+$/D', $this->method)) {
            throw new HttpException(400, 'invalid_method', 'Invalid HTTP method.');
        }
        if (!str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f?#\\\\]/', $path) || preg_match('/%(?![0-9a-f]{2})/i', $path)) {
            throw new HttpException(400, 'invalid_path', 'Invalid request path.');
        }
        $this->path = $path;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
        // Generate a trusted correlation ID rather than logging arbitrary client input.
        $this->requestId = bin2hex(random_bytes(16));
    }

    public static function fromGlobals(int $maxBodyBytes): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $name) {
            if (isset($_SERVER[$name])) {
                $headers[str_replace('_', '-', $name)] = (string) $_SERVER[$name];
            }
        }
        if (isset($headers['CONTENT-LENGTH'])) {
            $length = $headers['CONTENT-LENGTH'];
            if (!ctype_digit($length)) {
                throw new HttpException(400, 'invalid_content_length', 'Invalid Content-Length.');
            }
            if ((float) $length > $maxBodyBytes) {
                throw new HttpException(413, 'payload_too_large', 'Request body exceeds the configured limit.');
            }
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open request body.');
        }
        try {
            $body = stream_get_contents($stream, $maxBodyBytes + 1);
        } finally {
            fclose($stream);
        }
        if ($body === false) {
            throw new HttpException(400, 'invalid_body', 'Cannot read request body.');
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new HttpException(413, 'payload_too_large', 'Request body exceeds the configured limit.');
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = explode('?', $uri, 2)[0];
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $headers, $_GET, $body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function body(): string { return $this->body; }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $type = strtolower(trim(explode(';', $this->header('Content-Type', '') ?? '', 2)[0]));
        if ($type !== 'application/json' && !str_ends_with($type, '+json')) {
            throw new HttpException(415, 'unsupported_media_type', 'Expected JSON request body.');
        }
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'Malformed JSON request body.');
        }
        if (!is_array($data)) {
            throw new HttpException(422, 'invalid_payload', 'Expected a JSON object or array.');
        }
        return $data;
    }

    /** @param array<string,string> $parameters */
    public function withParameters(array $parameters): self
    {
        $copy = clone $this;
        $copy->parameters = $parameters;
        return $copy;
    }

    public function parameter(string $name): ?string { return $this->parameters[$name] ?? null; }
}
