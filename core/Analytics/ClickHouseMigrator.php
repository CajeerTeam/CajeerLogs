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
        $db='`'.$this->client->database().'`';
        $this->client->execute('CREATE DATABASE IF NOT EXISTS '.$db);
        $table=$this->client->qualified('analytics_schema_migrations');
        $this->client->execute('CREATE TABLE IF NOT EXISTS '.$table.' (version String, checksum FixedString(64), applied_at DateTime64(3,\'UTC\') DEFAULT now64(3)) ENGINE = MergeTree ORDER BY (version,applied_at)');
        $applied=[];
        foreach ($this->client->select('SELECT version, checksum FROM '.$table.' ORDER BY version') as $entry) {
            $name=(string)($entry['version']??'');$hash=(string)($entry['checksum']??'');
            if (isset($applied[$name]) && $applied[$name]!==$hash) throw new \RuntimeException('Conflicting ClickHouse migration history.');
            $applied[$name]=$hash;
        }
        $files=glob(rtrim($this->path,'/').'/*.sql');
        if ($files===false || $files===[]) throw new \RuntimeException('Missing ClickHouse migrations.');
        sort($files,SORT_STRING);
        $result=['applied'=>[],'skipped'=>[]];$expected=1;
        foreach ($files as $file) {
            $name=basename($file);if (!preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/D',$name,$m) || (int)$m[1]!==$expected++) throw new \RuntimeException('Invalid ClickHouse migration sequence.');
            $script=file_get_contents($file);if ($script===false) throw new \RuntimeException('Unreadable ClickHouse migration.');
            $hash=hash('sha256',$script);
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
}
