<?php
// Shared by all endpoints. Kept compatible with PHP 5.6+ (local XAMPP) and 7.3 (server).
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('OFFLINE_AFTER', 30);
define('CLAIM_TIMEOUT', 5);
define('TOUCH_EVERY', 5);

$GLOBALS['__pdo'] = null;

/**
 * Settings from config.php (database access, admin password hash), read once per request.
 *
 * @return array
 */
function cfg()
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

/**
 * Shared database connection, opened on first use.
 * Errors throw PDOException; rows come back as associative arrays.
 *
 * @return PDO
 */
function db()
{
    if ($GLOBALS['__pdo'] === null) {
        $config = cfg();
        $GLOBALS['__pdo'] = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }
    return $GLOBALS['__pdo'];
}

/**
 * Rolls back the open transaction, if any, so a failed request leaves the database unchanged.
 * Safe to call when no connection was ever opened.
 *
 * @return void
 */
function rollback_open()
{
    $pdo = $GLOBALS['__pdo'];
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

/**
 * Sends $data as the JSON response and ends the request.
 *
 * @param mixed $data       Anything json_encode() accepts.
 * @param int   $httpStatus HTTP status code.
 * @return void Never returns.
 */
function json_out($data, $httpStatus = 200)
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ends the request with {"error": $message}, rolling back any open transaction first.
 *
 * @param string $message    Shown to the player, so it is in German.
 * @param int    $httpStatus 400 bad request, 403 not allowed, 409 the game has moved on
 *                           (the player's page then refreshes quietly instead of showing it).
 * @return void Never returns.
 */
function fail($message, $httpStatus = 400)
{
    rollback_open();
    json_out(array('error' => $message), $httpStatus);
}

// Unexpected errors: roll back, log the details, and send the client only a generic message.
set_exception_handler(function ($exception) {
    rollback_open();
    error_log('Teamspiel: ' . $exception);
    if (!headers_sent()) {
        json_out(array('error' => 'Serverfehler'), 500);
    }
    exit;
});

/**
 * @return float Current server time in Unix seconds, with microseconds.
 */
function now()
{
    return microtime(true);
}

/**
 * @return float Random number from 0 to 1, both inclusive.
 */
function rnd()
{
    return mt_rand() / mt_getrandmax();
}

/**
 * Rolls a percentage chance.
 *
 * @param int $percent 0 never succeeds, 100 always does.
 * @return bool
 */
function chance($percent)
{
    return mt_rand(1, 100) <= $percent;
}

/**
 * @return string Random 32-character hex token that identifies a joined player.
 */
function new_token()
{
    $bytes = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
    return bin2hex($bytes);
}

/**
 * Keeps malformed tokens out of queries and comparisons.
 *
 * @param string $token Token sent by the client.
 * @return string $token if it has the format new_token() produces, otherwise ''.
 */
function clean_token($token)
{
    return preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '';
}

/**
 * Reads one value from a request array. Unlike isset(), a sent null stays null.
 *
 * @param array  $array   Usually the decoded request body.
 * @param string $key
 * @param mixed  $default Returned when $key is missing.
 * @return mixed
 */
function arg($array, $key, $default = null)
{
    return array_key_exists($key, $array) ? $array[$key] : $default;
}

/**
 * Decodes the JSON request body. Ends the request with 400 unless it is a JSON object.
 *
 * @return array
 */
function read_json_body()
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fail('Ungültige Anfrage.');
    }
    return $body;
}

/**
 * Settings used until the admin saves others. Also fills in keys missing from saved settings,
 * so new settings work without a migration.
 *
 * @return array
 */
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

/**
 * Admin settings from the database, merged over default_settings().
 * Prefer Game::settings(), which loads them only once per request.
 *
 * @return array
 */
function load_settings()
{
    $row = db()->query('SELECT json FROM settings WHERE id = 1')->fetch();
    $saved = $row ? json_decode($row['json'], true) : null;
    return array_merge(default_settings(), is_array($saved) ? $saved : array());
}

/**
 * State of a fresh game waiting in the lobby. See Game::$s for what each key means.
 *
 * @return array
 */
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
    /**
     * Game state, stored as JSON in the single `game` row and sent to every client.
     * All times are Unix seconds (float, server clock).
     *
     * - phase:        'lobby' | 'turn' | 'strafbier' | 'miss' | 'countdown' | 'stop' | 'over'
     * - start_at:     lobby auto-start time; null until everyone is ready
     * - teams:        [1 => int[], 2 => int[]] roster ids still playing, in throwing order
     * - turn_index:   turn counter; even = team 1, odd = team 2, floor(n / 2) picks the player
     * - thrower:      roster id of the current thrower
     * - thrower_team: the thrower's team, kept even if the thrower finishes mid-turn
     * - hit:          current hit (setter/fetcher ids, start/end times, setter timeline,
     *                 solo flag, end = null until all jobs are assigned); null otherwise
     * - pending_hit:  throw result rolled in advance while a Strafbier is shown
     * - offline:      roster ids without a poll for OFFLINE_AFTER seconds
     * - finished:     [['id' => int, 'team' => int], ...] players whose beer is empty
     * - winner:       team that ran out of players first; set when phase is 'over'
     *
     * @var array
     */
    public $s;

    /** @var int State version; commit() bumps it on every change and clients poll with it. */
    public $v;

    /**
     * Joined players keyed by roster id, in join order. Each entry holds
     * token, id, name, speed (1-10), team (null in the lobby), ready, last_seen.
     *
     * @var array<int, array>
     */
    public $players;

    /** @var float Request time; every timing decision in one request uses this same value. */
    public $now;

    /** @var bool Set by mutations; commit() only writes and bumps the version when true. */
    public $changed = false;

    /** @var bool True while open(true) holds the row lock; commit() ends the transaction. */
    private $locked = false;

    /** @var array|null Admin settings, loaded on first use by settings(). */
    private $settings = null;

    /**
     * Loads the game and its joined players.
     *
     * With $lock the game row is read with SELECT ... FOR UPDATE inside a transaction, so
     * concurrent requests wait until commit(). Every change must go through a locked Game.
     * Without $lock it is a cheap read for polling. Keys missing from the stored state
     * fall back to empty_state(), so states saved by older versions still load.
     *
     * @param bool $lock Lock the game row until commit().
     * @return Game
     * @throws RuntimeException If the game row is missing (sql/schema.sql not imported).
     */
    public static function open($lock)
    {
        $game = new Game();
        $game->now = now();
        if ($lock) {
            db()->beginTransaction();
            $game->locked = true;
        }
        $row = db()->query('SELECT state, version FROM game WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''))->fetch();
        if (!$row) {
            throw new RuntimeException('game row missing - import sql/schema.sql');
        }
        $stored = json_decode($row['state'], true);
        $game->s = array_merge(empty_state(), is_array($stored) ? $stored : array());
        $game->v = (int)$row['version'];
        $game->loadPlayers();
        return $game;
    }

    /**
     * Reads the joined players with their roster name and speed into $players.
     * Call it again after changing the players table so later logic sees the change.
     *
     * @return void
     */
    public function loadPlayers()
    {
        $rows = db()->query(
            'SELECT p.token, p.roster_id, p.team, p.ready, p.last_seen, r.name, r.speed
             FROM players p JOIN roster r ON r.id = p.roster_id ORDER BY p.joined_at'
        )->fetchAll();
        $this->players = array();
        foreach ($rows as $row) {
            $rosterId = (int)$row['roster_id'];
            $this->players[$rosterId] = array(
                'token' => $row['token'],
                'id' => $rosterId,
                'name' => $row['name'],
                'speed' => (int)$row['speed'],
                'team' => $row['team'] === null ? null : (int)$row['team'],
                'ready' => (bool)$row['ready'],
                'last_seen' => (float)$row['last_seen'],
            );
        }
    }

    /**
     * Admin settings merged over default_settings(); read from the database once per Game.
     *
     * @return array
     */
    public function settings()
    {
        if ($this->settings === null) {
            $this->settings = load_settings();
        }
        return $this->settings;
    }

    /**
     * Finds the joined player a client token belongs to. The token is the player's only
     * credential, so it is compared in constant time.
     *
     * @param string $token Token from the client, already checked by clean_token(); '' for none.
     * @return array|null The player's entry from $players, or null if the token isn't joined.
     */
    public function byToken($token)
    {
        if ($token === '') {
            return null;
        }
        foreach ($this->players as $player) {
            if (hash_equals($player['token'], $token)) {
                return $player;
            }
        }
        return null;
    }

    /**
     * @param array $player An entry of $players.
     * @return bool True if the player hasn't polled for OFFLINE_AFTER seconds as of $now.
     */
    public function isOffline($player)
    {
        return $player['last_seen'] < $this->now - OFFLINE_AFTER;
    }

    /**
     * Saves the state and bumps the version if $changed, then ends the transaction if the
     * row is locked. Call it once when the request's changes are done; fail() rolls back
     * instead. Also safe on an unlocked Game, where it only writes if something changed.
     *
     * @return void
     */
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

/**
 * Loads the game for reading and runs any time-based step that is due: auto-start,
 * job assignment, Stopp, offline players. The host has no cron, so polls drive these steps.
 * Polls stay lock-free; the row is only locked when tick_due() finds something to do.
 *
 * @return Game
 */
function sync_game()
{
    $game = Game::open(false);
    if (tick_due($game)) {
        $game = Game::open(true);
        tick($game);
        $game->commit();
    }
    return $game;
}

/**
 * Records that a player is still connected. Writes at most every TOUCH_EVERY seconds per
 * player, so polling twice a second doesn't mean two writes a second.
 *
 * @param string $token The player's token; '' does nothing.
 * @return void
 */
function touch_player($token)
{
    if ($token === '') {
        return;
    }
    $now = now();
    db()->prepare('UPDATE players SET last_seen = ? WHERE token = ? AND last_seen < ?')
        ->execute(array($now, $token, $now - TOUCH_EVERY));
}

/**
 * @param Game $game
 * @return int[] Roster ids of joined players that count as offline now, sorted so the list
 *               can be compared directly with $game->s['offline'].
 */
function offline_ids($game)
{
    $ids = array();
    foreach ($game->players as $player) {
        if ($game->isOffline($player)) {
            $ids[] = $player['id'];
        }
    }
    sort($ids);
    return $ids;
}

/**
 * True if the game waits for the thrower (to throw or press "Weiter") but the thrower is
 * offline or already finished, so nobody could move the game on.
 *
 * @param array $state Game::$s
 * @return bool
 */
function thrower_stuck($state)
{
    return in_array($state['phase'], array('turn', 'strafbier', 'miss', 'stop'), true)
        && (in_array($state['thrower'], $state['offline'], true) || team_of($state, $state['thrower']) === null);
}

/**
 * Checks without a lock whether tick() has anything to do. Must cover every case tick()
 * handles; a case missing here would only happen on the next player action.
 *
 * @param Game $game An unlocked game.
 * @return bool
 */
function tick_due($game)
{
    $state = $game->s;
    if ($state['phase'] === 'lobby') {
        if ($state['start_at'] !== null && $game->now >= $state['start_at']) {
            return true;
        }
        foreach ($game->players as $player) {
            if ($game->isOffline($player)) {
                return true;
            }
        }
        return false;
    }
    if (offline_ids($game) !== $state['offline'] || thrower_stuck($state)) {
        return true;
    }
    if ($state['phase'] === 'countdown') {
        $hit = $state['hit'];
        if (roles_open($hit) && $game->now >= $hit['started'] + CLAIM_TIMEOUT) {
            return true;
        }
        if ($hit['end'] !== null && $game->now >= $hit['end']) {
            return true;
        }
    }
    return false;
}

/**
 * Applies the time-based steps.
 * Lobby: removes offline players and starts the game once start_at has passed.
 * In a game: updates the offline list, moves past a stuck thrower, assigns jobs nobody
 * claimed within CLAIM_TIMEOUT, and switches to Stopp when the countdown ends.
 *
 * @param Game $game A locked game.
 * @return void
 */
function tick($game)
{
    $state = &$game->s;
    if ($state['phase'] === 'lobby') {
        $goneTokens = array();
        foreach ($game->players as $player) {
            if ($game->isOffline($player)) {
                $goneTokens[] = $player['token'];
            }
        }
        if ($goneTokens) {
            $delete = db()->prepare('DELETE FROM players WHERE token = ?');
            foreach ($goneTokens as $token) {
                $delete->execute(array($token));
            }
            $game->loadPlayers();
            lobby_recheck($game);
        }
        if ($state['start_at'] !== null && $game->now >= $state['start_at']) {
            start_game($game);
        }
        return;
    }

    $offline = offline_ids($game);
    if ($offline !== $state['offline']) {
        $state['offline'] = $offline;
        $game->changed = true;
    }
    if (thrower_stuck($state)) {
        advance($game);
    }
    if ($state['phase'] === 'countdown') {
        $hit = $state['hit'];
        if (roles_open($hit) && $game->now >= $hit['started'] + CLAIM_TIMEOUT) {
            auto_assign($game);
        }
        if ($state['hit']['end'] !== null && $game->now >= $state['hit']['end']) {
            $state['phase'] = 'stop';
            $game->changed = true;
        }
    }
}

/**
 * Starts the lobby countdown once at least 2 players are joined and all are ready, and
 * cancels it otherwise. A countdown that is already running keeps its start time.
 * Call it after anything changes who is joined or ready.
 *
 * @param Game $game A locked game in the lobby.
 * @return void
 */
function lobby_recheck($game)
{
    $state = &$game->s;
    $allReady = count($game->players) >= 2;
    foreach ($game->players as $player) {
        if (!$player['ready']) {
            $allReady = false;
            break;
        }
    }
    if ($allReady && $state['start_at'] === null) {
        $settings = $game->settings();
        $state['start_at'] = $game->now + $settings['startDelay'];
    } elseif (!$allReady) {
        $state['start_at'] = null;
    }
    $game->changed = true;
}

/**
 * Sets every player to not ready and cancels the lobby countdown, e.g. when someone new
 * joins or the admin changes the settings.
 *
 * @param Game $game A locked game in the lobby.
 * @return void
 */
function unready_all($game)
{
    db()->exec('UPDATE players SET ready = 0');
    $game->loadPlayers();
    $game->s['start_at'] = null;
    $game->changed = true;
}

/**
 * Ends the current game and returns to the lobby. Everyone stays joined but not ready.
 *
 * @param Game $game A locked game.
 * @return void
 */
function reset_game($game)
{
    db()->exec('UPDATE players SET ready = 0, team = NULL');
    $game->loadPlayers();
    $game->s = empty_state();
    $game->changed = true;
}

/**
 * Shuffles the joined players into two teams (alternating, so the sizes differ by at most
 * one), saves each player's team and starts the first turn.
 *
 * @param Game $game A locked game in the lobby.
 * @return void
 */
function start_game($game)
{
    $rosterIds = array_keys($game->players);
    shuffle($rosterIds);
    $teams = array(1 => array(), 2 => array());
    foreach ($rosterIds as $position => $rosterId) {
        $teams[$position % 2 === 0 ? 1 : 2][] = (int)$rosterId;
    }
    $update = db()->prepare('UPDATE players SET team = ? WHERE roster_id = ?');
    foreach ($teams as $team => $members) {
        foreach ($members as $rosterId) {
            $update->execute(array($team, $rosterId));
        }
    }
    $game->loadPlayers();

    $state = &$game->s;
    $state['start_at'] = null;
    $state['teams'] = $teams;
    $state['turn_index'] = 0;
    $state['offline'] = array();
    begin_turn($game);
}

/**
 * @param array $state    Game::$s
 * @param int   $rosterId
 * @return int|null 1 or 2 while the player is in a team; null if not playing or finished.
 */
function team_of($state, $rosterId)
{
    foreach (array(1, 2) as $team) {
        if (in_array($rosterId, $state['teams'][$team], true)) {
            return $team;
        }
    }
    return null;
}

/**
 * Starts the turn for the current turn_index. Teams alternate, and within a team the
 * thrower rotates. Offline players are skipped while anyone in the team is online.
 *
 * @param Game $game A locked game.
 * @return void
 */
function begin_turn($game)
{
    $state = &$game->s;
    $team = $state['turn_index'] % 2 === 0 ? 1 : 2;
    $members = $state['teams'][$team];
    $memberCount = count($members);
    $round = (int)floor($state['turn_index'] / 2);
    $thrower = $members[$round % $memberCount];
    for ($offset = 0; $offset < $memberCount; $offset++) {
        $candidate = $members[($round + $offset) % $memberCount];
        if (!in_array($candidate, $state['offline'], true)) {
            $thrower = $candidate;
            break;
        }
    }
    $state['thrower'] = $thrower;
    $state['thrower_team'] = $team;
    $state['phase'] = 'turn';
    $state['hit'] = null;
    $state['pending_hit'] = null;
    $game->changed = true;
}

/**
 * @param Game $game A locked game.
 * @return void
 */
function next_turn($game)
{
    $game->s['turn_index']++;
    begin_turn($game);
}

/**
 * Moves past a screen that waits for the thrower: after a Strafbier the throw result rolled
 * earlier takes effect, otherwise the next turn starts. Does nothing in other phases.
 *
 * @param Game $game A locked game.
 * @return void
 */
function advance($game)
{
    switch ($game->s['phase']) {
        case 'strafbier':
            resolve_throw($game, $game->s['pending_hit']);
            break;
        case 'turn':
        case 'miss':
        case 'stop':
            next_turn($game);
            break;
    }
}

/**
 * Rolls the throw: hit or miss by hitChance. In Strafbier mode a beerChance roll may first
 * show the Strafbier screen; the throw result is then kept until the thrower moves on.
 *
 * @param Game $game A locked game in the 'turn' phase.
 * @return void
 */
function do_throw($game)
{
    $settings = $game->settings();
    $isHit = chance($settings['hitChance']);
    if ($settings['strafbier'] && chance($settings['beerChance'])) {
        $game->s['phase'] = 'strafbier';
        $game->s['pending_hit'] = $isHit;
        $game->changed = true;
        return;
    }
    resolve_throw($game, $isHit);
}

/**
 * A hit starts the drinking countdown; a miss shows the miss screen.
 *
 * @param Game $game  A locked game.
 * @param bool $isHit
 * @return void
 */
function resolve_throw($game, $isHit)
{
    $game->s['pending_hit'] = null;
    if ($isHit) {
        start_hit($game);
    } else {
        $game->s['phase'] = 'miss';
        $game->changed = true;
    }
}

/**
 * Starts the drinking countdown after a hit. The opponents' jobs are open for claiming;
 * a one-player team gets the setter job at once and has no ball to fetch.
 *
 * @param Game $game A locked game.
 * @return void
 */
function start_hit($game)
{
    $state = &$game->s;
    // thrower_team, not team_of(): the thrower may have finished during a Strafbier.
    $opponentTeam = $state['thrower_team'] === 1 ? 2 : 1;
    $state['phase'] = 'countdown';
    $state['hit'] = array(
        'id' => mt_rand(1, 2147483647),
        'started' => $game->now,
        'opp_team' => $opponentTeam,
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
        'solo' => count($state['teams'][$opponentTeam]) === 1,
    );
    $game->changed = true;
    if ($state['hit']['solo']) {
        assign_role($game, 'setter', $state['teams'][$opponentTeam][0]);
    }
}

/**
 * The player's beer is empty: they leave their team's rotation and are listed as finished.
 * The first team with nobody left wins and the game ends. If the player was the thrower,
 * the game moves on without them.
 *
 * @param Game $game     A locked, running game.
 * @param int  $rosterId A player who is still in a team.
 * @return void
 */
function finish_player($game, $rosterId)
{
    $state = &$game->s;
    $team = team_of($state, $rosterId);
    $state['teams'][$team] = array_values(array_diff($state['teams'][$team], array($rosterId)));
    $state['finished'][] = array('id' => $rosterId, 'team' => $team);
    $game->changed = true;
    if (!$state['teams'][$team]) {
        $state['phase'] = 'over';
        $state['winner'] = $team;
        return;
    }
    if (thrower_stuck($state)) {
        advance($game);
    }
}

/**
 * @param array $hit Game::$s['hit']
 * @return bool True while a needed job is unassigned: the setter always, the fetcher
 *              unless the running team has only one player.
 */
function roles_open($hit)
{
    return $hit['setter'] === null || ($hit['fetcher'] === null && empty($hit['solo']));
}

/**
 * Time factor for one job: speed 1 -> x1.4, 5 -> x1.0, 10 -> x0.5, times a random draw
 * within +/- variance. Drawn once per job, so a player has an overall fast or slow run.
 *
 * @param Game $game
 * @param int  $rosterId
 * @return float
 */
function role_factor($game, $rosterId)
{
    $settings = $game->settings();
    $speed = isset($game->players[$rosterId]) ? $game->players[$rosterId]['speed'] : 5;
    $variance = $settings['variance'] / 100;
    return (1.5 - 0.1 * $speed) * (1 + $variance * (2 * rnd() - 1));
}

/**
 * Precomputes the setter's stages as absolute end times, so every phone can show the current
 * stage without asking the server. With $tipChance the bottle tips over at most once, on the
 * way back: the setter runs back to it, sets it up again and runs back again.
 *
 * @param float $start          When the setter starts running.
 * @param float $runToSeconds   Time to the bottle, already scaled by role_factor().
 * @param float $setUpSeconds   Time to set the bottle up, already scaled.
 * @param float $runBackSeconds Time back behind the line, already scaled.
 * @param int   $tipChance      Percent chance of one tip-over.
 * @return array [stages, tipped]: stages is a list of ['stage' => 'hin'|'auf'|'zurueck'|
 *               'umgefallen', 'end' => float]; tipped is true if the bottle tipped over.
 */
function setter_timeline($start, $runToSeconds, $setUpSeconds, $runBackSeconds, $tipChance)
{
    $atBottle = $start + $runToSeconds;
    $standing = $atBottle + $setUpSeconds;
    $stages = array(
        array('stage' => 'hin', 'end' => $atBottle),
        array('stage' => 'auf', 'end' => $standing),
    );
    $tipped = chance($tipChance);
    if ($tipped) {
        $covered = (0.2 + 0.7 * rnd()) * $runBackSeconds;
        $tipAt = $standing + $covered;
        $backAtBottle = $tipAt + $covered;
        $standingAgain = $backAtBottle + $setUpSeconds;
        $stages[] = array('stage' => 'zurueck', 'end' => $tipAt);
        $stages[] = array('stage' => 'umgefallen', 'end' => $backAtBottle);
        $stages[] = array('stage' => 'auf', 'end' => $standingAgain);
        $stages[] = array('stage' => 'zurueck', 'end' => $standingAgain + $runBackSeconds);
    } else {
        $stages[] = array('stage' => 'zurueck', 'end' => $standing + $runBackSeconds);
    }
    return array($stages, $tipped);
}

/**
 * Gives a player a job and works out when they are done. Someone doing both jobs (only when
 * nobody else is available) does them one after the other. Once every needed job is
 * assigned, the countdown end is known.
 *
 * @param Game   $game     A locked game in the 'countdown' phase.
 * @param string $role     'setter' (Aufstellen) or 'fetcher' (Ball holen).
 * @param int    $rosterId
 * @return void
 */
function assign_role($game, $role, $rosterId)
{
    $hit = &$game->s['hit'];
    $otherRole = $role === 'setter' ? 'fetcher' : 'setter';
    $start = $game->now;
    if ($hit[$otherRole] === $rosterId && $hit[$otherRole . '_end'] !== null) {
        $start = max($start, $hit[$otherRole . '_end']);
    }
    $settings = $game->settings();
    $factor = role_factor($game, $rosterId);
    if ($role === 'setter') {
        $result = setter_timeline(
            $start,
            $factor * $settings['tRunTo'],
            $factor * $settings['tSetUp'],
            $factor * $settings['tRunBack'],
            $settings['tipChance']
        );
        $hit['setter'] = $rosterId;
        $hit['setter_start'] = $start;
        $hit['timeline'] = $result[0];
        $hit['tipped'] = $result[1];
        $hit['setter_end'] = $result[0][count($result[0]) - 1]['end'];
    } else {
        $hit['fetcher'] = $rosterId;
        $hit['fetcher_start'] = $start;
        $hit['fetcher_end'] = $start + $factor * $settings['tFetch'];
    }
    if (!roles_open($hit)) {
        $hit['end'] = $hit['fetcher'] === null ? $hit['setter_end'] : max($hit['setter_end'], $hit['fetcher_end']);
    }
    $game->changed = true;
}

/**
 * Assigns the jobs nobody claimed within CLAIM_TIMEOUT, picking at random among online
 * opponents without a job. Falls back to an online player who already has the other job,
 * then to offline players, so the countdown can always end.
 *
 * @param Game $game A locked game in the 'countdown' phase.
 * @return void
 */
function auto_assign($game)
{
    $state = $game->s;
    $opponents = $state['teams'][$state['hit']['opp_team']];
    $offline = $state['offline'];
    foreach (array('setter', 'fetcher') as $role) {
        $hit = $game->s['hit'];
        if ($hit[$role] !== null || ($role === 'fetcher' && !empty($hit['solo']))) {
            continue;
        }
        $busy = $role === 'setter' ? $hit['fetcher'] : $hit['setter'];
        $online = array();
        $free = array();
        foreach ($opponents as $rosterId) {
            if (in_array($rosterId, $offline, true)) {
                continue;
            }
            $online[] = $rosterId;
            if ($rosterId !== $busy) {
                $free[] = $rosterId;
            }
        }
        $pool = $free ? $free : ($online ? $online : $opponents);
        assign_role($game, $role, $pool[mt_rand(0, count($pool) - 1)]);
    }
}

/**
 * Everything a phone needs to draw its screen: state, players, roster, a few settings,
 * the server time for clock sync, and which player the token belongs to ('me').
 * The throw result rolled before a Strafbier is left out, so it can't be read early.
 *
 * @param Game   $game
 * @param string $token The requesting player's token; '' for none.
 * @return array
 */
function payload($game, $token)
{
    $me = $game->byToken($token);
    $players = array();
    foreach ($game->players as $player) {
        $players[] = array(
            'id' => $player['id'],
            'name' => $player['name'],
            'team' => $player['team'],
            'ready' => $player['ready'],
            'online' => !$game->isOffline($player),
        );
    }
    $roster = array();
    foreach (db()->query('SELECT id, name FROM roster ORDER BY name')->fetchAll() as $row) {
        $roster[] = array('id' => (int)$row['id'], 'name' => $row['name']);
    }
    $settings = $game->settings();
    $state = $game->s;
    unset($state['pending_hit']);
    return array(
        'v' => $game->v,
        'now' => now(),
        'me' => $me ? $me['id'] : null,
        's' => $state,
        'players' => $players,
        'roster' => $roster,
        'settings' => array(
            'team1Name' => $settings['team1Name'],
            'team2Name' => $settings['team2Name'],
            'transport' => $settings['transport'],
            'claimTimeout' => CLAIM_TIMEOUT,
        ),
    );
}
