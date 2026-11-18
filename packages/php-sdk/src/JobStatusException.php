<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

final class JobStatusException extends \RuntimeException
{
    public function __construct(public readonly ?int $httpStatus) { parent::__construct('Job status unavailable (HTTP '.($httpStatus??'unknown').')'); }
}
