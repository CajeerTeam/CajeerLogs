<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Operations\BackupManager;
final class OperationsCommand
{
    public function __construct(private readonly Application $app) {}
    public function handle(string $command,array $args): int
    {
        $backup=new BackupManager($this->app);
        $n=count($args);
        $ops=new \Cajeer\Logs\Operations\BackupOperations($this->app);
        $execute=fn()=>match($command) {
            'audit:verify'=>$this->verifyAudit($args),
            'backup:run'=>$n===0?$ops->run():throw new DatabaseException('Usage: backup:run'),
            'backup:history'=>$n===0?$ops->history():throw new DatabaseException('Usage: backup:history'),
            'backup:replicate'=>$n===1?$ops->record('replicate',fn()=>$ops->replicate($args[0])):throw new DatabaseException('Usage: backup:replicate ID'),
            'backup:offsite-list'=>$n===0?$ops->offsiteList():throw new DatabaseException('Usage: backup:offsite-list'),
            'backup:offsite-verify'=>$n===1?$ops->offsiteVerify($args[0]):throw new DatabaseException('Usage: backup:offsite-verify ID'),
            'backup:fetch'=>$n===1?$ops->record('fetch',fn()=>$ops->fetch($args[0])):throw new DatabaseException('Usage: backup:fetch ID'),
            'backup:rehearse'=>$n===2?$ops->rehearse($args[0],$args[1]):throw new DatabaseException('Usage: backup:rehearse ID EMPTY_DB'),
            'backup:prune'=>$n===0||$args===['--confirm']?($n===1?$ops->record('prune',fn()=>$ops->prune(true)):$ops->prune(false)):throw new DatabaseException('Usage: backup:prune [--confirm]'),
            'backup:create'=>$n===0?$ops->record('manual_backup',fn()=>$backup->create()):throw new DatabaseException('Usage: backup:create'),
            'backup:list'=>$n===0?$backup->list():throw new DatabaseException('Usage: backup:list'),
            'backup:verify'=>$n===1?$backup->verify($args[0]):throw new DatabaseException('Usage: backup:verify BACKUP_ID'),
            'backup:restore'=>$n===2?$ops->record('manual_restore',fn()=>$backup->restore($args[0],$args[1])):throw new DatabaseException('Usage: backup:restore BACKUP_ID EMPTY_TARGET_DATABASE'),
            'auth:2fa-reset'=>$n===2 && $args[1]==='--confirm'?$this->reset($args[0]):throw new DatabaseException('Usage: auth:2fa-reset EMAIL --confirm'),
            'update:check'=>$n===1?$this->update($args[0]):throw new DatabaseException('Usage: update:check TRUSTED_RELEASE_JSON'),
            default=>throw new DatabaseException('Unknown operations command.'),
        };
        $result=str_starts_with($command,'backup:')?$ops->locked($execute,!in_array($command,['backup:verify','backup:restore','backup:rehearse','backup:replicate','backup:prune'],true)):$execute();
        if ($command==='backup:run') (new \Cajeer\Logs\Observability\WorkerHeartbeat($this->app->basePath('storage/cache')))->record('backup');
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        return $command==='audit:verify' && !$result['valid'] ? 1 : 0;
    }
    private function verifyAudit(array $args): array
    {
        if (count($args)!==0 && (count($args)!==2 || !ctype_digit($args[0]) || filter_var($args[0],FILTER_VALIDATE_INT)===false)) throw new DatabaseException('Usage: audit:verify [POSITION HASH]');
        $guard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
        try { return (new \Cajeer\Logs\Audit\AuditLogger($this->app->container()->get(\PDO::class)))->verify(isset($args[0])?(int)$args[0]:null,$args[1]??null); }
        finally { $guard->release(); }
    }
    private function reset(string $email): array
    {
        $pdo=$this->app->container()->get(\PDO::class);
        return (new TransactionManager($pdo))->run(function () use ($pdo,$email) {
            $identity=new \Cajeer\Logs\Security\Identity($pdo,true,''); $identity->lock();
            $user=(new \Cajeer\Logs\Database\Repository\UserRepository($pdo))->findByEmail($email);
            if (!$user) { throw new DatabaseException('User not found.'); }
            (new \Cajeer\Logs\Security\TwoFactorManager($pdo,''))->disable($user['id']);
            $identity->sql('DELETE FROM auth_sessions WHERE user_id=:id',['id'=>$user['id']]);
            (new \Cajeer\Logs\Audit\AuditLogger($pdo))->record('auth.totp_reset_cli',[],null,$user['id']);
            return ['user_id'=>$user['id'],'two_factor_enabled'=>false,'sessions_revoked'=>true];
        }, maxAttempts: 3);
    }
    private function update(string $path): array
    {
        if (!is_file($path)||!is_readable($path)||filesize($path)>65536) { throw new DatabaseException('Release metadata file unavailable.'); }
        $data=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR); $version=$data['version']??null;
        if (($data['name']??null)!=='CajeerLogs'||!is_string($version)||!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D',$version)) { throw new DatabaseException('Invalid CajeerLogs release metadata.'); }
        return ['current'=>$this->app->version(),'candidate'=>$version,'update_available'=>version_compare($version,$this->app->version(),'>'),'source'=>'local_release_metadata'];
    }
}
