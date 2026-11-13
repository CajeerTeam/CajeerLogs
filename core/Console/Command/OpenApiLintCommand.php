<?php
declare(strict_types=1);

namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Http\Request;
use Cajeer\Logs\Kernel\Application;

final class OpenApiLintCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(): int
    {
        try {
            $path = $this->app->config()->get('api.openapi');
            $file = realpath($this->app->basePath($path));
            if ($file === false || !str_starts_with($file, $this->app->basePath() . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('OpenAPI path must be inside the application.');
            }
            $raw = file_get_contents($file);
            if ($raw === false) { throw new \RuntimeException('Cannot read OpenAPI document.'); }
            // JSON is a YAML 1.2 subset; the shipped document can be parsed without vendor packages.
            try {
                $spec = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) { throw new \RuntimeException('Non-JSON YAML requires symfony/yaml.', 0, $e); }
                $spec = \Symfony\Component\Yaml\Yaml::parse($raw);
            }
            if (!is_array($spec) || ($spec['openapi'] ?? null) !== '3.1.1' || !is_string($spec['info']['title'] ?? null) || ($spec['info']['version'] ?? null) !== $this->app->version()) {
                throw new \RuntimeException('Invalid OpenAPI metadata or version mismatch.');
            }
            if (($spec['servers'][0]['url'] ?? null) !== $this->app->config()->get('api.prefix')) {
                throw new \RuntimeException('OpenAPI server prefix differs from configured API prefix.');
            }
            if (!is_array($spec['paths'] ?? null) || $spec['paths'] === [] || !isset($spec['paths']['/health']['get'])) {
                throw new \RuntimeException('OpenAPI health operation is missing.');
            }
            $expected = \Cajeer\Logs\Api\Controller\IdentityController::ROUTES + ['GET /health'=>'getHealth'];
            $ids = [];
            foreach ($spec['paths'] as $route => $methods) {
                if (!is_string($route) || !str_starts_with($route, '/') || !is_array($methods)) { throw new \RuntimeException('Invalid OpenAPI path.'); }
                foreach ($methods as $method => $operation) {
                    if (!in_array($method, ['get','post','put','patch','delete','head','options'], true) || !is_array($operation) || !is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                        throw new \RuntimeException('Invalid operation or responses.');
                    }
                    $id = $operation['operationId'] ?? null;
                    if (!is_string($id) || $id === '' || isset($ids[$id])) { throw new \RuntimeException('Missing or duplicate operationId.'); }
                    $ids[$id] = true;
                    $key = strtoupper($method) . ' ' . $route;
                    if (($expected[$key] ?? null) !== $id) { throw new \RuntimeException('Operation is not registered in runtime.'); }
                    unset($expected[$key]);
                    foreach ($operation['responses'] as $status => $response) {
                        if (!preg_match('/^[1-5][0-9]{2}$/D', (string) $status) || (!is_string($response['description'] ?? null) && !is_string($response['$ref'] ?? null))) { throw new \RuntimeException('Invalid response definition.'); }
                    }
                }
            }
            if ($expected !== []) { throw new \RuntimeException('Runtime operations missing from OpenAPI.'); }
            $this->references($spec, $spec);
            if (file_get_contents($this->app->basePath('openapi.yaml')) !== file_get_contents($this->app->basePath('api/openapi.yaml'))) {
                throw new \RuntimeException('Root and API OpenAPI documents differ.');
            }
            $response = $this->app->handle(new Request('GET', $this->app->config()->get('api.prefix') . '/health'));
            if (!isset($spec['paths']['/health']['get']['responses'][(string) $response->status])) {
                throw new \RuntimeException('Actual health response is not documented.');
            }
            $data = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
            if (($data['version'] ?? null) !== $this->app->version() || ($data['scope'] ?? null) !== 'runtime') { throw new \RuntimeException('Health response does not match runtime contract.'); }
            echo "OpenAPI runtime lint: OK (metadata, references, operations, version and live dispatch)\n";
            return 0;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'OpenAPI runtime lint failed: ' . $error->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function references(array $node, array $root): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                if (!is_string($value) || !str_starts_with($value, '#/')) { throw new \RuntimeException('Only local OpenAPI references are supported.'); }
                $target = $root;
                foreach (explode('/', substr($value, 2)) as $segment) {
                    $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                    if (!is_array($target) || !array_key_exists($segment, $target)) { throw new \RuntimeException('Unresolved OpenAPI reference.'); }
                    $target = $target[$segment];
                }
            } elseif (is_array($value)) {
                $this->references($value, $root);
            }
        }
    }
}
