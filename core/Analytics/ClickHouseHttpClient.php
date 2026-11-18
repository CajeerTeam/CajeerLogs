<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

/** Strict, bounded ClickHouse HTTP transport. Only trusted application code supplies SQL. */
final class ClickHouseHttpClient
{
    private string $endpoint;
    private string $database;
    private string $username;
    private string $password;
    private int $timeout;
    private string $caFile;

    public function __construct(array $config)
    {
        $url=(string)($config['dsn']??'');
        $parts=parse_url($url);
        if ($parts===false || !is_array($parts) || !in_array($parts['scheme']??'', ['http','https'],true)
            || empty($parts['host']) || isset($parts['user'],$parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'', ['', '/'],true)
            || !filter_var($url,FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid ClickHouse DSN; supply an HTTP(S) origin without credentials or query.');
        }
        if ($parts['scheme']==='http' && ($config['allow_insecure']??false)!==true) {
            throw new \InvalidArgumentException('Plaintext ClickHouse requires explicit CLICKHOUSE_ALLOW_INSECURE=true on a private network.');
        }
        $db=(string)($config['database']??'');
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D',$db)) throw new \InvalidArgumentException('Invalid ClickHouse database name.');
        $user=(string)($config['username']??''); $password=(string)($config['password']??'');
        if ($user==='' || strlen($user)>128 || preg_match('/[\r\n]/',$user.$password)) throw new \InvalidArgumentException('Invalid ClickHouse credentials.');
        $timeout=filter_var($config['timeout_seconds']??5,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>30]]);
        if ($timeout===false) throw new \InvalidArgumentException('CLICKHOUSE_TIMEOUT_SECONDS must be 1..30.');
        $ca=(string)($config['ca_file']??'');
        if ($ca!=='' && (!is_file($ca) || is_link($ca) || !is_readable($ca))) throw new \InvalidArgumentException('Invalid ClickHouse CA file.');
        $this->endpoint=rtrim($url,'/').'/'; $this->database=$db; $this->username=$user; $this->password=$password;
        $this->timeout=$timeout; $this->caFile=$ca;
    }

    public function database(): string { return $this->database; }
    public function qualified(string $table): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D',$table)) throw new \InvalidArgumentException('Invalid table name.');
        return '`'.$this->database.'`.`'.$table.'`';
    }

    /** @return list<array<string,mixed>> */
    public function select(string $sql,array $params=[]): array
    {
        $data=json_decode($this->request($sql.' FORMAT JSON',$params,true),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data']) || !array_is_list($data['data'])) {
            throw new \RuntimeException('Unexpected ClickHouse JSON result.');
        }
        return $data['data'];
    }

    /** Execute a trusted DDL statement, without accepting SQL from API callers. */
    public function execute(string $sql): void { $this->request($sql,[],false); }

    /** Insert JSONEachRow with bounded payload and an explicit list of columns. */
    public function insert(string $table,array $rows,array $columns): void
    {
        if ($rows===[] || count($rows)>100) throw new \InvalidArgumentException('ClickHouse batch must contain 1..100 records.');
        foreach ($columns as $column) if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D',$column)) throw new \InvalidArgumentException('Invalid column.');
        $payload='';
        foreach ($rows as $row) {
            $payload.=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            if (strlen($payload)>1048576) throw new \InvalidArgumentException('ClickHouse insert exceeds 1 MiB.');
        }
        $query='INSERT INTO '.$this->qualified($table).' ('.implode(',',array_map(static fn($v)=>'`'.$v.'`',$columns)).') FORMAT JSONEachRow';
        $this->request($query,[],false,$payload);
    }

    private function request(string $query,array $params,bool $readonly,?string $payload=null): string
    {
        if (strlen($query)>16384) throw new \InvalidArgumentException('ClickHouse query too long.');
        $settings=[
            'database'=>preg_match('/^CREATE DATABASE\b/i',ltrim($query))?'default':$this->database,'wait_end_of_query'=>'1',
            'max_execution_time'=>(string)$this->timeout,
            'max_result_bytes'=>'2097152','max_result_rows'=>'1000',
            'result_overflow_mode'=>'throw','readonly'=>$readonly?'1':'0',
            'max_memory_usage'=>'268435456',
        ];
        foreach ($params as $key=>$value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$key) || !is_scalar($value)
                || strlen((string)$value)>512 || count($settings)>40) throw new \InvalidArgumentException('Invalid ClickHouse query parameter.');
            $settings['param_'.$key]=(string)$value;
        }
        if ($payload!==null) $settings['query']=$query;
        $url=$this->endpoint.'?'.http_build_query($settings,'','&',PHP_QUERY_RFC3986);
        $headers="Content-Type: text/plain; charset=utf-8\r\nX-ClickHouse-User: ".$this->username."\r\nX-ClickHouse-Key: ".$this->password."\r\nConnection: close\r\n";
        $http=['method'=>'POST','header'=>$headers,'content'=>$payload??$query,'timeout'=>$this->timeout,
            'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1];
        $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false];
        if ($this->caFile!=='') $ssl['cafile']=$this->caFile;
        $context=stream_context_create(['http'=>$http,'ssl'=>$ssl]);
        $failure=null;
        set_error_handler(static function(int $severity,string $message)use(&$failure):bool{$failure=$message;return true;});
        try { $stream=fopen($url,'rb',false,$context); }
        finally { restore_error_handler(); }
        if ($stream===false) throw new \RuntimeException('ClickHouse connection failed.');
        try {
            $meta=stream_get_meta_data($stream);
            $status=0;
            foreach (($meta['wrapper_data']??[]) as $line) {
                if (preg_match('~^HTTP/\S+ (\d{3})~',$line,$m)) $status=(int)$m[1];
            }
            $data=stream_get_contents($stream,2097153);
            $timedOut=stream_get_meta_data($stream)['timed_out']??false;
        } finally { fclose($stream); }
        if ($data===false || $timedOut) throw new \RuntimeException('ClickHouse request timed out or failed.');
        if (strlen($data)>2097152) throw new \RuntimeException('ClickHouse response exceeded 2 MiB.');
        if ($status<200 || $status>=300) throw new \RuntimeException('ClickHouse request failed (HTTP '.$status.').');
        return $data;
    }
}
