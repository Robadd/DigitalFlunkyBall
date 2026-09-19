<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/hash.php 'passwort'\n");
    exit(1);
}
echo password_hash($argv[1], PASSWORD_DEFAULT), "\n";
