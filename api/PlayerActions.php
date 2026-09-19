<?php

/**
 * The actions a player's phone can send to action.php, one method per action.
 *
 * Each method validates the request against the current state and either changes the
 * locked Game or stops the request with fail(). Saving and responding stay in action.php.
 */
class PlayerActions
{
    /** Allowed action names mapped to their methods; nothing outside this list can be called. */
    private static $methods = array(
        'join' => 'join',
        'leave' => 'leave',
        'ready' => 'ready',
        'throw' => 'throwBall',
        'ok' => 'ok',
        'claim' => 'claim',
        'finish' => 'finish',
        'reset' => 'reset',
    );

    /** @var Game The locked game, already ticked. */
    private $g;

    /** @var array Decoded request body. */
    private $in;

    /** @var string The sender's token; '' if none. join() replaces it with a new one. */
    private $token;

    /** @var array|null The sender's entry from Game::$players, or null if not joined. */
    private $me;

    /** @var string|null Token created by join(), to send back to the client. */
    private $newToken = null;

    /**
     * @param Game   $g     The locked game, after tick().
     * @param array  $in    Decoded request body.
     * @param string $token The sender's token, already checked by clean_token(); '' for none.
     */
    public function __construct(Game $g, array $in, $token)
    {
        $this->g = $g;
        $this->in = $in;
        $this->token = $token;
        $this->me = $g->byToken($token);
    }

    /**
     * Runs the named action. Unknown names stop the request with 400.
     *
     * @param string $action Value of 'a' in the request body.
     * @return void
     */
    public function run($action)
    {
        if (!isset(self::$methods[$action])) {
            fail('Unbekannte Aktion.');
        }
        $this->{self::$methods[$action]}();
    }

    /** @return string The sender's token after the action; '' if they have none. */
    public function token()
    {
        return $this->token;
    }

    /** @return string|null The token join() created, or null if no new token was issued. */
    public function newToken()
    {
        return $this->newToken;
    }

    /**
     * Joins the lobby under a roster name, or switches to another name. Issues a token to
     * new players. Everyone becomes not ready, so a new player can't miss the start.
     */
    private function join()
    {
        $g = $this->g;
        if ($g->s['phase'] !== 'lobby') {
            fail('Das Spiel läuft bereits.');
        }
        $rid = (int)arg($this->in, 'roster_id', 0);
        $st = db()->prepare('SELECT id FROM roster WHERE id = ?');
        $st->execute(array($rid));
        if (!$st->fetch()) {
            fail('Unbekannter Spieler.');
        }
        if (isset($g->players[$rid]) && (!$this->me || $this->me['id'] !== $rid)) {
            fail('Der Name ist schon vergeben.', 409);
        }
        if ($this->me) {
            db()->prepare('UPDATE players SET roster_id = ?, last_seen = ? WHERE token = ?')
                ->execute(array($rid, $g->now, $this->token));
        } else {
            $this->token = new_token();
            $this->newToken = $this->token;
            db()->prepare('INSERT INTO players (token, roster_id, ready, joined_at, last_seen) VALUES (?, ?, 0, ?, ?)')
                ->execute(array($this->token, $rid, $g->now, $g->now));
        }
        unready_all($g);
    }

    /** Leaves the lobby and frees the name. Not possible once the game has started. */
    private function leave()
    {
        $this->requireMe();
        if ($this->g->s['phase'] !== 'lobby') {
            fail('Während des Spiels nicht möglich.');
        }
        db()->prepare('DELETE FROM players WHERE token = ?')->execute(array($this->token));
        $this->g->loadPlayers();
        lobby_recheck($this->g);
    }

    /** Sets the sender ready or not ready ('on'), which starts or stops the start countdown. */
    private function ready()
    {
        $this->requireMe();
        if ($this->g->s['phase'] !== 'lobby') {
            fail('Das Spiel läuft bereits.', 409);
        }
        db()->prepare('UPDATE players SET ready = ? WHERE token = ?')
            ->execute(array(arg($this->in, 'on') ? 1 : 0, $this->token));
        $this->g->loadPlayers();
        lobby_recheck($this->g);
    }

    /** The current thrower throws; the server rolls hit, miss and Strafbier. */
    private function throwBall()
    {
        $this->requireMe();
        $this->expectPhase(array('turn'));
        if ($this->me['id'] !== $this->g->s['thrower']) {
            fail('Du bist nicht dran.', 403);
        }
        do_throw($this->g);
    }

    /** The thrower moves on from the Strafbier, miss or Stopp screen. */
    private function ok()
    {
        $this->requireMe();
        $this->expectPhase(array('strafbier', 'miss', 'stop'));
        if ($this->me['id'] !== $this->g->s['thrower']) {
            fail('Nur der Werfer kann weiter.', 403);
        }
        advance($this->g);
    }

    /**
     * An opponent takes a job after a hit: 'setter' (Aufstellen) or 'fetcher' (Ball holen).
     * First come, first served; one job per player; no ball job for a one-player team.
     */
    private function claim()
    {
        $this->requireMe();
        $s = $this->g->s;
        if ($s['phase'] !== 'countdown') {
            fail('Zu spät.', 409);
        }
        $role = arg($this->in, 'role');
        if ($role !== 'setter' && $role !== 'fetcher') {
            fail('Ungültige Aufgabe.');
        }
        $h = $s['hit'];
        $myId = $this->me['id'];
        if (team_of($s, $myId) !== $h['opp_team']) {
            fail('Nur das gegnerische Team.', 403);
        }
        if ($role === 'fetcher' && !empty($h['solo'])) {
            fail('Kein Ballholen nötig.', 409);
        }
        if ($h[$role] !== null) {
            fail('Schon vergeben.', 409);
        }
        if ($h['setter'] === $myId || $h['fetcher'] === $myId) {
            fail('Du hast schon eine Aufgabe.', 409);
        }
        assign_role($this->g, $role, $myId);
    }

    /** The sender's beer is empty: they leave their team, which may end the game. */
    private function finish()
    {
        $this->requireMe();
        $s = $this->g->s;
        if ($s['phase'] === 'lobby' || $s['phase'] === 'over' || team_of($s, $this->me['id']) === null) {
            fail('Nicht möglich.', 409);
        }
        finish_player($this->g, $this->me['id']);
    }

    /** Ends the game for everyone and returns to the lobby. Players stay joined. */
    private function reset()
    {
        $this->requireMe();
        reset_game($this->g);
    }

    /**
     * Stops the request with 403 unless the sender is a joined player.
     * Used by every action except join(), which is how a player gets a token in the first place.
     *
     * @return void Does not return on failure: fail() rolls back and exits.
     */
    private function requireMe()
    {
        if (!$this->me) {
            fail('Du bist nicht angemeldet.', 403);
        }
    }

    /**
     * Stops the request with 409 unless the client's screen matches the current game state.
     *
     * The client sends the phase ('ph') and turn_index ('ti') it was showing when the button was
     * tapped. A double tap or a tap on an outdated screen then can't advance the game twice,
     * e.g. a second "Weiter" landing after the turn has already moved on. The client quietly
     * refreshes on 409 instead of showing an error.
     *
     * @param array $allowed Phases in which this action is valid.
     * @return void Does not return on failure: fail() rolls back and exits.
     */
    private function expectPhase($allowed)
    {
        $s = $this->g->s;
        $ph = (string)arg($this->in, 'ph', '');
        if ($ph !== $s['phase'] || !in_array($ph, $allowed, true) || (int)arg($this->in, 'ti', -1) !== $s['turn_index']) {
            fail('Veraltet.', 409);
        }
    }
}
