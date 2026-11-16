<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Http\Request;
use PDO;

/** The same bearer and rate-limit policy for sync, async and job polling. */
final class IngestAuthorization
{
    private readonly Identity $identity;
    private readonly RateLimiter $rate;
    public function __construct(private readonly PDO $pdo, private readonly int $limit, private readonly ?RateLimitPolicy $policy=null)
    {
        $this->identity=new Identity($pdo,true,'');
        $this->rate=new RateLimiter($pdo);
    }

    public static function credentials(Request $request): array
    {
        $credential=Identity::credentials($request);
        if ($credential['kind']!=='token') Identity::forbidden();
        return $credential;
    }

    /** Only the server-provided peer address is trusted. Collapse IPv4-mapped IPv6. */
    public static function peerKey(Request $request): string
    {
        $packed=@inet_pton($request->remoteAddress);
        if ($packed===false) return 'unknown';
        if (strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") $packed=substr($packed,12);
        return inet_ntop($packed) ?: 'unknown';
    }

    /** Charge HTTP attempts outside the delivery transaction, even if it later rolls back. */
    public function begin(Request $request,array $credential): IngestPrincipal
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('Ingest preflight requires autocommit.');
        $this->policy?->peer($this->rate,$request);
        $this->rate->enforce('ingest-ip:'.self::peerKey($request),$this->limit,60);
        // Do not update last_used_at here: a row lock could wait outside the ingest SQL budget.
        $p=IngestPrincipal::fromIdentity($this->identity->authenticate($request,$credential,false,false,false));
        $this->policy?->principal($this->rate,'token',$p->id);
        $this->rate->enforce('ingest-token:'.$p->id,$this->limit,60);
        if ($p->requestsPerMinute!==null) $this->rate->enforce('credential:'.$p->id,$p->requestsPerMinute,60);
        return $p;
    }

    /** Reauthorize under the token row lock, without charging the request again. */
    public function locked(Request $request,array $credential,IngestPrincipal $expected,bool $touch=true): IngestPrincipal
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Ingest authorization requires a transaction.');
        $current=IngestPrincipal::fromIdentity($this->identity->authenticate($request,$credential,false,false,$touch));
        $expected->assertUnchanged($current);
        return $current;
    }
}
