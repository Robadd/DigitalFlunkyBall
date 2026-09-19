<?php
// Copy to config.php and fill in. Create the admin hash via SSH: php tools/hash.php 'dein-passwort'
return array(
    'db_dsn' => 'mysql:host=localhost;dbname=DATENBANK;charset=utf8mb4',
    'db_user' => 'BENUTZER',
    'db_pass' => 'PASSWORT',
    'admin_hash' => '',
);
