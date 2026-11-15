<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Security\TwoFactorManager;
use PDO;

/** Server-authenticated navigation state. The credential is never the signing key. */
final class PageCursor
{
    public const TTL=3600;
    public function __construct(private readonly string $key,private readonly string $principal)
    {
        if (strlen($key)!==32 || $principal==='') throw new \InvalidArgumentException('Invalid cursor signer.');
    }
    /** Configured installations share a domain-separated master; otherwise persist
     * a random server key in the private settings table (never returned by an API). */
    public static function forPrincipal(PDO $pdo,string $master,array $principal): self
    {
        if (TwoFactorManager::keyValid($master)) {
            $key=hash_hkdf('sha256',hex2bin($master),32,'cajeerlogs:pagination:v2');
        } else {
            $read=static function () use ($pdo) {
                $stmt=$pdo->query("SELECT value #>> '{}' FROM settings WHERE key='pagination.signing_key'");
                $value=$stmt->fetchColumn(); $stmt->closeCursor(); return $value;
            };
            $secret=$read();
            if ($secret===false) {
                $stmt=$pdo->prepare("INSERT INTO settings(key,value) VALUES ('pagination.signing_key',to_jsonb(CAST(:secret AS text))) ON CONFLICT (key) DO NOTHING");
                $stmt->execute(['secret'=>bin2hex(random_bytes(32))]); $stmt->closeCursor();
                $secret=$read();
            }
            if (!is_string($secret)||!preg_match('/^[a-f0-9]{64}$/D',$secret)) throw new DatabaseException('Invalid pagination signing key.');
            $key=hex2bin($secret);
        }
        // Session hash binds cursors to the actual login, not merely the user UUID.
        return new self($key,$principal['kind'].':'.$principal['id'].':'.($principal['hash']??''));
    }
    private static function encode(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }
    private static function decode(string $encoded): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$encoded)) self::invalid();
        $raw=base64_decode(strtr($encoded,'-_','+/'),true);
        if ($raw===false||self::encode($raw)!==$encoded) self::invalid();
        return $raw;
    }
    private function binding(LogQuery $query): string
    {
        $filters=$query->filters; ksort($filters);
        return hash_hmac('sha256',json_encode([$this->principal,$query->consistency,$filters],JSON_THROW_ON_ERROR),$this->key);
    }
    public function issue(LogQuery $query,array $state): string
    {
        $p=['v'=>2,'f'=>$this->binding($query),'d'=>$state['d'],'t'=>$state['t'],'id'=>$state['id'],'w'=>$state['w'],'exp'=>$state['exp']];
        $body=self::encode(json_encode($p,JSON_THROW_ON_ERROR));
        return $body.'.'.self::encode(hash_hmac('sha256','cajeerlogs-page-v2.'.$body,$this->key,true));
    }
    public function open(LogQuery $query,?int $now=null): array
    {
        $now??=time();
        if ($query->cursor===null) return ['d'=>'older','t'=>null,'id'=>null,'w'=>null,'exp'=>$now+self::TTL];
        $parts=explode('.',$query->cursor);
        if (count($parts)!==2) self::invalid(); // v1 cursors are deliberately not accepted by HTTP.
        [$body,$mac]=$parts;
        if (!hash_equals(hash_hmac('sha256','cajeerlogs-page-v2.'.$body,$this->key,true),self::decode($mac))) self::invalid();
        try { $p=json_decode(self::decode($body),true,8,JSON_THROW_ON_ERROR); } catch (\JsonException) { self::invalid(); }
        if (!is_array($p)||array_keys($p)!==['v','f','d','t','id','w','exp']||$p['v']!==2||$p['f']!==$this->binding($query)||!in_array($p['d'],['older','newer'],true)||!is_int($p['exp'])) self::invalid();
        if ($p['exp']<=$now) throw new HttpException(422,'invalid_query','Cursor expired. Restart the search.');
        if ($p['exp']>$now+self::TTL+60) self::invalid();
        if (($p['t']===null)!==($p['id']===null)||($p['t']===null&&$p['d']!=='older')) self::invalid();
        if ($p['t']!==null) {
            if (!is_string($p['t'])||!is_string($p['id'])) self::invalid();
            $p['t']=LogQuery::timestamp($p['t']); $p['id']=LogQuery::uuid($p['id']);
        }
        if ($query->consistency==='snapshot') {
            if (!is_string($p['w'])) self::invalid();
            $p['w']=LogQuery::timestamp($p['w']);
        } elseif ($p['w']!==null) self::invalid();
        return array_intersect_key($p,array_flip(['d','t','id','w','exp']));
    }
    private static function invalid(): never { throw new HttpException(422,'invalid_query','Invalid cursor or changed filters/access. Restart the search.'); }
}
