'use strict';

const API = 'api/';
const POLL_MS = 500;
const FETCH_TIMEOUT_MS = 5000;

const store = {
    get(k) {
        try { return localStorage.getItem(k); } catch (e) { return null; }
    },
    set(k, v) {
        try {
            if (v === null) localStorage.removeItem(k);
            else localStorage.setItem(k, v);
        } catch (e) { /* storage unavailable: token lives only for this page load */ }
    },
};

const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const viewEl = $('view');
const teamsEl = $('teams');
const footEl = $('foot');
const netEl = $('net');
const soundHint = $('soundHint');
const toastEl = $('toast');
const music = $('countdownMusic');
const horn = $('hornSound');

const STAGE_TEXT = {
    wait: 'wartet noch',
    hin: 'läuft zur Flasche',
    auf: 'stellt die Flasche auf',
    zurueck: 'läuft zurück',
    umgefallen: '– Flasche umgefallen! Läuft nochmal hin',
    done: 'ist zurück ✔',
};
const FETCH_TEXT = {
    wait: 'wartet noch',
    ball: 'holt den Ball',
    done: 'ist mit dem Ball zurück ✔',
};

const TOKEN_KEY = 'flunkyball.token';
let token = store.get(TOKEN_KEY) || '';
let data = null;
let busy = false;
let lastKey = null;
let lastView = null;

const clock = { samples: [], offset: 0 };
let transport = null;
let pollTimer = null;
let pollInFlight = false;
let es = null;
let sseFails = 0;
let sseBye = false;

let audioUnlocked = false;
let musicOn = false;
let hornFor = null;
let wakeLock = null;
let wakeNextTry = 0;
let startKickFor = null;
let toastTimer = null;

// ---------- clock & network ----------

// Keep the offset from the sample with the lowest round trip; it has the smallest error.
function clockSample(t0, t1, serverSec) {
    const rtt = t1 - t0;
    clock.samples.push({ rtt, off: serverSec * 1000 - (t0 + rtt / 2) });
    if (clock.samples.length > 30) clock.samples.shift();
    clock.offset = clock.samples.reduce((a, b) => (b.rtt < a.rtt ? b : a)).off;
}

const serverNow = () => (Date.now() + clock.offset) / 1000;

// Without a timeout, one request that hangs on a flaky mobile connection would stop polling for good.
async function getJSON(url, opts) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
    try {
        const t0 = Date.now();
        const r = await fetch(url, Object.assign({ cache: 'no-store', signal: ctrl.signal }, opts));
        const t1 = Date.now();
        const d = await r.json();
        if (typeof d.now === 'number') clockSample(t0, t1, d.now);
        return { status: r.status, d };
    } finally {
        clearTimeout(timer);
    }
}

const stateUrl = v => `${API}state.php?v=${v}&t=${encodeURIComponent(token)}`;

function setNet(ok) {
    netEl.textContent = ok ? '' : '⚠ Verbindung …';
}

function schedulePoll(ms) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(pollOnce, ms);
}

async function pollOnce() {
    if (pollInFlight) return;
    pollInFlight = true;
    let ok = true;
    try {
        const { d } = await getJSON(stateUrl(data ? data.v : -1));
        if (d.error) ok = false;
        else apply(d);
    } catch (e) {
        ok = false;
    }
    pollInFlight = false;
    setNet(ok);
    if (transport === 'poll') schedulePoll(ok ? POLL_MS : 2000);
}

function startTransport(mode) {
    clearTimeout(pollTimer);
    if (es) {
        es.close();
        es = null;
    }
    transport = mode;
    if (mode === 'sse') openSSE();
    else schedulePoll(0);
}

function syncTransport() {
    const want = data && data.settings.transport === 'sse' && 'EventSource' in window && sseFails < 3 ? 'sse' : 'poll';
    if (want !== transport) startTransport(want);
}

function openSSE() {
    sseBye = false;
    es = new EventSource(`${API}events.php?v=${data ? data.v : -1}&t=${encodeURIComponent(token)}`);
    es.onmessage = e => {
        sseFails = 0;
        setNet(true);
        apply(JSON.parse(e.data));
    };
    // The server ends each stream after ~25 s; that reconnect is expected, not a failure.
    es.addEventListener('bye', () => { sseBye = true; });
    es.onerror = () => {
        if (sseBye) {
            sseBye = false;
            return;
        }
        sseFails++;
        setNet(false);
        if (sseFails >= 3) startTransport('poll');
    };
}

async function refresh() {
    if (transport !== 'sse') {
        schedulePoll(0);
        return;
    }
    if (!es || es.readyState === EventSource.CLOSED) openSSE();
    try {
        apply((await getJSON(stateUrl(-1))).d);
    } catch (e) { /* the stream will catch up */ }
}

function setToken(t) {
    if (t === token) return;
    token = t;
    store.set(TOKEN_KEY, t || null);
    if (transport === 'sse') startTransport('sse');
}

function apply(d) {
    if (!d || d.u || d.error) return;
    if (data && d.v < data.v) return;
    data = d;
    syncTransport();
    frame();
}

async function act(a, extra) {
    if (busy) return;
    busy = true;
    frame();
    try {
        const { status, d } = await getJSON(API + 'action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ a, t: token }, extra || {})),
        });
        if (typeof d.token === 'string') setToken(d.token);
        if (d.error) {
            if (status !== 409) toast(d.error, true);
            refresh();
        } else {
            if (a === 'leave') setToken('');
            apply(d);
        }
    } catch (e) {
        toast('Keine Verbindung – bitte nochmal versuchen.', true);
    }
    busy = false;
    frame();
}

function toast(msg, bad) {
    toastEl.textContent = msg;
    toastEl.classList.toggle('bad', !!bad);
    toastEl.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.add('hidden'), 3000);
}

// ---------- views ----------

const teamName = t => (t ? data.settings['team' + t + 'Name'] : '');
// Current team from the game state; finished players are no longer in any team.
const teamOf = (s, id) => (id === null ? null : ([1, 2].find(t => (s.teams[t] || []).indexOf(id) >= 0) || null));

function setterStage(h, now) {
    if (!h || h.setter === null) return null;
    if (now < h.setter_start) return 'wait';
    for (const st of h.timeline) {
        if (now < st.end) return st.stage;
    }
    return 'done';
}

function fetcherStage(h, now) {
    if (!h || h.fetcher === null) return null;
    if (now < h.fetcher_start) return 'wait';
    return now < h.fetcher_end ? 'ball' : 'done';
}

// key: re-render when it changes. buzz: vibration pattern when the screen type changes.
function screen(key, cls, icon, title, sub, extra, buzz) {
    return {
        key,
        cls,
        buzz: buzz || null,
        html: `<div class="big-icon">${icon}</div><div class="big-title">${title}</div>${sub ? `<div class="sub">${sub}</div>` : ''}${extra || ''}`,
    };
}

function lobbyList(d) {
    if (!d.players.length) return '<p class="muted">Noch niemand in der Lobby.</p>';
    const rows = d.players.map(p => {
        const status = !p.online ? '📴 offline' : p.ready ? '✅ Bereit' : '⏳ Nicht bereit';
        const mine = p.id === d.me;
        return `<li class="${mine ? 'me' : ''}"><span>${esc(p.name)}${mine ? ' (du)' : ''}</span><span>${status}</span></li>`;
    }).join('');
    return `<div class="lobby"><h3>In der Lobby (${d.players.length})</h3><ul>${rows}</ul></div>`;
}

function lobbyView(d, P, dis) {
    const list = lobbyList(d);
    if (d.me === null) {
        const taken = new Set(d.players.map(p => p.id));
        const picks = d.roster.length
            ? `<div class="pick-grid">${d.roster.map(r => {
                const t = taken.has(r.id);
                return `<button class="pick" data-act="join" data-id="${r.id}"${t || busy ? ' disabled' : ''}>${esc(r.name)}${t ? '<small>dabei</small>' : ''}</button>`;
            }).join('')}</div>`
            : '<p class="muted">Noch keine Spieler angelegt – im Admin-Bereich hinzufügen.</p>';
        const soon = d.s.start_at !== null ? '<div class="starting">Spiel startet in <span data-live="start"></span> s</div>' : '';
        return { key: 'lobby-pick', cls: 'v-plain', html: `<h2>Wer bist du?</h2>${soon}${picks}${list}` };
    }
    if (d.s.start_at !== null) {
        return {
            key: 'lobby-countdown',
            cls: 'v-green',
            buzz: [200],
            html: '<div class="sub">Alle bereit – das Spiel startet in</div>'
                + '<div class="countdown huge"><span data-live="start"></span></div>'
                + `<button class="big-btn" data-act="ready" data-on="0"${dis}>Doch nicht bereit</button>${list}`,
        };
    }
    const ready = !!(P[d.me] && P[d.me].ready);
    const notReady = d.players.filter(p => !p.ready).map(p => (p.id === d.me ? 'dich' : esc(p.name)));
    const status = d.players.length < 2 ? 'Warte auf mindestens 2 Spieler …' : `Warte auf: ${notReady.join(', ')}`;
    const btn = `<button class="big-btn ready-btn${ready ? ' on' : ''}" data-act="ready" data-on="${ready ? 0 : 1}"${dis}>${ready ? '✅ Bereit' : '⏳ Nicht bereit – tippen'}</button>`;
    return {
        key: 'lobby-joined',
        cls: 'v-plain',
        html: `<h2>Hallo ${esc(P[d.me] ? P[d.me].name : '')}!</h2>${btn}<div class="muted">${status}</div>${list}<button class="link" data-act="leave"${dis}>Abmelden</button>`,
    };
}

function setterScreen(st, cd) {
    switch (st) {
        case 'hin': return screen('st-hin', 'v-orange', '🏃', 'HINLAUFEN!', 'Lauf zur Flasche', cd, [200]);
        case 'auf': return screen('st-auf', 'v-orange', '🍾', 'AUFSTELLEN!', 'Stell die Flasche wieder hin', cd, [100]);
        case 'zurueck': return screen('st-zur', 'v-orange', '🏃', 'ZURÜCKLAUFEN!', 'Schnell hinter die Linie', cd, [100]);
        default: return screen('st-tip', 'v-red', '💥', 'UMGEFALLEN!', 'Nochmal hin und aufstellen!', cd, [500, 100, 500]);
    }
}

function countdownView(d, h, now, c) {
    const sSt = setterStage(h, now);
    const fSt = fetcherStage(h, now);
    const stages = `${sSt}|${fSt}|${h.setter}|${h.fetcher}`;
    const info = `<div class="info">🍾 ${h.setter !== null ? `${c.nm(h.setter)} ${STAGE_TEXT[sSt]}` : 'Aufstellen: noch offen'}`
        + (h.solo ? '' : `<br>⚽ ${h.fetcher !== null ? `${c.nm(h.fetcher)} ${FETCH_TEXT[fSt]}` : 'Ball holen: noch offen'}`)
        + '</div>';

    if (c.myTeam !== null && c.myTeam === c.thrTeam) {
        return screen('drink|' + stages, 'v-green', '🍺', 'TRINKEN!', '', c.cd + info + c.finishBtn, [300]);
    }
    if (c.myTeam !== null && c.myTeam === h.opp_team) {
        const iS = h.setter === d.me;
        const iF = h.fetcher === d.me;
        if (iS && sSt !== 'done' && sSt !== 'wait') return setterScreen(sSt, c.cd);
        if (iF && fSt === 'ball') return screen('fetch', 'v-orange', '⚽', 'BALL HOLEN!', 'Hol den Ball und lauf zurück', c.cd, [200]);
        if (iS || iF) return screen('role-done|' + stages, 'v-plain', '✔️', 'Fertig!', 'Warte auf den Rest …', c.cd + info);
        if (h.setter === null || (h.fetcher === null && !h.solo)) {
            const btn = (role, icon, label) => (h[role] !== null
                ? `<button class="big-btn claimed" disabled>${icon} ${label} – ${c.nm(h[role])}</button>`
                : `<button class="big-btn" data-act="claim" data-role="${role}"${c.dis}>${icon} ${label}</button>`);
            return screen(
                `claim|${h.setter}|${h.fetcher}`, 'v-orange', '🏃', 'Getroffen! Wer macht was?',
                'Automatische Verteilung in <span data-live="claim"></span> s',
                btn('setter', '🍾', 'Aufstellen') + btn('fetcher', '⚽', 'Ball holen'),
                [200, 100, 200],
            );
        }
    }
    return screen('watch|' + stages, 'v-plain', '👀', 'Getroffen!', '', c.cd + info);
}

function buildView(d, now) {
    const s = d.s;
    const h = s.hit;
    const P = {};
    d.players.forEach(p => { P[p.id] = p; });
    const dis = busy ? ' disabled' : '';

    let phase = s.phase;
    // Every phone switches to STOPP at the shared end time without waiting for the server.
    if (phase === 'countdown' && h && h.end !== null && now >= h.end) phase = 'stop';

    let v;
    if (phase === 'lobby') {
        v = lobbyView(d, P, dis);
    } else {
        const c = {
            nm: id => esc(P[id] ? P[id].name : '?'),
            dis,
            myTeam: teamOf(s, d.me),
            thrTeam: s.thrower_team,
            cd: '<div class="countdown">🍺 <span data-live="countdown"></span> s</div>',
            finishBtn: `<button class="finish-btn" data-act="finish"${dis}>🍺 Leer – ich bin fertig</button>`,
        };
        const isThrower = d.me !== null && d.me === s.thrower;
        const okBtn = label => (isThrower ? `<button class="big-btn" data-act="ok"${dis}>${label}</button>` : '');

        switch (phase) {
            case 'turn':
                v = isThrower
                    ? screen('turn-me', 'v-blue', '🎯', 'DU BIST DRAN!', 'Wirf auf die Flasche', `<button class="big-btn" data-act="throw"${dis}>Wurf</button>`, [200, 100, 200])
                    : screen('turn', 'v-plain', '⏳', `${c.nm(s.thrower)} wirft …`, esc(teamName(c.thrTeam)));
                break;
            case 'strafbier':
                v = screen('strafbier', 'v-red', '🍺⚠️', 'STRAFBIER!',
                    isThrower ? 'Du musst ein Strafbier trinken!' : `${c.nm(s.thrower)} muss ein Strafbier trinken!`,
                    okBtn('Getrunken – weiter'), [400]);
                break;
            case 'miss':
                v = screen('miss', 'v-plain', '🙈', 'Nicht getroffen!',
                    isThrower ? 'Daneben.' : `${c.nm(s.thrower)} hat daneben geworfen.`, okBtn('Weiter'));
                break;
            case 'countdown':
                v = countdownView(d, h, now, c);
                break;
            case 'stop': {
                const sub = h && h.end !== null ? `Trinkzeit: ${(h.end - h.started).toFixed(1)} s` : '';
                const drinking = c.myTeam !== null && c.myTeam === c.thrTeam;
                v = screen(drinking ? 'stop-drink' : 'stop', 'v-red', '🛑', drinking ? 'STOPP – absetzen!' : 'STOPP!', sub,
                    okBtn('Weiter') + (drinking ? c.finishBtn : ''), [500]);
                break;
            }
            case 'over': {
                const mine = d.me !== null && P[d.me] ? P[d.me].team : null;
                const sub = mine === null ? 'Alle Biere leer.' : mine === s.winner ? 'Glückwunsch – ihr wart schneller!' : 'Nächstes Mal klappt\'s!';
                v = screen('over', 'v-green', '🏆', `Sieg für ${esc(teamName(s.winner))}!`, sub,
                    d.me !== null ? `<button class="big-btn" data-act="newgame"${dis}>Neues Spiel</button>` : '', [300, 100, 300]);
                break;
            }
            default:
                v = screen('unknown', 'v-plain', '…', 'Lade …');
        }
    }
    v.phase = phase;
    v.base = v.key.split('|')[0];
    return v;
}

function renderTeams() {
    const s = data.s;
    if (s.phase === 'lobby') {
        teamsEl.classList.add('hidden');
        teamsEl.innerHTML = '';
        return;
    }
    const P = {};
    data.players.forEach(p => { P[p.id] = p; });
    const name = id => esc(P[id] ? P[id].name : '?') + (id === data.me ? ' (du)' : '');
    const over = s.phase === 'over';
    teamsEl.classList.remove('hidden');
    teamsEl.innerHTML = [1, 2].map(t => {
        const members = (s.teams[t] || []).map(id => {
            const off = s.offline.indexOf(id) >= 0;
            const cls = `member${id === s.thrower && !over ? ' current' : ''}${off ? ' off' : ''}`;
            return `<div class="${cls}">${name(id)}${off ? ' 📴' : ''}</div>`;
        }).join('');
        const done = s.finished.filter(f => f.team === t).map(f => `<div class="member done">✔ ${name(f.id)}</div>`).join('');
        const cls = `team${t === s.thrower_team && !over ? ' active' : ''}${t === s.winner ? ' winner' : ''}`;
        return `<div class="${cls}"><h3>${t === s.winner ? '🏆 ' : ''}${esc(teamName(t))}</h3>${members}${done}</div>`;
    }).join('');
}

function renderFoot() {
    const s = data.s;
    if (s.phase === 'lobby' || s.phase === 'over') {
        footEl.innerHTML = '';
        return;
    }
    if (data.me === null) {
        footEl.innerHTML = '<span class="muted">Das Spiel läuft – du schaust zu.</span>';
        return;
    }
    const mine = teamOf(s, data.me) !== null
        ? `<button class="finish-btn" data-act="finish"${busy ? ' disabled' : ''}>🍺 Ich bin fertig</button>`
        : '<span class="muted">✔ Du bist fertig und schaust jetzt zu.</span>';
    footEl.innerHTML = mine + '<button class="link" data-act="end">Spiel beenden</button>';
}

function updateLive(now) {
    const s = data.s;
    const h = s.hit;
    viewEl.querySelectorAll('[data-live]').forEach(el => {
        let t = '';
        switch (el.dataset.live) {
            case 'start':
                if (s.start_at !== null) {
                    const left = Math.ceil(s.start_at - now);
                    t = left > 0 ? left : '…';
                }
                break;
            case 'countdown':
                t = h && h.end !== null ? Math.max(0, Math.ceil(h.end - now)) : '?';
                break;
            case 'claim':
                t = h ? Math.max(0, Math.ceil(h.started + data.settings.claimTimeout - now)) : '';
                break;
        }
        t = String(t);
        if (el.textContent !== t) el.textContent = t;
    });
}

// ---------- sound, vibration, wake lock ----------

function sounds(v, now) {
    const h = data.s.hit;
    // Music only on the thrower's phone, as in the single-device version; the horn plays everywhere.
    const wantMusic = v.phase === 'countdown' && data.me !== null && data.me === data.s.thrower;
    if (wantMusic && !musicOn) {
        musicOn = true;
        music.currentTime = 0;
        music.play().catch(() => {});
    } else if (!wantMusic && musicOn) {
        musicOn = false;
        music.pause();
    }
    if (v.phase === 'stop' && h && hornFor !== h.id) {
        hornFor = h.id;
        if (now - h.end < 3) {
            horn.currentTime = 0;
            horn.play().catch(() => {});
        }
    }
}

// Mobile browsers only allow audio after a user gesture, so prime both elements on the first tap.
function unlockAudio() {
    if (audioUnlocked) return;
    audioUnlocked = true;
    [music, horn].forEach(a => {
        if (a === music && musicOn) return;
        a.muted = true;
        const p = a.play();
        const reset = () => {
            a.pause();
            a.currentTime = 0;
            a.muted = false;
        };
        if (p && p.then) p.then(reset, () => { a.muted = false; });
        else reset();
    });
    frame();
}

async function keepAwake() {
    if (wakeLock || Date.now() < wakeNextTry || !('wakeLock' in navigator)) return;
    if (document.visibilityState !== 'visible' || !data || data.me === null) return;
    wakeNextTry = Date.now() + 10000;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; wakeNextTry = 0; });
    } catch (e) { /* retried after wakeNextTry */ }
}

// ---------- main loop ----------

function frame() {
    if (!data) return;
    const now = serverNow();
    const v = buildView(data, now);
    const key = `${v.key}|${data.v}|${data.me}|${busy}`;
    if (key !== lastKey) {
        if (v.buzz && (!lastView || lastView.base !== v.base) && navigator.vibrate) navigator.vibrate(v.buzz);
        lastKey = key;
        lastView = v;
        viewEl.className = 'view ' + v.cls;
        viewEl.innerHTML = v.html;
        renderTeams();
        renderFoot();
    }
    updateLive(now);
    // Ask the server right at the start time instead of waiting for the next poll.
    if (data.s.phase === 'lobby' && data.s.start_at !== null && now >= data.s.start_at && startKickFor !== data.s.start_at) {
        startKickFor = data.s.start_at;
        refresh();
    }
    sounds(v, now);
    keepAwake();
    soundHint.classList.toggle('hidden', audioUnlocked || data.me === null);
}

document.addEventListener('click', unlockAudio, true);

document.addEventListener('click', e => {
    const b = e.target.closest('[data-act]');
    if (!b || b.disabled || !data) return;
    const s = data.s;
    switch (b.dataset.act) {
        case 'join':
            act('join', { roster_id: Number(b.dataset.id) });
            break;
        case 'ready':
            act('ready', { on: b.dataset.on === '1' });
            break;
        case 'leave':
            act('leave');
            break;
        case 'throw':
            act('throw', { ph: 'turn', ti: s.turn_index });
            break;
        case 'claim':
            act('claim', { role: b.dataset.role });
            break;
        case 'ok':
            act('ok', { ph: lastView ? lastView.phase : s.phase, ti: s.turn_index });
            break;
        case 'finish':
            if (confirm('Bier leer? Du bist dann raus aus deinem Team.')) act('finish');
            break;
        case 'newgame':
            act('reset');
            break;
        case 'end':
            if (confirm('Spiel für alle beenden?')) act('reset');
            break;
    }
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    wakeNextTry = 0;
    refresh();
});

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

startTransport('poll');
setInterval(frame, 200);
