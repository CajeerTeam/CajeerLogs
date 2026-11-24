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
    public function __construct(string $method, string $path, array $headers = [], public readonly array $query = [], private readonly string $body = '', public readonly string $remoteAddress = 'unknown', public readonly bool $secureTransport = false)
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
            $lower=strtolower($name);
            if (isset($this->headers[$lower]) || !preg_match("/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/D",$name) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$value)) throw new HttpException(400,'invalid_header','Invalid or duplicate HTTP header.');
            $this->headers[$lower] = $value;
        }
        // Generate a trusted correlation ID rather than logging arbitrary client input.
        $this->requestId = bin2hex(random_bytes(16));
    }

    public static function fromGlobals(int $maxBodyBytes, ?string $ingestPrefix = null, ?\Cajeer\Logs\Security\DeploymentSecurity $deployment = null, ?array $resolvedTransport = null): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = explode('?', $uri, 2)[0];
        $ingest = $ingestPrefix !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($path, [$ingestPrefix.'/ingest',$ingestPrefix.'/ingest/async'],true);
        if ($ingest) $maxBodyBytes=min($maxBodyBytes,1048576);
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }
        $peer=(string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $secure=in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on','1'], true);
        if ($deployment!==null) {
            $transport=$resolvedTransport??$deployment->transport($peer,$secure,$headers);
            $peer=$transport['address']; $secure=$transport['secure'];
            $deployment->assertRequest((string)($_SERVER['REQUEST_METHOD']??'GET'),$path,$headers['HOST']??null,$secure);
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $name) {
            if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
                $header = str_replace('_', '-', $name);
                $value = (string) $_SERVER[$name];
                if (isset($headers[$header]) && $headers[$header] !== $value) throw new HttpException(400,'invalid_header','Conflicting HTTP header.');
                $headers[$header] = $value;
            }
        }
        // Ambiguous framing must be rejected for every route, not only ingestion.
        // A proxy and the application must not disagree about request boundaries.
        if (isset($headers['CONTENT-LENGTH'],$headers['TRANSFER-ENCODING'])) throw new HttpException(400,'ambiguous_body','Conflicting body framing.');
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
        if ($ingest && isset($headers['CONTENT-LENGTH']) && (int)$headers['CONTENT-LENGTH'] !== strlen($body)) throw new HttpException(400,'invalid_body','Content-Length does not match body.');
        $query=$_GET;
        if ($ingestPrefix!==null && in_array($_SERVER['REQUEST_METHOD']??'', ['GET','HEAD'],true) && preg_match('~^'.preg_quote($ingestPrefix,'~').'/logs(?:/[^/]+)?$~D',$path)) {
            $query=self::searchQuery(explode('?', $uri, 2)[1]??'');
        }
        if ($ingestPrefix!==null && $path===$ingestPrefix.'/auth/skif4er/callback') {
            $query=self::searchQuery(explode('?', $uri, 2)[1]??'');
        }
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $headers, $query, $body, $peer, $secure);
    }

    /** Parse raw search parameters before PHP normalizes names or overwrites duplicates. */
    public static function searchQuery(string $raw): array
    {
        if ($raw==='') return [];
        if (strlen($raw)>65536) throw new HttpException(422,'invalid_query','Query string exceeds limit.');
        $result=[];
        foreach (explode('&',$raw) as $pair) {
            if (count($result)>=40 || preg_match('/%(?![0-9a-f]{2})/i',$pair)) throw new HttpException(422,'invalid_query','Malformed query string.');
            $parts=explode('=',$pair,2);
            $key=urldecode($parts[0]);
            if (count($parts)!==2 || !preg_match('/^[a-z][a-z0-9_]*$/D',$key) || array_key_exists($key,$result)) throw new HttpException(422,'invalid_query','Invalid or duplicate query parameter.');
            $result[$key]=urldecode($parts[1]);
        }
        return $result;
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
