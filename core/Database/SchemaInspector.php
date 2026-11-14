<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

/** Catalog-based structural fingerprint; excludes data, ownership and statistics. */
final class SchemaInspector
{
    public function __construct(private readonly PDO $pdo) {}

    /** Called inside a transaction so the search_path is local and deparsed names are stable. */
    public function snapshot(string $schema): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Schema inspection requires a transaction.');
        $path=$this->pdo->query("SELECT pg_catalog.current_setting('search_path')")->fetchColumn();
        $set=$this->pdo->prepare("SELECT pg_catalog.set_config('search_path', :path, true)");
        $set->execute(['path'=>'pg_catalog, "'.$schema.'", pg_temp']);
        $set->closeCursor();
        try {
            $query=$this->pdo->prepare(<<<'SQL'
WITH target AS (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname=:schema),
relations AS (SELECT c.* FROM pg_catalog.pg_class c JOIN target t ON t.oid=c.relnamespace),
objects AS (
 SELECT 'relation:'||c.relname AS key, jsonb_build_object('kind',c.relkind,'persistence',c.relpersistence,'rls',c.relrowsecurity,'force_rls',c.relforcerowsecurity,'replica_identity',c.relreplident,'partition',c.relispartition,'partition_bound',pg_get_expr(c.relpartbound,c.oid,false),'partition_key',CASE WHEN c.relkind='p' THEN pg_get_partkeydef(c.oid) END,'view',CASE WHEN c.relkind IN ('v','m') THEN pg_get_viewdef(c.oid,false) END) AS value
 FROM relations c WHERE c.relkind NOT IN ('i','I','S','t')
 UNION ALL
 SELECT 'column:'||c.relname||'.'||a.attname, jsonb_build_object('type',format_type(a.atttypid,a.atttypmod),'position',row_number() OVER (PARTITION BY c.oid ORDER BY a.attnum),'not_null',a.attnotnull,'identity',a.attidentity,'generated',a.attgenerated,'default',pg_get_expr(d.adbin,d.adrelid,false),'collation',CASE WHEN a.attcollation<>0 THEN a.attcollation::regcollation::text END)
 FROM relations c JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum
 WHERE c.relkind IN ('r','p','v','m','f') AND a.attnum>0 AND NOT a.attisdropped
 UNION ALL
 SELECT 'constraint:'||r.relname||'.'||c.conname, jsonb_build_object('type',c.contype,'definition',pg_get_constraintdef(c.oid,false),'validated',c.convalidated,'enforced',COALESCE(to_jsonb(c)->>'conenforced','true'),'deferrable',c.condeferrable,'deferred',c.condeferred)
 FROM pg_catalog.pg_constraint c JOIN relations r ON r.oid=c.conrelid WHERE c.contype<>'n'
 UNION ALL
 SELECT 'index:'||c.relname, jsonb_build_object('table',r.relname,'method',am.amname,'unique',i.indisunique,'primary',i.indisprimary,'valid',i.indisvalid,'ready',i.indisready,'live',i.indislive,'nulls_not_distinct',i.indnullsnotdistinct,'keys',i.indnkeyatts,'columns',(SELECT jsonb_agg(pg_get_indexdef(i.indexrelid,k,false) ORDER BY k) FROM generate_series(1,i.indnatts) k),'options',i.indoption::text,'opclasses',(SELECT jsonb_agg(o.opcname ORDER BY x.ord) FROM unnest(i.indclass) WITH ORDINALITY x(oid,ord) JOIN pg_catalog.pg_opclass o ON o.oid=x.oid),'collations',(SELECT jsonb_agg(CASE WHEN x.oid=0 THEN NULL ELSE x.oid::regcollation::text END ORDER BY x.ord) FROM unnest(i.indcollation) WITH ORDINALITY x(oid,ord)),'predicate',pg_get_expr(i.indpred,i.indrelid,false))
 FROM relations c JOIN pg_catalog.pg_index i ON i.indexrelid=c.oid JOIN pg_catalog.pg_class r ON r.oid=i.indrelid JOIN pg_catalog.pg_am am ON am.oid=c.relam
 UNION ALL
 SELECT 'sequence:'||c.relname, jsonb_build_object('type',format_type(s.seqtypid,NULL),'start',s.seqstart,'increment',s.seqincrement,'min',s.seqmin,'max',s.seqmax,'cache',s.seqcache,'cycle',s.seqcycle,'owner',(SELECT jsonb_build_array(r.relname,a.attname) FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_class r ON r.oid=d.refobjid JOIN pg_catalog.pg_attribute a ON a.attrelid=r.oid AND a.attnum=d.refobjsubid WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.refclassid='pg_class'::regclass AND d.deptype IN ('a','i') LIMIT 1))
 FROM relations c JOIN pg_catalog.pg_sequence s ON s.seqrelid=c.oid
 UNION ALL
 SELECT 'trigger:'||r.relname||'.'||t.tgname, jsonb_build_object('definition',pg_get_triggerdef(t.oid,false),'enabled',t.tgenabled) FROM relations r JOIN pg_catalog.pg_trigger t ON t.tgrelid=r.oid WHERE NOT t.tgisinternal
 UNION ALL
 SELECT 'policy:'||r.relname||'.'||p.polname, jsonb_build_object('command',p.polcmd,'permissive',p.polpermissive,'roles',(SELECT jsonb_agg(CASE WHEN x.role_oid=0 THEN 'PUBLIC' ELSE pg_get_userbyid(x.role_oid) END ORDER BY x.ord) FROM unnest(p.polroles) WITH ORDINALITY x(role_oid,ord)),'using',pg_get_expr(p.polqual,p.polrelid,false),'check',pg_get_expr(p.polwithcheck,p.polrelid,false)) FROM relations r JOIN pg_catalog.pg_policy p ON p.polrelid=r.oid
 UNION ALL
 SELECT 'function:'||p.proname||'('||pg_get_function_identity_arguments(p.oid)||')', jsonb_build_object('kind',p.prokind,'result',pg_get_function_result(p.oid),'source',p.prosrc) FROM pg_catalog.pg_proc p JOIN target t ON t.oid=p.pronamespace
)
SELECT key, value::text AS value FROM objects ORDER BY key
SQL);
            $query->execute(['schema'=>$schema]);
            $snapshot=[];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (array_key_exists($row['key'],$snapshot)) throw new DatabaseException('Ambiguous catalog object identity.');
                $snapshot[$row['key']]=$row['value'];
            }
            ksort($snapshot,SORT_STRING);
            return $snapshot;
        } catch (\Throwable $e) {
            // On SQL failure the owning transaction must roll back; do not mask the original error.
            throw $e;
        } finally {
            try { if ($this->pdo->inTransaction()) { $set->execute(['path'=>$path]); $set->closeCursor(); } } catch (\PDOException) { /* Aborted transaction will be rolled back by caller. */ }
        }
    }

    public static function differences(array $expected,array $actual): array
    {
        $issues=[];
        foreach ($expected as $key=>$value) {
            if (!array_key_exists($key,$actual)) $issues[]=['object'=>$key,'change'=>'missing'];
            elseif ($actual[$key]!==$value) $issues[]=['object'=>$key,'change'=>'changed'];
        }
        foreach ($actual as $key=>$value) if (!array_key_exists($key,$expected)) $issues[]=['object'=>$key,'change'=>'unexpected'];
        usort($issues,static fn($a,$b)=>strcmp($a['object'],$b['object']));
        return $issues;
    }
}
