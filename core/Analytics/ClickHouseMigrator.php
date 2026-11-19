<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

/** Executable, checksum-fenced ClickHouse DDL migrations (single operator at a time). */
final class ClickHouseMigrator
{
    public function __construct(private readonly ClickHouseHttpClient $client,private readonly string $path) {}

    /** @return array{applied:list<string>,skipped:list<string>} */
    public function migrate(): array
    {
        // Validate the entire release manifest before any DDL or metadata writes.
        // ClickHouse DDL is not transactional; a malformed later migration must not
        // leave an earlier migration partially applied during this invocation.
        $files=self::manifest($this->path);
        $db='`'.$this->client->database().'`';
        $this->client->execute('CREATE DATABASE IF NOT EXISTS '.$db);
        $table=$this->client->qualified('analytics_schema_migrations');
        $this->client->execute('CREATE TABLE IF NOT EXISTS '.$table.' (version String, checksum FixedString(64), applied_at DateTime64(3,\'UTC\') DEFAULT now64(3)) ENGINE = MergeTree ORDER BY (version,applied_at)');
        $applied=self::validateHistory($files,$this->client->select('SELECT version, checksum FROM '.$table.' ORDER BY version'));
        $result=['applied'=>[],'skipped'=>[]];
        foreach ($files as $name=>$entry) {
            $script=$entry['sql'];
            $hash=$entry['checksum'];
            if (isset($applied[$name])) {
                if (!hash_equals($hash,$applied[$name])) throw new \RuntimeException('ClickHouse migration checksum mismatch: '.$name);
                $result['skipped'][]=$name;continue;
            }
            // SQL migration files are trusted application assets, not user input.
            // The schema ships with cajeer_logs as its reference database; substitute only a prevalidated identifier.
            $script=str_replace('cajeer_logs', $this->client->database(), $script);
            $script=preg_replace('/^\s*--[^\n]*$/m','',$script);
            foreach (explode(';',$script) as $statement) {
                $statement=trim($statement);
                if ($statement!=='') $this->client->execute($statement);
            }
            $this->client->insert('analytics_schema_migrations',[['version'=>$name,'checksum'=>$hash]],['version','checksum']);
            $result['applied'][]=$name;
        }
        return $result;
    }

    /** @return array<string,array{sql:string,checksum:string}> */
    public static function manifest(string $directory): array
    {
        $paths=glob(rtrim($directory,'/').'/*.sql');
        if (!$paths) throw new \RuntimeException('Missing ClickHouse migrations.');
        sort($paths,SORT_STRING);
        $files=[];$expected=1;
        foreach ($paths as $path) {
            $name=basename($path);
            if (!preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/D',$name,$match) || (int)$match[1]!==$expected++) {
                throw new \RuntimeException('Invalid ClickHouse migration sequence: '.$name);
            }
            if (is_link($path) || !is_file($path) || !is_readable($path)) throw new \RuntimeException('Unsafe ClickHouse migration file: '.$name);
            $sql=file_get_contents($path);
            if ($sql===false || trim($sql)==='') throw new \RuntimeException('Unreadable or empty ClickHouse migration: '.$name);
            $files[$name]=['sql'=>$sql,'checksum'=>hash('sha256',$sql)];
        }
        return $files;
    }

    /** Fail closed on unknown, duplicated, or gapped ClickHouse migration history.
     * ClickHouse DDL has no atomic rollback; never replay a missing prefix or
     * silently accept a future database schema with an older release binary.
     * @param array<string,array{sql:string,checksum:string}> $files
     * @param list<array<string,mixed>> $rows
     * @return array<string,string>
     */
    public static function validateHistory(array $files,array $rows): array
    {
        $applied=[];
        foreach ($rows as $row) {
            $name=(string)($row['version']??'');$hash=(string)($row['checksum']??'');
            if (!array_key_exists($name,$files)) throw new \RuntimeException('Unknown ClickHouse migration history: '.$name);
            if (isset($applied[$name])) throw new \RuntimeException('Duplicate ClickHouse migration history: '.$name);
            if (!hash_equals($files[$name]['checksum'],$hash)) throw new \RuntimeException('ClickHouse migration checksum mismatch: '.$name);
            $applied[$name]=$hash;
        }
        $seenMissing=false;
        foreach ($files as $name=>$file) {
            if (!isset($applied[$name])) $seenMissing=true;
            elseif ($seenMissing) throw new \RuntimeException('Gapped ClickHouse migration history: '.$name);
        }
        return $applied;
    }
}
