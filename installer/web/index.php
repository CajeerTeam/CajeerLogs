<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>CajeerLogs Installer</title></head>
<body>
  <h1>CajeerLogs Web Installer</h1>
  <p>Начальный web installer: проверка PHP, расширений, прав на storage и подключения к БД будет реализована в 0.2.x–0.3.x.</p>
  <ul>
    <li>PHP: <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></li>
    <li>Target: PHP 8.5 ready</li>
    <li>Primary server: Nginx</li>
  </ul>
</body>
</html>
