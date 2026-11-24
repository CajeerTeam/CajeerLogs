<?php
declare(strict_types=1);
return [
    'enabled'=>env('SKIF4ER_ID_ENABLED',false),
    'client_id'=>env_raw('SKIF4ER_ID_CLIENT_ID'),
    'client_secret'=>env_raw('SKIF4ER_ID_CLIENT_SECRET'),
];
