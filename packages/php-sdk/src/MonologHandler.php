<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** Monolog 3 adapter. Buffered delivery is opt-in and never silently discards events. */
final class MonologHandler extends \Monolog\Handler\AbstractProcessingHandler
{
    private bool $insideWrite = false;

    public function __construct(
        private readonly CajeerLogsClient $client,
        private readonly string $source,
        private readonly ?string $service = null,
        \Monolog\Level|int|string $level = \Monolog\Level::Debug,
        bool $bubble = true,
        private readonly ?DurableBatchDelivery $delivery = null,
    ) {
        parent::__construct($level,$bubble);
    }

    protected function write(\Monolog\LogRecord $record): void
    {
        // Reject re-entrancy instead of triggering recursive transport logging.
        if ($this->insideWrite) throw new \LogicException('Recursive Monolog SDK delivery is not supported.');
        $this->insideWrite = true;
        try {
            $event=['timestamp'=>$record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'source'=>$this->source,'level'=>strtolower($record->level->getName()),'message'=>$record->message,
                'context'=>Logger::normalizeContext(['channel'=>$record->channel,'context'=>$record->context,'extra'=>$record->extra])];
            if ($this->service!==null) $event['service']=$this->service;
            if ($this->delivery!==null) $this->delivery->enqueue($event);
            else $this->client->ingest($event);
        } finally {
            $this->insideWrite = false;
        }
    }

    public function flush(): int { return $this->delivery?->flush() ?? 0; }

    public function close(): void
    {
        try { $this->flush(); } finally { parent::close(); }
    }
}
