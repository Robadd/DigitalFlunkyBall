<?php
require __DIR__ . '/lib.php';
require __DIR__ . '/PlayerActions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST erforderlich.', 405);
}
$in = read_json_body();

$g = Game::open(true);
tick($g);
$actions = new PlayerActions($g, $in, clean_token((string)arg($in, 't', '')));
$actions->run((string)arg($in, 'a', ''));

$token = $actions->token();
if ($token !== '') {
    db()->prepare('UPDATE players SET last_seen = ? WHERE token = ?')->execute(array($g->now, $token));
}
$g->commit();

$out = payload($g, $token);
if ($actions->newToken() !== null) {
    $out['token'] = $actions->newToken();
}
json_out($out);
