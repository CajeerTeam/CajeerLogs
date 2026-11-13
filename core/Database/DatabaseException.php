<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

/** Messages are deliberately safe for operators; PDO details stay internal. */
final class DatabaseException extends \RuntimeException {}
