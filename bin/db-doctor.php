#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Repository;

$printSql = in_array('--sql', $argv, true);
$repo = new Repository(Database::pdo());
$expected = (string)Env::get('DB_USERNAME', '');
$db = (string)Env::get('DB_DATABASE', '');

echo "Database: {$db}\n";
echo "Expected owner: {$expected}\n\n";

if (Database::driver() !== 'pgsql') {
    echo "SQLite mode: ownership fix is not required.\n";
    exit(0);
}

$bad = [];
foreach ($repo->dbOwnershipReport() as $row) {
    $ok = !isset($row['ok']) || $row['ok'];
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $row['kind'] . ' ' . $row['name'] . ' owner=' . $row['owner'] . "\n";
    if (!$ok) {
        $bad[] = $row;
    }
}

if (!$bad) {
    echo "\nOwnership is OK.\n";
    exit(0);
}

echo "\nRun this SQL as PostgreSQL superuser/root role:\n\n";
$sql = [];
$sql[] = 'ALTER DATABASE "' . str_replace('"', '""', $db) . '" OWNER TO "' . str_replace('"', '""', $expected) . '";';
$sql[] = 'ALTER SCHEMA public OWNER TO "' . str_replace('"', '""', $expected) . '";';
$sql[] = 'GRANT ALL ON SCHEMA public TO "' . str_replace('"', '""', $expected) . '";';
$sql[] = 'DO $$';
$sql[] = 'DECLARE r RECORD;';
$sql[] = 'BEGIN';
$sql[] = "  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP";
$sql[] = "    EXECUTE format('ALTER TABLE public.%I OWNER TO %I;', r.tablename, '" . str_replace("'", "''", $expected) . "');";
$sql[] = '  END LOOP;';
$sql[] = "  FOR r IN SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' LOOP";
$sql[] = "    EXECUTE format('ALTER SEQUENCE public.%I OWNER TO %I;', r.sequencename, '" . str_replace("'", "''", $expected) . "');";
$sql[] = '  END LOOP;';
$sql[] = 'END $$;';

echo implode("\n", $sql) . "\n";
exit($printSql ? 0 : 2);
