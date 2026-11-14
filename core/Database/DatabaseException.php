<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

/** Messages are deliberately safe for operators; PDO details stay internal. */
final class DatabaseException extends \RuntimeException
{
    public function __construct(string $message, int $code=0, ?\Throwable $previous=null, public readonly string $category='configuration')
    {
        parent::__construct($message,$code,$previous);
    }
}
