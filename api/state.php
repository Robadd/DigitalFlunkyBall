<?php
require __DIR__ . '/lib.php';

$token = clean_token(isset($_GET['t']) ? (string)$_GET['t'] : '');
$v = isset($_GET['v']) ? (int)$_GET['v'] : -1;

touch_player($token);
$g = sync_game();
if ($g->v === $v) {
    json_out(array('u' => 1, 'v' => $v, 'now' => now()));
}
json_out(payload($g, $token));
