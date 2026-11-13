<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Http\JsonResponse;
use Cajeer\Logs\Observability\SystemReport;

final class SystemController
{
    public function __construct(private readonly SystemReport $report) {}
    /** The router must authenticate system:read before invoking this controller. */
    public function report(): JsonResponse
    {
        $data=$this->report->build();
        return new JsonResponse($data,$data['status']==='ok'?200:503);
    }
}
