<?php
declare(strict_types=1);

namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Http\JsonResponse;
use Cajeer\Logs\Observability\HealthCheck;

final class HealthController
{
    public function __construct(private readonly HealthCheck $health) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->health->run();
        return new JsonResponse($result, $result['status'] === 'ok' ? 200 : 503,['Cache-Control'=>'no-store']);
    }
}
