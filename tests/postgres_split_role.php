<?php
declare(strict_types=1);
use Cajeer\Logs\Database\ConnectionFactory;
use Cajeer\Logs\Kernel\Application;
if(getenv('CAJEER_TEST_DATABASE')!=='1'||getenv('CAJEER_TEST_SPLIT_ROLE')!=='1'){fwrite(STDERR,"Provision disposable cl_split_role schema and CI roles first.\n");exit(1);}
$root=dirname(__DIR__);require $root.'/core/bootstrap.php';
$tmp=sys_get_temp_dir().'/cl-split-'.bin2hex(random_bytes(6));mkdir($tmp,0700);$file=$tmp.'/.env';
$env=array_merge(getenv(),['CAJEER_ENV_FILE'=>$file,'APP_ENV'=>'test','APP_URL'=>'http://localhost:8080','DB_SCHEMA'=>'cl_split_role','DB_USERNAME'=>'cl_runtime_ci','DB_PASSWORD'=>'ci-runtime-split-password','DB_MIGRATION_USERNAME'=>'cl_migrator_ci','DB_MIGRATION_PASSWORD'=>'ci-migration-split-password','CAJEER_ADMIN_EMAIL'=>'split@example.test','CAJEER_ADMIN_PASSWORD'=>'ci-split-admin-password-123']);
function cli(array $args,array $env):string{global $root;$p=proc_open([PHP_BINARY,$root.'/bin/cajeer',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);if(!is_resource($p))throw new RuntimeException('CLI process failed');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('CLI failed: '.$args[0].' '.$err);return $out;}
try{
    cli(['install:check'],$env);$out=cli(['install','--non-interactive'],$env);if(!str_contains($out,'Administrator created'))throw new RuntimeException('First administrator not created');
    if(str_contains(file_get_contents($file),'DB_MIGRATION_')||str_contains(file_get_contents($file),'ci-migration-split-password'))throw new RuntimeException('Migration credential persisted');
    unset($env['DB_MIGRATION_USERNAME'],$env['DB_MIGRATION_PASSWORD']);cli(['db:doctor'],$env);cli(['migrate:status'],$env);
    $app=new Application($root);$app->boot();$c=$app->config();$c->set('database.username','cl_runtime_ci');$c->set('database.password','ci-runtime-split-password');$c->set('database.schema','cl_split_role');$pdo=(new ConnectionFactory($c))->make('pgsql');
    if($pdo->query("SELECT current_user='cl_runtime_ci' AND NOT has_schema_privilege(current_user,current_schema(),'CREATE')")->fetchColumn()!==true)throw new RuntimeException('Runtime role is not restricted');
    if($pdo->query("SELECT count(*) FROM users WHERE email='split@example.test' AND role='administrator'")->fetchColumn()!=1)throw new RuntimeException('Administrator missing');
    if($pdo->query("SELECT tableowner FROM pg_tables WHERE schemaname=current_schema() AND tablename='users'")->fetchColumn()!=='cl_migrator_ci')throw new RuntimeException('Wrong table owner');
    echo "PASS separate CLI migration role, restricted runtime, first administrator, no elevated credentials on disk\n";
}finally{if(is_file($file))unlink($file);rmdir($tmp);}
