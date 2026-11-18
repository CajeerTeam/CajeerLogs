<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** Operator action required; never automatically enqueue a replacement. */
final class JobFailedException extends \RuntimeException
{
    public function __construct(public readonly string $jobId, public readonly string $jobStatus) { parent::__construct('Async job '.$jobId.' reached '.$jobStatus); }
}
