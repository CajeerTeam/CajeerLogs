<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

/** Verify security-sensitive function attributes not covered by legacy schema snapshots.
 * The expected attributes are derived from immutable migrations 0019 and 0020.
 */
final class SecuritySchema
{
    public static function differences(PDO $pdo, string $schema, array $history): array
    {
        $expected=[];
        if (isset($history['0019_audit_accountability.sql'])) {
            $expected=['audit_scope'=>['sql','i',false,true], 'audit_digest'=>['sql','i',false,true],
                'audit_append'=>['plpgsql','v',false,true], 'audit_immutable'=>['plpgsql','v',false,true]];
        }
        if (isset($history['0020_rate_limit_buckets.sql'])) $expected['api_rate_charge']=['plpgsql','v',true,false];
        if (isset($history['0022_queue_accounting.sql'])) { $expected['queue_account_delta']=['plpgsql','v',false,true]; $expected['queue_reject_truncate']=['plpgsql','v',false,true]; }
        if (isset($history['0024_project_queue_fairness.sql'])) foreach (['queue_project_create','queue_project_delta','queue_job_scope_immutable'] as $fn) $expected[$fn]=['plpgsql','v',false,true];
        if (isset($history['0025_queue_operations_metrics.sql'])) $expected['queue_attempt_expiry_metric']=['plpgsql','v',false,true];
        if ($expected===[]) return [];
        $q=$pdo->prepare(<<<'SQL'
            SELECT p.proname, l.lanname, p.provolatile, p.proretset,
                p.prosecdef, p.proisstrict, p.proleakproof, p.proparallel, p.prokind,
                p.proconfig = ARRAY['search_path=pg_catalog']::text[] AS fixed_path,
                p.proconfig IS NULL AS default_path
            FROM pg_catalog.pg_proc p
            JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
            JOIN pg_catalog.pg_language l ON l.oid=p.prolang
            WHERE n.nspname=:schema AND p.proname IN ('audit_scope','audit_digest','audit_append','audit_immutable','api_rate_charge','queue_account_delta','queue_reject_truncate','queue_project_create','queue_project_delta','queue_job_scope_immutable','queue_attempt_expiry_metric')
            SQL);
        $q->execute(['schema'=>$schema]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        $seen=[]; $issues=[];
        foreach ($rows as $row) {
            $name=$row['proname'];
            if (!isset($expected[$name])) continue;
            [$language,$volatility,$set,$fixed]=$expected[$name];
            $valid=!isset($seen[$name]) && $row['lanname']===$language && $row['provolatile']===$volatility
                && $row['proretset']===$set && $row['prosecdef']===false && $row['proisstrict']===false
                && $row['proleakproof']===false && $row['proparallel']==='u' && $row['prokind']==='f'
                && ($fixed ? $row['fixed_path']===true : $row['default_path']===true);
            $seen[$name]=true;
            if (!$valid) $issues[]=['object'=>'function-security:'.$name,'change'=>'changed'];
        }
        foreach ($expected as $name=>$_) if (!isset($seen[$name])) $issues[]=['object'=>'function-security:'.$name,'change'=>'missing'];
        return $issues;
    }

    /** Runtime needs EXECUTE as well as the relation grants. */
    public static function inaccessible(PDO $pdo,string $schema): array
    {
        $q=$pdo->prepare("SELECT p.proname FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=:schema AND p.proname IN ('audit_scope','audit_digest','audit_append','audit_immutable','api_rate_charge','queue_account_delta','queue_reject_truncate','queue_project_create','queue_project_delta','queue_job_scope_immutable','queue_attempt_expiry_metric') AND NOT pg_catalog.has_function_privilege(current_user,p.oid,'EXECUTE') ORDER BY p.proname");
        $q->execute(['schema'=>$schema]); $missing=$q->fetchAll(PDO::FETCH_COLUMN); $q->closeCursor(); return $missing;
    }
}
