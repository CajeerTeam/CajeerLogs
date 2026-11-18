<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** An accepted async job has not yet committed; resume polling this same job ID. */
final class JobPendingException extends \RuntimeException
{
    public function __construct(public readonly string $jobId) { parent::__construct('Async job remains unconfirmed: '.$jobId); }
}
