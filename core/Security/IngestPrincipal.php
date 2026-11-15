<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Database\Repository\TokenRepository;
use Cajeer\Logs\Http\HttpException;

/** Request-local authorization snapshot. Never contains a bearer secret or digest. */
final readonly class IngestPrincipal
{
    private function __construct(public string $id, public string $projectId, public ?string $sourceId,
        public array $scopes, public ?string $serviceAccountId, public ?int $requestsPerMinute) {}

    public static function fromIdentity(array $identity): self
    {
        if (($identity['kind']??null)!=='token' || !is_array($identity['token']??null)) Identity::forbidden();
        $t=$identity['token'];
        $id=$identity['id']??null; $project=$t['project_id']??null;
        $source=$t['source_id']??null; $account=$t['service_account_id']??null;
        $rate=$t['requests_per_minute']??null;
        if (!self::uuid($id) || !self::uuid($project) || ($t['id']??null)!==$id
            || ($source!==null && !self::uuid($source)) || ($account!==null && !self::uuid($account))
            || ($rate!==null && (!is_int($rate)||$rate<1||$rate>1000000))) Identity::forbidden();
        try { $scopes=TokenRepository::scopes($t['scopes']??null,$project); }
        catch (HttpException) { Identity::forbidden(); }
        if (!in_array('logs:write',$scopes,true)) Identity::forbidden();
        return new self($id,$project,$source,$scopes,$account,$rate);
    }

    private static function uuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v)===1;
    }

    /** A policy change during preflight must not authorize work using stale limits. */
    public function assertUnchanged(self $current): void
    {
        if ($this->id!==$current->id || $this->projectId!==$current->projectId || $this->sourceId!==$current->sourceId
            || $this->serviceAccountId!==$current->serviceAccountId || $this->scopes!==$current->scopes) Identity::forbidden();
        if ($this->requestsPerMinute!==$current->requestsPerMinute) {
            throw new HttpException(503,'authorization_changed','Credential policy changed. Retry the request.',['Retry-After'=>'1']);
        }
    }

    public function assertSource(string $sourceId): void
    {
        if (!self::uuid($sourceId) || ($this->sourceId!==null && $this->sourceId!==$sourceId)) Identity::forbidden();
    }
}
