<?php
declare(strict_types=1);

use Cajeer\Logs\Database\{DatabaseException,PostgresBoolean};
use Cajeer\Logs\Http\{HttpException,Request};
use Cajeer\Logs\Log\Redactor;
use Cajeer\Logs\Security\Identity;

require dirname(__DIR__).'/core/bootstrap.php';
$count=0;
function hardened(bool $condition,string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    ++$count; echo "PASS $message\n";
}
function denied(callable $callback,string $class,?int $status=null): bool {
    try { $callback(); } catch (Throwable $e) {
        return $e instanceof $class && ($status===null || ($e instanceof HttpException && $e->status===$status));
    }
    return false;
}
foreach ([true,1,'1','t','true'] as $value) hardened(PostgresBoolean::required($value)===true,'PostgreSQL true '.json_encode($value));
foreach ([false,0,'0','f','false'] as $value) hardened(PostgresBoolean::required($value)===false,'PostgreSQL false '.json_encode($value));
foreach ([null,'', 'yes', 'no','FALSE',[],2] as $value) hardened(denied(fn()=>PostgresBoolean::required($value),DatabaseException::class),'unknown policy fails closed '.json_encode($value));
$redactor=new Redactor();
foreach (['apiKey','accessToken','refreshToken','clientSecret','privateKey','idToken','proxyAuthorization','setCookie'] as $name) {
    $secret='SensitiveValue'.bin2hex(random_bytes(5));
    hardened(!str_contains($redactor->text('SDK '.$name.'='.$secret),$secret),'camelCase secret masked '.$name);
    hardened(!str_contains($redactor->text('{"'.$name.'":"'.$secret.'"}'),$secret),'JSON text secret masked '.$name);
}
hardened(Identity::publicUser(['id'=>'1','two_factor_enabled'=>'f'])['two_factor_enabled']===false,'disabled TOTP remains disabled');
hardened(Identity::publicUser(['id'=>'1','two_factor_enabled'=>'t'])['two_factor_enabled']===true,'enabled TOTP remains enabled');
hardened(denied(fn()=>Identity::publicUser(['two_factor_enabled'=>'unknown']),DatabaseException::class),'invalid TOTP state rejected');
// Exercise the real front-controller parsing path, including PHP's server-variable mapping.
$original=$_SERVER;
try {
    $_SERVER=['REQUEST_URI'=>'/api/v1/auth/login','REQUEST_METHOD'=>'POST','HTTP_TRANSFER_ENCODING'=>'chunked','CONTENT_LENGTH'=>'0','REMOTE_ADDR'=>'127.0.0.1'];
    hardened(denied(fn()=>Request::fromGlobals(4096,'/api/v1'),HttpException::class,400),'ambiguous non-ingest framing rejected');
    $_SERVER['REQUEST_URI']='/api/v1/ingest';
    hardened(denied(fn()=>Request::fromGlobals(4096,'/api/v1'),HttpException::class,400),'ambiguous ingest framing rejected');
    unset($_SERVER['HTTP_TRANSFER_ENCODING']);
    hardened(Request::fromGlobals(4096,'/api/v1')->header('Content-Length')==='0','unambiguous framing accepted');
} finally { $_SERVER=$original; }
// Restore quarantine is a security boundary: a text 't' from PDO must not bypass it.
final class HardenedBooleanRow extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function fetchColumn(int $column=0): mixed { return $this->value; }
    public function execute(?array $params=null): bool { return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed { return false; }
}
final class HardenedBooleanDb extends PDO {
    public function __construct(private mixed $value) {}
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false {
        return new HardenedBooleanRow($this->value);
    }
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return new HardenedBooleanRow($this->value);
    }
}
foreach (['t',true] as $value) {
    $db=new HardenedBooleanDb($value);
    hardened(\Cajeer\Logs\Database\RestoreFence::pending($db),'restore pending detected '.json_encode($value));
    hardened(denied(fn()=>\Cajeer\Logs\Database\RestoreFence::assertAvailable($db),DatabaseException::class),'quarantined database rejects access '.json_encode($value));
}
foreach (['f',false] as $value) {
    $db=new HardenedBooleanDb($value);
    hardened(!\Cajeer\Logs\Database\RestoreFence::pending($db),'published database detected '.json_encode($value));
    \Cajeer\Logs\Database\RestoreFence::assertAvailable($db);
}
hardened(denied(fn()=>\Cajeer\Logs\Database\RestoreFence::pending(new HardenedBooleanDb('unknown')),DatabaseException::class),'unexpected restore status fails closed');
foreach (['t',true] as $value) {
    $repo=new \Cajeer\Logs\Database\Repository\UserRepository(new HardenedBooleanDb($value));
    hardened(denied(fn()=>$repo->verifyInstallerAdministrator('admin@example.org','not-a-password'),DatabaseException::class),'existing administrator blocks duplicate install '.json_encode($value));
}
foreach (['f',false] as $value) {
    $repo=new \Cajeer\Logs\Database\Repository\UserRepository(new HardenedBooleanDb($value));
    hardened($repo->verifyInstallerAdministrator('admin@example.org','not-a-password')===null,'empty installer accepts first administrator '.json_encode($value));
}
echo "Security hardening: $count checks passed\n";
