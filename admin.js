'use strict';

const $ = id => document.getElementById(id);
const settingsForm = $('settings');
let toastTimer = null;

async function api(a, extra) {
    const r = await fetch('api/admin.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ a }, extra || {})),
    });
    let d = {};
    try { d = await r.json(); } catch (e) { /* non-JSON error page */ }
    if (!r.ok || d.error) {
        const err = new Error(d.error || `Fehler ${r.status}`);
        err.status = r.status;
        throw err;
    }
    return d;
}

function toast(msg, bad) {
    const el = $('toast');
    el.textContent = msg;
    el.classList.toggle('bad', !!bad);
    el.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.add('hidden'), 3000);
}

function showLogin() {
    $('panel').classList.add('hidden');
    $('login').classList.remove('hidden');
    $('pw').focus();
}

const PHASES = {
    lobby: 'Lobby',
    turn: 'Wurf',
    strafbier: 'Strafbier',
    miss: 'Daneben',
    countdown: 'Countdown',
    stop: 'Stopp',
    over: 'Beendet',
};

function syncOutputs() {
    settingsForm.querySelectorAll('output[data-for]').forEach(o => {
        o.textContent = settingsForm.elements[o.dataset.for].value;
    });
}

function fillSettings(s) {
    Object.keys(s).forEach(k => {
        const el = settingsForm.elements[k];
        if (!el) return;
        if (el.type === 'checkbox') el.checked = !!s[k];
        else el.value = s[k];
    });
    syncOutputs();
}

function readSettings() {
    const f = settingsForm.elements;
    const out = { strafbier: f.strafbier.checked };
    ['team1Name', 'team2Name', 'transport'].forEach(k => { out[k] = f[k].value; });
    ['tRunTo', 'tSetUp', 'tRunBack', 'tFetch', 'variance', 'hitChance', 'beerChance', 'tipChance', 'startDelay'].forEach(k => { out[k] = Number(f[k].value); });
    return out;
}

function renderPlayers(d) {
    $('phase').textContent = PHASES[d.phase] || d.phase;
    const ul = $('players');
    ul.textContent = '';
    if (!d.players.length) {
        const li = document.createElement('li');
        li.className = 'muted';
        li.textContent = 'Niemand angemeldet.';
        ul.appendChild(li);
        return;
    }
    d.players.forEach(p => {
        const li = document.createElement('li');
        const status = !p.online ? '📴 offline' : p.ready ? '✅ bereit' : '⏳ nicht bereit';
        const team = p.team ? ` · ${d.settings['team' + p.team + 'Name']}` : '';
        li.textContent = `${p.name} – ${status}${team}`;
        ul.appendChild(li);
    });
}

function renderRoster(list) {
    const box = $('roster');
    box.textContent = '';
    list.forEach(r => {
        const row = document.createElement('div');
        row.className = 'roster-item';
        row.innerHTML = '<input type="text" maxlength="40">'
            + '<label class="speed">Tempo <input type="range" min="1" max="10"><output></output></label>'
            + '<button type="button" data-do="save">Speichern</button>'
            + '<button type="button" data-do="del" class="danger">Löschen</button>';
        const [name, speed] = row.querySelectorAll('input');
        const out = row.querySelector('output');
        name.value = r.name;
        speed.value = r.speed;
        out.textContent = r.speed;
        speed.addEventListener('input', () => { out.textContent = speed.value; });
        row.querySelector('[data-do=save]').addEventListener('click', () => {
            run('rosterUpdate', { id: r.id, name: name.value, speed: Number(speed.value) }, 'Gespeichert');
        });
        row.querySelector('[data-do=del]').addEventListener('click', () => {
            if (confirm(`${r.name} löschen?`)) run('rosterDelete', { id: r.id }, 'Gelöscht');
        });
        box.appendChild(row);
    });
}

function show(d, withSettings) {
    $('login').classList.add('hidden');
    $('panel').classList.remove('hidden');
    renderPlayers(d);
    renderRoster(d.roster);
    // Only refill the form when asked, so unsaved edits survive roster actions.
    if (withSettings) fillSettings(d.settings);
}

async function run(a, extra, okMsg, withSettings) {
    try {
        const d = await api(a, extra);
        show(d, withSettings);
        if (okMsg) toast(okMsg);
        return true;
    } catch (e) {
        if (e.status === 401) showLogin();
        else toast(e.message, true);
        return false;
    }
}

$('login').addEventListener('submit', async e => {
    e.preventDefault();
    const pw = $('pw');
    await run('login', { password: pw.value }, null, true);
    pw.value = '';
});

settingsForm.addEventListener('input', syncOutputs);
settingsForm.addEventListener('submit', e => {
    e.preventDefault();
    run('saveSettings', { settings: readSettings() }, 'Einstellungen gespeichert', true);
});

const addForm = $('add');
addForm.elements.speed.addEventListener('input', () => {
    addForm.querySelector('output').textContent = addForm.elements.speed.value;
});
addForm.addEventListener('submit', async e => {
    e.preventDefault();
    const ok = await run('rosterAdd', { name: addForm.elements.name.value, speed: Number(addForm.elements.speed.value) }, 'Hinzugefügt');
    if (ok) {
        addForm.elements.name.value = '';
        addForm.elements.name.focus();
    }
});

$('refresh').addEventListener('click', () => run('get'));
$('reset').addEventListener('click', () => {
    if (confirm('Spiel zurücksetzen? Alle bleiben angemeldet und landen in der Lobby.')) run('reset', null, 'Zurückgesetzt');
});
$('kick').addEventListener('click', () => {
    if (confirm('Alle Spieler abmelden und Spiel zurücksetzen?')) run('kickAll', null, 'Alle entfernt');
});
$('logout').addEventListener('click', async () => {
    try { await api('logout'); } catch (e) { /* already logged out */ }
    showLogin();
});

api('get')
    .then(d => show(d, true))
    .catch(e => {
        if (e.status === 401) showLogin();
        else toast(e.message, true);
    });
