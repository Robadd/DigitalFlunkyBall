<?php
// Shared by all endpoints. Kept compatible with PHP 5.6+ (local XAMPP) and 7.3 (server).
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('OFFLINE_AFTER', 30);
define('CLAIM_TIMEOUT', 5);
define('TOUCH_EVERY', 5);

$GLOBALS['__pdo'] = null;

function cfg()
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

function db()
{
    if ($GLOBALS['__pdo'] === null) {
        $c = cfg();
        $GLOBALS['__pdo'] = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }
    return $GLOBALS['__pdo'];
}

function rollback_open()
{
    $pdo = $GLOBALS['__pdo'];
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail($msg, $code = 400)
{
    rollback_open();
    json_out(array('error' => $msg), $code);
}

set_exception_handler(function ($e) {
    rollback_open();
    error_log('Teamspiel: ' . $e);
    if (!headers_sent()) {
        json_out(array('error' => 'Serverfehler'), 500);
    }
    exit;
});

function now()
{
    return microtime(true);
}

function rnd()
{
    return mt_rand() / mt_getrandmax();
}

function chance($percent)
{
    return mt_rand(1, 100) <= $percent;
}

function new_token()
{
    $bytes = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
    return bin2hex($bytes);
}

function clean_token($t)
{
    return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : '';
}

function arg($a, $k, $default = null)
{
    return array_key_exists($k, $a) ? $a[$k] : $default;
}

function read_json_body()
{
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        fail('Ungültige Anfrage.');
    }
    return $in;
}

function default_settings()
{
    return array(
        'team1Name' => 'Team 1',
        'team2Name' => 'Team 2',
        'strafbier' => false,
        // Standard seconds for speed 5; speed scales them, variance adds +/- randomness.
        // Bottle and ball are both ~5 m from the line, run at ~2.5 m/s from a standing start.
        'tRunTo' => 2,
        'tSetUp' => 1.5,
        'tRunBack' => 2,
        'tFetch' => 5,
        'variance' => 20,
        'hitChance' => 25,
        'beerChance' => 5,
        'tipChance' => 10,
        'startDelay' => 10,
        'transport' => 'poll',
    );
}

function load_settings()
{
    $row = db()->query('SELECT json FROM settings WHERE id = 1')->fetch();
    $s = $row ? json_decode($row['json'], true) : null;
    return array_merge(default_settings(), is_array($s) ? $s : array());
}

function empty_state()
{
    return array(
        'phase' => 'lobby',
        'start_at' => null,
        'teams' => array(1 => array(), 2 => array()),
        'turn_index' => 0,
        'thrower' => null,
        'thrower_team' => null,
        'hit' => null,
        'pending_hit' => null,
        'offline' => array(),
        'finished' => array(),
        'winner' => null,
    );
}

class Game
{
    public $s;
    public $v;
    public $players;
    public $now;
    public $changed = false;
    private $locked = false;
    private $settings = null;

    public static function open($lock)
    {
        $g = new Game();
        $g->now = now();
        if ($lock) {
            db()->beginTransaction();
            $g->locked = true;
        }
        $row = db()->query('SELECT state, version FROM game WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''))->fetch();
        if (!$row) {
            throw new RuntimeException('game row missing - import sql/schema.sql');
        }
        $st = json_decode($row['state'], true);
        $g->s = array_merge(empty_state(), is_array($st) ? $st : array());
        $g->v = (int)$row['version'];
        $g->loadPlayers();
        return $g;
    }

    public function loadPlayers()
    {
        $rows = db()->query(
            'SELECT p.token, p.roster_id, p.team, p.ready, p.last_seen, r.name, r.speed
             FROM players p JOIN roster r ON r.id = p.roster_id ORDER BY p.joined_at'
        )->fetchAll();
        $this->players = array();
        foreach ($rows as $r) {
            $id = (int)$r['roster_id'];
            $this->players[$id] = array(
                'token' => $r['token'],
                'id' => $id,
                'name' => $r['name'],
                'speed' => (int)$r['speed'],
                'team' => $r['team'] === null ? null : (int)$r['team'],
                'ready' => (bool)$r['ready'],
                'last_seen' => (float)$r['last_seen'],
            );
        }
    }

    public function settings()
    {
        if ($this->settings === null) {
            $this->settings = load_settings();
        }
        return $this->settings;
    }

    public function byToken($token)
    {
        if ($token === '') {
            return null;
        }
        foreach ($this->players as $p) {
            if (hash_equals($p['token'], $token)) {
                return $p;
            }
        }
        return null;
    }

    public function isOffline($p)
    {
        return $p['last_seen'] < $this->now - OFFLINE_AFTER;
    }

    public function commit()
    {
        if ($this->changed) {
            db()->prepare('UPDATE game SET state = ?, version = version + 1 WHERE id = 1')
                ->execute(array(json_encode($this->s, JSON_UNESCAPED_UNICODE)));
            $this->v++;
            $this->changed = false;
        }
        if ($this->locked) {
            db()->commit();
            $this->locked = false;
        }
    }
}

// Reads without a lock and only locks when a time-based transition is due.
function sync_game()
{
    $g = Game::open(false);
    if (tick_due($g)) {
        $g = Game::open(true);
        tick($g);
        $g->commit();
    }
    return $g;
}

function touch_player($token)
{
    if ($token === '') {
        return;
    }
    $now = now();
    db()->prepare('UPDATE players SET last_seen = ? WHERE token = ? AND last_seen < ?')
        ->execute(array($now, $token, $now - TOUCH_EVERY));
}

function offline_ids($g)
{
    $ids = array();
    foreach ($g->players as $p) {
        if ($g->isOffline($p)) {
            $ids[] = $p['id'];
        }
    }
    sort($ids);
    return $ids;
}

// The thrower has to press "Weiter"; if they are offline or already finished, nobody can.
function thrower_stuck($s)
{
    return in_array($s['phase'], array('turn', 'strafbier', 'miss', 'stop'), true)
        && (in_array($s['thrower'], $s['offline'], true) || team_of($s, $s['thrower']) === null);
}

function tick_due($g)
{
    $s = $g->s;
    if ($s['phase'] === 'lobby') {
        if ($s['start_at'] !== null && $g->now >= $s['start_at']) {
            return true;
        }
        foreach ($g->players as $p) {
            if ($g->isOffline($p)) {
                return true;
            }
        }
        return false;
    }
    if (offline_ids($g) !== $s['offline'] || thrower_stuck($s)) {
        return true;
    }
    if ($s['phase'] === 'countdown') {
        $h = $s['hit'];
        if (roles_open($h) && $g->now >= $h['started'] + CLAIM_TIMEOUT) {
            return true;
        }
        if ($h['end'] !== null && $g->now >= $h['end']) {
            return true;
        }
    }
    return false;
}

// Must run inside a locked Game.
function tick($g)
{
    $s = &$g->s;
    if ($s['phase'] === 'lobby') {
        $gone = array();
        foreach ($g->players as $p) {
            if ($g->isOffline($p)) {
                $gone[] = $p['token'];
            }
        }
        if ($gone) {
            $del = db()->prepare('DELETE FROM players WHERE token = ?');
            foreach ($gone as $t) {
                $del->execute(array($t));
            }
            $g->loadPlayers();
            lobby_recheck($g);
        }
        if ($s['start_at'] !== null && $g->now >= $s['start_at']) {
            start_game($g);
        }
        return;
    }

    $off = offline_ids($g);
    if ($off !== $s['offline']) {
        $s['offline'] = $off;
        $g->changed = true;
    }
    if (thrower_stuck($s)) {
        advance($g);
    }
    if ($s['phase'] === 'countdown') {
        $h = $s['hit'];
        if (roles_open($h) && $g->now >= $h['started'] + CLAIM_TIMEOUT) {
            auto_assign($g);
        }
        if ($s['hit']['end'] !== null && $g->now >= $s['hit']['end']) {
            $s['phase'] = 'stop';
            $g->changed = true;
        }
    }
}

function lobby_recheck($g)
{
    $s = &$g->s;
    $all = count($g->players) >= 2;
    foreach ($g->players as $p) {
        if (!$p['ready']) {
            $all = false;
            break;
        }
    }
    if ($all && $s['start_at'] === null) {
        $set = $g->settings();
        $s['start_at'] = $g->now + $set['startDelay'];
    } elseif (!$all) {
        $s['start_at'] = null;
    }
    $g->changed = true;
}

function unready_all($g)
{
    db()->exec('UPDATE players SET ready = 0');
    $g->loadPlayers();
    $g->s['start_at'] = null;
    $g->changed = true;
}

function reset_game($g)
{
    db()->exec('UPDATE players SET ready = 0, team = NULL');
    $g->loadPlayers();
    $g->s = empty_state();
    $g->changed = true;
}

function start_game($g)
{
    $ids = array_keys($g->players);
    shuffle($ids);
    $teams = array(1 => array(), 2 => array());
    foreach ($ids as $i => $id) {
        $teams[$i % 2 === 0 ? 1 : 2][] = (int)$id;
    }
    $upd = db()->prepare('UPDATE players SET team = ? WHERE roster_id = ?');
    foreach ($teams as $t => $list) {
        foreach ($list as $id) {
            $upd->execute(array($t, $id));
        }
    }
    $g->loadPlayers();

    $s = &$g->s;
    $s['start_at'] = null;
    $s['teams'] = $teams;
    $s['turn_index'] = 0;
    $s['offline'] = array();
    begin_turn($g);
}

function team_of($s, $rid)
{
    foreach (array(1, 2) as $t) {
        if (in_array($rid, $s['teams'][$t], true)) {
            return $t;
        }
    }
    return null;
}

// Teams alternate; within a team the thrower rotates. Offline players are skipped.
function begin_turn($g)
{
    $s = &$g->s;
    $team = $s['turn_index'] % 2 === 0 ? 1 : 2;
    $list = $s['teams'][$team];
    $n = count($list);
    $k = (int)floor($s['turn_index'] / 2);
    $pick = $list[$k % $n];
    for ($i = 0; $i < $n; $i++) {
        $id = $list[($k + $i) % $n];
        if (!in_array($id, $s['offline'], true)) {
            $pick = $id;
            break;
        }
    }
    $s['thrower'] = $pick;
    $s['thrower_team'] = $team;
    $s['phase'] = 'turn';
    $s['hit'] = null;
    $s['pending_hit'] = null;
    $g->changed = true;
}

function next_turn($g)
{
    $g->s['turn_index']++;
    begin_turn($g);
}

function advance($g)
{
    switch ($g->s['phase']) {
        case 'strafbier':
            resolve_throw($g, $g->s['pending_hit']);
            break;
        case 'turn':
        case 'miss':
        case 'stop':
            next_turn($g);
            break;
    }
}

function do_throw($g)
{
    $set = $g->settings();
    $hit = chance($set['hitChance']);
    if ($set['strafbier'] && chance($set['beerChance'])) {
        $g->s['phase'] = 'strafbier';
        $g->s['pending_hit'] = $hit;
        $g->changed = true;
        return;
    }
    resolve_throw($g, $hit);
}

function resolve_throw($g, $hit)
{
    $g->s['pending_hit'] = null;
    if ($hit) {
        start_hit($g);
    } else {
        $g->s['phase'] = 'miss';
        $g->changed = true;
    }
}

function start_hit($g)
{
    $s = &$g->s;
    // thrower_team, not team_of(): the thrower may have finished during a Strafbier.
    $opp = $s['thrower_team'] === 1 ? 2 : 1;
    $s['phase'] = 'countdown';
    $s['hit'] = array(
        'id' => mt_rand(1, 2147483647),
        'started' => $g->now,
        'opp_team' => $opp,
        'setter' => null,
        'setter_start' => null,
        'timeline' => null,
        'setter_end' => null,
        'tipped' => false,
        'fetcher' => null,
        'fetcher_start' => null,
        'fetcher_end' => null,
        'end' => null,
        // A one-player team only has to put the bottle back up; nobody fetches the ball.
        'solo' => count($s['teams'][$opp]) === 1,
    );
    $g->changed = true;
    if ($s['hit']['solo']) {
        assign_role($g, 'setter', $s['teams'][$opp][0]);
    }
}

// A player whose beer is empty leaves the rotation; the first team with nobody left wins.
function finish_player($g, $rid)
{
    $s = &$g->s;
    $team = team_of($s, $rid);
    $s['teams'][$team] = array_values(array_diff($s['teams'][$team], array($rid)));
    $s['finished'][] = array('id' => $rid, 'team' => $team);
    $g->changed = true;
    if (!$s['teams'][$team]) {
        $s['phase'] = 'over';
        $s['winner'] = $team;
        return;
    }
    if (thrower_stuck($s)) {
        advance($g);
    }
}

function roles_open($h)
{
    return $h['setter'] === null || ($h['fetcher'] === null && empty($h['solo']));
}

// One draw per role: speed 1 -> x1.4, 5 -> x1.0, 10 -> x0.5, times the random variance.
function role_factor($g, $rid)
{
    $set = $g->settings();
    $speed = isset($g->players[$rid]) ? $g->players[$rid]['speed'] : 5;
    $v = $set['variance'] / 100;
    return (1.5 - 0.1 * $speed) * (1 + $v * (2 * rnd() - 1));
}

// At most one tip-over, somewhere on the way back.
function setter_timeline($start, $hin, $auf, $zur, $tipChance)
{
    $a = $start + $hin;
    $b = $a + $auf;
    $tl = array(
        array('stage' => 'hin', 'end' => $a),
        array('stage' => 'auf', 'end' => $b),
    );
    $tipped = chance($tipChance);
    if ($tipped) {
        $covered = (0.2 + 0.7 * rnd()) * $zur;
        $tipAt = $b + $covered;
        $backAt = $tipAt + $covered;
        $upAt = $backAt + $auf;
        $tl[] = array('stage' => 'zurueck', 'end' => $tipAt);
        $tl[] = array('stage' => 'umgefallen', 'end' => $backAt);
        $tl[] = array('stage' => 'auf', 'end' => $upAt);
        $tl[] = array('stage' => 'zurueck', 'end' => $upAt + $zur);
    } else {
        $tl[] = array('stage' => 'zurueck', 'end' => $b + $zur);
    }
    return array($tl, $tipped);
}

function assign_role($g, $role, $rid)
{
    $h = &$g->s['hit'];
    $other = $role === 'setter' ? 'fetcher' : 'setter';
    $start = $g->now;
    if ($h[$other] === $rid && $h[$other . '_end'] !== null) {
        $start = max($start, $h[$other . '_end']);
    }
    $set = $g->settings();
    $f = role_factor($g, $rid);
    if ($role === 'setter') {
        $res = setter_timeline($start, $f * $set['tRunTo'], $f * $set['tSetUp'], $f * $set['tRunBack'], $set['tipChance']);
        $h['setter'] = $rid;
        $h['setter_start'] = $start;
        $h['timeline'] = $res[0];
        $h['tipped'] = $res[1];
        $h['setter_end'] = $res[0][count($res[0]) - 1]['end'];
    } else {
        $h['fetcher'] = $rid;
        $h['fetcher_start'] = $start;
        $h['fetcher_end'] = $start + $f * $set['tFetch'];
    }
    if (!roles_open($h)) {
        $h['end'] = $h['fetcher'] === null ? $h['setter_end'] : max($h['setter_end'], $h['fetcher_end']);
    }
    $g->changed = true;
}

function auto_assign($g)
{
    $s = $g->s;
    $opps = $s['teams'][$s['hit']['opp_team']];
    $offline = $s['offline'];
    foreach (array('setter', 'fetcher') as $role) {
        $h = $g->s['hit'];
        if ($h[$role] !== null || ($role === 'fetcher' && !empty($h['solo']))) {
            continue;
        }
        $taken = $role === 'setter' ? $h['fetcher'] : $h['setter'];
        $online = array();
        $free = array();
        foreach ($opps as $id) {
            if (in_array($id, $offline, true)) {
                continue;
            }
            $online[] = $id;
            if ($id !== $taken) {
                $free[] = $id;
            }
        }
        $pool = $free ? $free : ($online ? $online : $opps);
        assign_role($g, $role, $pool[mt_rand(0, count($pool) - 1)]);
    }
}

function payload($g, $token)
{
    $me = $g->byToken($token);
    $players = array();
    foreach ($g->players as $p) {
        $players[] = array(
            'id' => $p['id'],
            'name' => $p['name'],
            'team' => $p['team'],
            'ready' => $p['ready'],
            'online' => !$g->isOffline($p),
        );
    }
    $roster = array();
    foreach (db()->query('SELECT id, name FROM roster ORDER BY name')->fetchAll() as $r) {
        $roster[] = array('id' => (int)$r['id'], 'name' => $r['name']);
    }
    $set = $g->settings();
    $s = $g->s;
    unset($s['pending_hit']);
    return array(
        'v' => $g->v,
        'now' => now(),
        'me' => $me ? $me['id'] : null,
        's' => $s,
        'players' => $players,
        'roster' => $roster,
        'settings' => array(
            'team1Name' => $set['team1Name'],
            'team2Name' => $set['team2Name'],
            'transport' => $set['transport'],
            'claimTimeout' => CLAIM_TIMEOUT,
        ),
    );
}
