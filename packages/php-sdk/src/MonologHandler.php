<?php
declare(strict_types=1);
namespace Cajeer\Logs\Sdk;

/** Monolog 3 adapter. Delivery failures are not silently swallowed. */
final class MonologHandler extends \Monolog\Handler\AbstractProcessingHandler
{
    public function __construct(private readonly CajeerLogsClient $client, private readonly string $source,
        private readonly ?string $service = null, \Monolog\Level|int|string $level = \Monolog\Level::Debug, bool $bubble = true)
    { parent::__construct($level,$bubble); }
    protected function write(\Monolog\LogRecord $record): void
    {
        $event=['timestamp'=>$record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),'source'=>$this->source,'level'=>strtolower($record->level->getName()),'message'=>$record->message,
            'context'=>Logger::normalizeContext(['channel'=>$record->channel,'context'=>$record->context,'extra'=>$record->extra])];
        if ($this->service!==null) { $event['service']=$this->service; }
        $this->client->ingest($event);
    }
}
