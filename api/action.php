<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST erforderlich.', 405);
}
$in = read_json_body();
$a = (string)arg($in, 'a', '');
$token = clean_token((string)arg($in, 't', ''));
$newToken = null;

$g = Game::open(true);
tick($g);
$s = &$g->s;
$me = $g->byToken($token);

function need_me($me)
{
    if (!$me) {
        fail('Du bist nicht angemeldet.', 403);
    }
}

// Guards against double taps and stale screens: the client sends the phase/turn it saw.
function expect_phase($g, $in, $allowed)
{
    $ph = (string)arg($in, 'ph', '');
    if ($ph !== $g->s['phase'] || !in_array($ph, $allowed, true) || (int)arg($in, 'ti', -1) !== $g->s['turn_index']) {
        fail('Veraltet.', 409);
    }
}

switch ($a) {
    case 'join':
        if ($s['phase'] !== 'lobby') {
            fail('Das Spiel läuft bereits.');
        }
        $rid = (int)arg($in, 'roster_id', 0);
        $st = db()->prepare('SELECT id FROM roster WHERE id = ?');
        $st->execute(array($rid));
        if (!$st->fetch()) {
            fail('Unbekannter Spieler.');
        }
        if (isset($g->players[$rid]) && (!$me || $me['id'] !== $rid)) {
            fail('Der Name ist schon vergeben.', 409);
        }
        if ($me) {
            db()->prepare('UPDATE players SET roster_id = ?, last_seen = ? WHERE token = ?')
                ->execute(array($rid, $g->now, $token));
        } else {
            $token = new_token();
            $newToken = $token;
            db()->prepare('INSERT INTO players (token, roster_id, ready, joined_at, last_seen) VALUES (?, ?, 0, ?, ?)')
                ->execute(array($token, $rid, $g->now, $g->now));
        }
        unready_all($g);
        break;

    case 'leave':
        need_me($me);
        if ($s['phase'] !== 'lobby') {
            fail('Während des Spiels nicht möglich.');
        }
        db()->prepare('DELETE FROM players WHERE token = ?')->execute(array($token));
        $g->loadPlayers();
        lobby_recheck($g);
        break;

    case 'ready':
        need_me($me);
        if ($s['phase'] !== 'lobby') {
            fail('Das Spiel läuft bereits.', 409);
        }
        db()->prepare('UPDATE players SET ready = ? WHERE token = ?')
            ->execute(array(arg($in, 'on') ? 1 : 0, $token));
        $g->loadPlayers();
        lobby_recheck($g);
        break;

    case 'throw':
        need_me($me);
        expect_phase($g, $in, array('turn'));
        if ($me['id'] !== $s['thrower']) {
            fail('Du bist nicht dran.', 403);
        }
        do_throw($g);
        break;

    case 'ok':
        need_me($me);
        expect_phase($g, $in, array('strafbier', 'miss', 'stop'));
        if ($me['id'] !== $s['thrower']) {
            fail('Nur der Werfer kann weiter.', 403);
        }
        advance($g);
        break;

    case 'claim':
        need_me($me);
        if ($s['phase'] !== 'countdown') {
            fail('Zu spät.', 409);
        }
        $role = arg($in, 'role');
        if ($role !== 'setter' && $role !== 'fetcher') {
            fail('Ungültige Aufgabe.');
        }
        $h = $s['hit'];
        if (team_of($s, $me['id']) !== $h['opp_team']) {
            fail('Nur das gegnerische Team.', 403);
        }
        if ($role === 'fetcher' && !empty($h['solo'])) {
            fail('Kein Ballholen nötig.', 409);
        }
        if ($h[$role] !== null) {
            fail('Schon vergeben.', 409);
        }
        if ($h['setter'] === $me['id'] || $h['fetcher'] === $me['id']) {
            fail('Du hast schon eine Aufgabe.', 409);
        }
        assign_role($g, $role, $me['id']);
        break;

    case 'finish':
        need_me($me);
        if ($s['phase'] === 'lobby' || $s['phase'] === 'over' || team_of($s, $me['id']) === null) {
            fail('Nicht möglich.', 409);
        }
        finish_player($g, $me['id']);
        break;

    case 'reset':
        need_me($me);
        reset_game($g);
        break;

    default:
        fail('Unbekannte Aktion.');
}

if ($token !== '') {
    db()->prepare('UPDATE players SET last_seen = ? WHERE token = ?')->execute(array($g->now, $token));
}
$g->commit();

$out = payload($g, $token);
if ($newToken !== null) {
    $out['token'] = $newToken;
}
json_out($out);
