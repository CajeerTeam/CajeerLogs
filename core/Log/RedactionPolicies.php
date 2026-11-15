<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Database\DatabaseException;
use PDO;
use stdClass;

/** Settings-backed policy repository. A schema-local lock fences policy publication against durable writes. */
final class RedactionPolicies
{
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function lock(bool $exclusive=false): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Redaction requires a transaction.');
        $fn=$exclusive?'pg_advisory_xact_lock':'pg_advisory_xact_lock_shared';
        $stmt=$this->sql("SELECT current_setting('transaction_isolation') AS isolation, $fn(hashtextextended('cajeerlogs:redaction:' || current_schema(),0))");
        $isolation=$stmt->fetchColumn(); $stmt->closeCursor();
        // A pre-existing repeatable snapshot could otherwise miss a policy published while waiting for the fence.
        if ($isolation!=='read committed') throw new DatabaseException('Redaction writes require READ COMMITTED isolation.');
    }
    private static function key(?string $project,?string $source=null): string
    {
        if ($source!==null && $project===null) throw new \LogicException('Source needs project.');
        return 'redaction.'.($project===null?'system':LogQuery::uuid($project).($source===null?'':'.'.LogQuery::uuid($source)));
    }
    private static function decode(string $value): array
    {
        try {
            $p=json_decode($value,false,32,JSON_THROW_ON_ERROR);
            if (!$p instanceof stdClass || !is_int($p->revision??null) || $p->revision<1 || !is_array($p->rules??null)) throw new \UnexpectedValueException();
            return ['revision'=>$p->revision,'rules'=>RedactionPolicy::validate($p->rules)];
        } catch (\Throwable) { throw new DatabaseException('Stored redaction policy is invalid.'); }
    }
    public function get(?string $project,?string $source=null): array
    {
        $this->assertScope($project,$source);
        $value=$this->sql('SELECT value FROM settings WHERE key=:key',['key'=>self::key($project,$source)])->fetchColumn();
        return $value===false?['revision'=>0,'rules'=>[]]:self::decode($value);
    }
    private function assertScope(?string $project,?string $source): void
    {
        if ($project===null) return;
        $s=$source===null?$this->sql('SELECT 1 FROM projects WHERE id=:project',['project'=>$project]):$this->sql('SELECT 1 FROM sources WHERE project_id=:project AND id=:source',['project'=>$project,'source'=>$source]);
        if ($s->fetchColumn()===false) throw new HttpException(404,'not_found','Resource not found.');
    }
    public function set(?string $project,?string $source,int $revision,array $rules): array
    {
        RedactionPolicy::validate($rules); $this->lock(true);
        $old=$this->get($project,$source);
        if ($revision!==$old['revision'] || $revision===PHP_INT_MAX) throw new HttpException(409,'redaction_revision_conflict','Redaction policy changed. Reload it.');
        $result=['revision'=>$revision+1,'rules'=>$rules];
        $this->sql('INSERT INTO settings(key,value) VALUES (:key,CAST(:value AS jsonb)) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_at=clock_timestamp()',
            ['key'=>self::key($project,$source),'value'=>json_encode($result,JSON_THROW_ON_ERROR)]);
        return $result;
    }
    /** Load all relevant policies in one statement under a shared transaction fence. */
    public function apply(array $events,string $project,array $sources,int $version=IngestValidator::VERSION,?array $draft=null): array
    {
        $sources=IngestBatch::sourceMap($events,$sources);
        $this->lock();
        $keys=[self::key(null),self::key($project)];
        foreach ($sources as $id) $keys[]=self::key($project,$id);
        $rows=$this->sql('SELECT key,value FROM settings WHERE key IN (SELECT jsonb_array_elements_text(CAST(:keys AS jsonb)))', ['keys'=>json_encode(array_values(array_unique($keys)),JSON_THROW_ON_ERROR)])->fetchAll(PDO::FETCH_ASSOC);
        $rules=[]; foreach ($rows as $row) $rules[$row['key']]=self::decode($row['value'])['rules'];
        if ($draft!==null) $rules[self::key($draft['project'],$draft['source'])]=RedactionPolicy::validate($draft['rules']);
        $common=array_merge($rules[$keys[0]]??[],$rules[$keys[1]]??[]); $compiled=[];$out=[]; $deadline=hrtime(true)+1_000_000_000;
        foreach ($events as $event) {
            if (hrtime(true)>$deadline) throw new HttpException(503,'redaction_failed','Redaction could not be completed safely.',['Retry-After'=>'5']);
            $id=$sources[$event->source];
            $compiled[$id]??=new RedactionPolicy(array_merge($common,$rules[self::key($project,$id)]??[]));
            $out[]=$compiled[$id]->apply($event,$version);
        }
        return $out;
    }
}
