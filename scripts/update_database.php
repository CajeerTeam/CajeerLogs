<?php
declare(strict_types=1);
// Operator-only subprocess of the native updater. Never expose through HTTP.
use Cajeer\Logs\Config\Environment;
use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Installer\SecureFile;
use Cajeer\Logs\Operations\BackupManager;
use Cajeer\Logs\Operations\BackupOperations;
if (PHP_SAPI !== 'cli') {http_response_code(404);exit(1);}
ini_set('display_errors','0');
try {
    $app=require dirname(__DIR__).'/core/bootstrap.php';
    $app->boot();
    $backup=new BackupManager($app);
    $action=$argv[1]??'';
    $config=$app->config();
    $env=$app->environmentPath();
    if ($env!==$app->basePath('.env') || !SecureFile::privateFile($env)) throw new RuntimeException('Native update requires a local private .env.');
    $file=Environment::load($env,true);
    if (($file['DB_DATABASE']??null)!==$config->get('database.database') || getenv('DB_DATABASE')!==false) throw new RuntimeException('DB_DATABASE must be explicitly managed in .env, not overridden.');
    foreach (['DB_MIGRATION_USERNAME','DB_MIGRATION_PASSWORD','DB_MIGRATION_PASSWORD_FILE'] as $key) { if (getenv($key)!==false || isset($file[$key])) throw new RuntimeException('Split-role deployments require manual upgrade.'); }
    if ($config->get('operations.backup_dir')!=='') throw new RuntimeException('Native update requires default local backup directory.');
    $result=match($action) {
        'inspect'=>count($argv)===2?['version'=>$app->version(),'database'=>$config->get('database.database'),'schema'=>$app->container()->get(ConnectionFactory::class)->schema(),'url'=>$config->get('app.url')]:throw new RuntimeException('Invalid inspect arguments.'),
        'snapshot'=>count($argv)===3 && $argv[2]==='--confirm-stopped'?(new BackupOperations($app))->locked(function() use($backup) { $backup->assertUpdateQuiescent(); $m=$backup->create(); return $backup->verify($m['id']); }):throw new RuntimeException('Snapshot requires --confirm-stopped.'),
        'clone'=>count($argv)===5 && $argv[4]==='--confirm-stopped'?(new BackupOperations($app))->locked(fn()=>$backup->cloneForUpdate($argv[2],$argv[3]),false):throw new RuntimeException('Clone requires backup ID, empty target database and --confirm-stopped.'),
        'quiescent'=>count($argv)===2?(function() use($backup) {$backup->assertUpdateQuiescent();return ['status'=>'ok'];})():throw new RuntimeException('Invalid quiescence arguments.'),
        default=>throw new RuntimeException('Unknown maintenance operation.'),
    };
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {fwrite(STDERR,"Update database operation failed: ".$e->getMessage().PHP_EOL);exit(1);}
