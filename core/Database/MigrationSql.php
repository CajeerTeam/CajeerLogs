<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

/** Trusted release SQL, not a general-purpose SQL console. Reject transaction escapes. */
final class MigrationSql
{
    public static function parse(string $sql): array
    {
        $mode='transactional';
        if (preg_match('/\A-- cajeer:(concurrent-index|backfill)\s*\r?\n/', $sql, $m)) { $mode=$m[1]; }
        // Tokenize PostgreSQL quoted strings/identifiers, dollar quotes and nested comments.
        $statements=[]; $words=[]; $start=0; $n=strlen($sql); $i=0;
        while ($i<$n) {
            $c=$sql[$i]; $pair=substr($sql,$i,2);
            if (ctype_space($c)) { ++$i; continue; }
            if ($pair==='--') { $end=strpos($sql,"\n",$i); $i=$end===false?$n:$end+1; continue; }
            if ($pair==='/*') {
                $depth=1; $i+=2;
                while ($i<$n && $depth>0) { $p=substr($sql,$i,2); if ($p==='/*') { ++$depth; $i+=2; } elseif ($p==='*/') { --$depth; $i+=2; } else { ++$i; } }
                if ($depth!==0) throw new DatabaseException('Unterminated migration comment.');
                continue;
            }
            if ($c==="'" || $c==='"') {
                // E'...' uses backslash escaping; regular strings use standard_conforming_strings.
                $escape=$c==="'" && $i>0 && in_array($sql[$i-1],['e','E'],true) && ($i<2 || !preg_match('/[A-Za-z0-9_]/',$sql[$i-2]));
                $quote=$c; ++$i; $closed=false;
                while ($i<$n) { if ($escape && $sql[$i]==='\\') { $i+=2; continue; } if ($sql[$i]===$quote) { if (($sql[$i+1]??'')===$quote) { $i+=2; continue; } ++$i; $closed=true; break; } ++$i; }
                if (!$closed) throw new DatabaseException('Unterminated migration literal.');
                $words[]='?'; continue;
            }
            if ($c==='$' && preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/A',$sql,$m,0,$i)) {
                $end=strpos($sql,$m[0],$i+strlen($m[0])); if ($end===false) throw new DatabaseException('Unterminated migration dollar quote.');
                $i=$end+strlen($m[0]); $words[]='?'; continue;
            }
            if ($c===';') { if ($words!==[]) { $statements[]=['sql'=>trim(substr($sql,$start,$i-$start)), 'words'=>$words]; } $words=[]; $start=++$i; continue; }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/A',$sql,$m,0,$i)) { $words[]=strtoupper($m[0]); $i+=strlen($m[0]); continue; }
            $words[]=$c; ++$i;
        }
        if ($words!==[]) $statements[]=['sql'=>trim(substr($sql,$start)), 'words'=>$words];
        if ($statements===[]) throw new DatabaseException('Empty migration SQL.');
        $index=null;
        if ($mode==='concurrent-index') {
            // Deliberately narrow: non-unique, named, ordinary-column indexes. Recovery can
            // safely rebuild an interrupted index without changing uniqueness semantics.
            $body=trim(preg_replace('/\A-- cajeer:concurrent-index\s*\r?\n/','',$sql)??'');
            if (count($statements)!==1 || !preg_match('/\ACREATE\s+INDEX\s+CONCURRENTLY\s+([a-z_][a-z0-9_]{0,62})\s+ON\s+([a-z_][a-z0-9_]{0,62})(?:\s+USING\s+(btree|gin))?\s*\(\s*([a-z_][a-z0-9_]{0,62}(?:\s+(?:ASC|DESC))?(?:\s*,\s*[a-z_][a-z0-9_]{0,62}(?:\s+(?:ASC|DESC))?)*)\s*\)\s*;?\z/iD',$body,$m)) {
                throw new DatabaseException('Concurrent migration requires one non-unique CREATE INDEX CONCURRENTLY on ordinary columns.');
            }
            $index=['name'=>strtolower($m[1]),'table'=>strtolower($m[2]),'sql'=>$body];
        } elseif ($mode==='backfill') {
            $w=$statements[0]['words']; $depth=0; $top=[];
            foreach ($w as $token) { if ($token==='(') { ++$depth; } elseif ($token===')') { --$depth; } elseif ($depth===0) { $top[]=$token; } }
            $main=null; foreach (array_slice($top,1) as $token) { if (in_array($token,['SELECT','INSERT','UPDATE','DELETE','MERGE'],true)) { $main=$token; break; } }
            if (count($statements)!==1 || $w[0]!=='WITH' || $main!=='UPDATE' || !in_array('LIMIT',$w,true) || !in_array('WHERE',$top,true) || !in_array('RETURNING',$top,true) || count(array_filter($w,static fn($t)=>$t==='UPDATE'))!==1 || in_array('DELETE',$w,true) || in_array('INSERT',$w,true)) {
                throw new DatabaseException('Backfill requires one bounded candidate CTE followed by an idempotent UPDATE ... WHERE ... RETURNING.');
            }
        } else {
            foreach ($statements as $statement) {
                $w=$statement['words']; $first=$w[0];
                if (!in_array($first,['CREATE','ALTER','DROP','INSERT','UPDATE','DELETE','SELECT','WITH','COMMENT'],true) || in_array('CONCURRENTLY',$w,true)) throw new DatabaseException('Unsupported migration statement or missing concurrent-index directive.');
                if ($first==='CREATE' && !in_array($w[1]??'',['TABLE','INDEX','UNIQUE','SEQUENCE','VIEW'],true)) throw new DatabaseException('Unsupported CREATE in transactional migration.');
                if (in_array($first,['ALTER','DROP'],true) && !in_array($w[1]??'',['TABLE','INDEX','SEQUENCE','VIEW'],true)) throw new DatabaseException('Unsupported schema operation in migration.');
            }
        }
        return ['mode'=>$mode,'statements'=>$statements,'index'=>$index];
    }
}
