<?php
require __DIR__ . '/lib.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
// Scope the cookie to the app folder (e.g. /flunkyball/), not the whole domain.
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/';
session_name('flunkyball_admin');
session_set_cookie_params(0, $basePath, '', $https, true);
session_start();

// Requiring a JSON content type blocks cross-site form posts (CSRF) without a token.
$ctype = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos($ctype, 'application/json') !== 0) {
    fail('Ungültige Anfrage.');
}
$in = read_json_body();
$a = (string)arg($in, 'a', '');

function clamp_int($v, $min, $max)
{
    return max($min, min($max, (int)$v));
}

function clamp_seconds($v, $min, $max)
{
    return round(max($min, min($max, (float)$v)), 1);
}

function clean_name($v, $max, $fallback)
{
    $v = trim((string)$v);
    $v = function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    return $v === '' ? $fallback : $v;
}

function admin_data()
{
    $g = sync_game();
    $roster = array();
    foreach (db()->query('SELECT id, name, speed FROM roster ORDER BY name')->fetchAll() as $r) {
        $roster[] = array('id' => (int)$r['id'], 'name' => $r['name'], 'speed' => (int)$r['speed']);
    }
    $players = array();
    foreach ($g->players as $p) {
        $players[] = array('name' => $p['name'], 'team' => $p['team'], 'ready' => $p['ready'], 'online' => !$g->isOffline($p));
    }
    return array('settings' => $g->settings(), 'roster' => $roster, 'players' => $players, 'phase' => $g->s['phase']);
}

function save_settings($in)
{
    if (!is_array($in)) {
        fail('Ungültige Einstellungen.');
    }
    $d = default_settings();
    $cur = load_settings();
    $get = function ($k) use ($in, $cur) {
        return array_key_exists($k, $in) ? $in[$k] : $cur[$k];
    };
    $s = array(
        'team1Name' => clean_name($get('team1Name'), 30, $d['team1Name']),
        'team2Name' => clean_name($get('team2Name'), 30, $d['team2Name']),
        'strafbier' => (bool)$get('strafbier'),
        'tRunTo' => clamp_seconds($get('tRunTo'), 0.5, 60),
        'tSetUp' => clamp_seconds($get('tSetUp'), 0.5, 60),
        'tRunBack' => clamp_seconds($get('tRunBack'), 0.5, 60),
        'tFetch' => clamp_seconds($get('tFetch'), 0.5, 60),
        'variance' => clamp_int($get('variance'), 0, 50),
        'hitChance' => clamp_int($get('hitChance'), 0, 100),
        'beerChance' => clamp_int($get('beerChance'), 0, 100),
        'tipChance' => clamp_int($get('tipChance'), 0, 100),
        'startDelay' => clamp_int($get('startDelay'), 0, 120),
        'transport' => $get('transport') === 'sse' ? 'sse' : 'poll',
    );
    $g = Game::open(true);
    db()->prepare('INSERT INTO settings (id, json) VALUES (1, ?) ON DUPLICATE KEY UPDATE json = VALUES(json)')
        ->execute(array(json_encode($s, JSON_UNESCAPED_UNICODE)));
    if ($g->s['phase'] === 'lobby') {
        unready_all($g);
    }
    $g->changed = true;
    $g->commit();
}

function roster_write($sql, $params)
{
    $g = Game::open(true);
    try {
        db()->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail('Den Namen gibt es schon.', 409);
        }
        throw $e;
    }
    $g->changed = true;
    $g->commit();
}

if ($a === 'login') {
    $c = cfg();
    $pw = (string)arg($in, 'password', '');
    if (empty($c['admin_hash']) || !password_verify($pw, $c['admin_hash'])) {
        sleep(1);
        fail('Falsches Passwort.', 403);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    session_write_close();
    json_out(admin_data());
}

if (empty($_SESSION['admin'])) {
    fail('Nicht angemeldet.', 401);
}

if ($a === 'logout') {
    $_SESSION = array();
    session_destroy();
    json_out(array('ok' => true));
}
session_write_close();

switch ($a) {
    case 'get':
        break;

    case 'saveSettings':
        save_settings(arg($in, 'settings'));
        break;

    case 'rosterAdd':
        $name = clean_name(arg($in, 'name', ''), 40, '');
        if ($name === '') {
            fail('Name fehlt.');
        }
        roster_write('INSERT INTO roster (name, speed) VALUES (?, ?)', array($name, clamp_int(arg($in, 'speed', 5), 1, 10)));
        break;

    case 'rosterUpdate':
        $name = clean_name(arg($in, 'name', ''), 40, '');
        if ($name === '') {
            fail('Name fehlt.');
        }
        roster_write('UPDATE roster SET name = ?, speed = ? WHERE id = ?', array($name, clamp_int(arg($in, 'speed', 5), 1, 10), (int)arg($in, 'id', 0)));
        break;

    case 'rosterDelete':
        $id = (int)arg($in, 'id', 0);
        $g = Game::open(true);
        if ($g->s['phase'] !== 'lobby' && isset($g->players[$id])) {
            fail('Der Spieler ist im laufenden Spiel.', 409);
        }
        db()->prepare('DELETE FROM roster WHERE id = ?')->execute(array($id));
        $g->loadPlayers();
        if ($g->s['phase'] === 'lobby') {
            lobby_recheck($g);
        }
        $g->changed = true;
        $g->commit();
        break;

    case 'reset':
        $g = Game::open(true);
        reset_game($g);
        $g->commit();
        break;

    case 'kickAll':
        $g = Game::open(true);
        db()->exec('DELETE FROM players');
        $g->loadPlayers();
        $g->s = empty_state();
        $g->changed = true;
        $g->commit();
        break;

    default:
        fail('Unbekannte Aktion.');
}

json_out(admin_data());
