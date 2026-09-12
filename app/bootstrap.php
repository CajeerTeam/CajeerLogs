<?php
declare(strict_types=1);

// Совместимый bootstrap-слой для старой структуры CajeerLogs.
// Новый backend core находится в core/.
return require dirname(__DIR__) . '/core/bootstrap.php';
