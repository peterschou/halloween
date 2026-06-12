<?php
// Security: Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

return [
    'host'   => 'db',
    'name'   => 'scarepath',
    'user'   => 'root',
    'pass'   => 'secret',
    'prefix' => 'sp_',
];