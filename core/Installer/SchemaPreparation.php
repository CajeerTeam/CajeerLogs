<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\{ConnectionFactory,DatabaseException,PostgresBoolean,SchemaInspector,TransactionManager};
use Cajeer\Logs\Kernel\Application;

/** Explicit, least-privilege preparation of a dedicated empty PostgreSQL schema. */
final class SchemaPreparation
{
    public function __construct(private readonly Application $app) {}

    public function run(): array
    {
        $factory=$this->app->container()->get(ConnectionFactory::class);
        $schema=$factory->schema();
        $runtime=$this->app->config()->get('database.username');
        if ($schema==='public' || !is_string($runtime) || !preg_match('/^[A-Za-z0-9_.-]{1,63}$/D',$runtime)) {
            throw new DatabaseException('Schema preparation requires a dedicated non-public DB_SCHEMA and valid runtime role.');
        }
        $pdo=$factory->make('migration-bootstrap');
        $result=(new TransactionManager($pdo))->run(function () use ($pdo,$schema,$runtime) {
            $lock=$pdo->prepare('SELECT pg_try_advisory_xact_lock(hashtextextended(:key,0))');
            $lock->execute(['key'=>'cajeerlogs:install-session:'.$schema]);
            if (!PostgresBoolean::required($lock->fetchColumn())) { throw new DatabaseException('Another database installation is running.'); }
            $lock->closeCursor();
            $migrator=$pdo->query('SELECT current_user')->fetchColumn();
            if ($migrator===$runtime) { throw new DatabaseException('Schema preparation requires distinct migration and runtime roles.'); }
            if (PostgresBoolean::required($pdo->query("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname=current_user")->fetchColumn())) {
                throw new DatabaseException('Use a dedicated migration role, not a privileged PostgreSQL administrator.');
            }
            $role=$pdo->prepare("SELECT rolcanlogin AND NOT (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls) AND has_database_privilege(oid,current_database(),'CONNECT') FROM pg_roles WHERE rolname=:name");
            $role->execute(['name'=>$runtime]);
            if (!PostgresBoolean::required($role->fetchColumn())) { throw new DatabaseException('Runtime role must exist, have LOGIN/CONNECT and no elevated cluster privileges.'); }
            $role->closeCursor();
            $membership=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM pg_roles r WHERE r.rolname<>:runtime AND (r.rolname=current_user OR r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolbypassrls OR r.rolname IN ('pg_read_all_data','pg_write_all_data','pg_execute_server_program','pg_read_server_files','pg_write_server_files')) AND pg_has_role(CAST(:member AS name),r.oid,'MEMBER'))");
            $membership->execute(['runtime'=>$runtime,'member'=>$runtime]);
            if (PostgresBoolean::required($membership->fetchColumn())) throw new DatabaseException('Runtime role must not inherit or assume migration or privileged roles.');
            $membership->closeCursor();
            $owner=$pdo->prepare('SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname=:schema');
            $owner->execute(['schema'=>$schema]); $current=$owner->fetchColumn(); $owner->closeCursor();
            $created=$current===false;
            $quotedSchema='"'.$schema.'"'; $quotedRole='"'.$runtime.'"';
            if ($created) {
                if (!PostgresBoolean::required($pdo->query("SELECT has_database_privilege(current_user,current_database(),'CREATE')")->fetchColumn())) { throw new DatabaseException('Migration role needs CREATE on the database to prepare a schema.'); }
                $pdo->exec('CREATE SCHEMA '.$quotedSchema.' AUTHORIZATION CURRENT_USER');
            } else {
                // Include types, operators and other namespace-bound objects beyond the schema fingerprint.
                $objects=$pdo->prepare("SELECT EXISTS(SELECT 1 FROM pg_depend d JOIN pg_namespace n ON n.oid=d.refobjid WHERE d.refclassid='pg_namespace'::regclass AND d.classid<>'pg_default_acl'::regclass AND n.nspname=:schema)");
                $objects->execute(['schema'=>$schema]); $nonempty=PostgresBoolean::required($objects->fetchColumn()); $objects->closeCursor();
                if ($current!==$migrator || $nonempty || (new SchemaInspector($pdo))->snapshot($schema)!==[]) {
                    throw new DatabaseException('Schema preparation refuses a nonempty schema or one owned by another role. Use DBA-managed grants for existing installations.');
                }
            }
            // Scoped to this dedicated schema; no changes to public, database ownership or cluster roles.
            $pdo->exec('GRANT USAGE ON SCHEMA '.$quotedSchema.' TO '.$quotedRole);
            $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$quotedSchema.' GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.$quotedRole);
            $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA '.$quotedSchema.' GRANT USAGE, SELECT ON SEQUENCES TO '.$quotedRole);
            $permission=$pdo->prepare("SELECT has_schema_privilege(CAST(:role AS text),CAST(:schema AS text),'CREATE')");
            $permission->execute(['role'=>$runtime,'schema'=>$schema]);
            if (PostgresBoolean::required($permission->fetchColumn())) { throw new DatabaseException('Runtime role must not inherit CREATE on the dedicated schema.'); }
            return ['schema'=>$schema,'schema_created'=>$created,'runtime_role'=>$runtime,'migration_role'=>$migrator];
        });
        // Verify actual runtime credentials after committing schema preparation. An empty schema can be retried safely.
        $factory->make('pgsql');
        return $result+['runtime_connection_verified'=>true];
    }
}
