<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDOException;

/** Safe classifications: never expose libpq diagnostics or SQL parameter values. */
final class DatabaseFailure
{
    public static function category(\Throwable $error): string
    {
        for ($e=$error; $e!==null; $e=$e->getPrevious()) {
            if ($e instanceof DatabaseException && ($e->category!=='configuration' || $e->getPrevious()===null)) { return $e->category; }
            if (!$e instanceof PDOException) { continue; }
            $state=(string)($e->errorInfo[0]??$e->getCode());
            $message=strtolower($e->getMessage());
            // libpq connection errors often arrive as 08006 even for authentication/TLS.
            if (str_starts_with($state,'28') || (($state==='HY000'||str_starts_with($state,'08')) && preg_match('/password authentication failed|no password supplied|no pg_hba.conf entry|role .* does not exist|authentication method/', $message))) { return 'authentication'; }
            if (($state==='HY000'||str_starts_with($state,'08')) && preg_match('/certificate (?:verify|verification|file|does not|has expired)|server certificate|ssl error|ssl is not enabled|ssl connection is required|tls handshake|private key|root cert/', $message)) { return 'tls'; }
            if ($state==='3D000' || (($state==='HY000'||str_starts_with($state,'08')) && preg_match('/database .* does not exist/', $message))) { return 'configuration'; }
            if ($state==='57014') { return 'statement_timeout'; }
            if ($state==='55P03') { return 'lock_timeout'; }
            if (in_array($state,['40001','40P01'],true)) { return 'transaction_conflict'; }
            if ($state==='25006') { return 'read_only'; }
            if (in_array($state,['53300','53400'],true) || (($state==='HY000'||str_starts_with($state,'08')) && preg_match('/too many clients|remaining connection slots|too many connections/', $message))) { return 'capacity'; }
            if (in_array($state,['57P01','57P02','57P03','57P04','57P05','25P03'],true)) { return 'connection_lost'; }
            if (($state==='HY000'||str_starts_with($state,'08')) && preg_match('/timeout expired|connection timed out|connect timeout/', $message)) { return 'connect_timeout'; }
            if (($state==='HY000'||str_starts_with($state,'08')) && preg_match('/server closed the connection|connection (?:is )?(?:closed|lost|refused|reset)|ssl connection has been closed|connection to server was lost|connection not open|connection already closed|broken pipe|no connection to the server|could not (?:connect to server|receive data from server|send data to server|translate host name)|network is unreachable|terminating connection|ssl syscall error/', $message)) { return 'connection_lost'; }
            if (str_starts_with($state,'08')) { return 'connection_lost'; }
            if (str_starts_with($state,'53')) { return 'capacity'; }
            return 'query_error';
        }
        return 'unknown';
    }

    public static function reconnectable(\Throwable $error): bool
    {
        return in_array(self::category($error),['connection_lost','connect_timeout','capacity'],true);
    }

    public static function unavailable(\Throwable $error): bool
    {
        return self::reconnectable($error) || in_array(self::category($error),['statement_timeout','lock_timeout','transaction_conflict','read_only'],true);
    }
}
