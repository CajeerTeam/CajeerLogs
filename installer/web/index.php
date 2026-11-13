<?php
declare(strict_types=1);
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "Web installation is disabled. Use php bin/cajeer install on the server.\n";
