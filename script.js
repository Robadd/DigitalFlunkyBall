let teams = { team1: [], team2: [] };
let teamNames = { team1: "Team 1", team2: "Team 2" };
let turnIndex = 0;
let advancedMode = false;

let minCountdown = 5,
    maxCountdown = 13;
let hitChance = 25,
    bottleChance = 10,
    beerChance = 5;

function showSettings() {
    document.getElementById('setup').classList.add('hidden');
    document.getElementById('settings').classList.remove('hidden');
}

function backToSetup() {
    document.getElementById('settings').classList.add('hidden');
    document.getElementById('setup').classList.remove('hidden');
}

function setupPlayers() {
    const count = parseInt(document.getElementById('playerCount').value);
    const inputsDiv = document.getElementById('playerInputs');
    inputsDiv.innerHTML = "";
    for (let i = 1; i <= count; i++) {
        inputsDiv.innerHTML += `Spieler ${i}: <input type="text" id="p${i}" value="Spieler ${i}"><br>`;
    }
    document.getElementById('setup').classList.add('hidden');
    document.getElementById('players').classList.remove('hidden');
}

function startGame() {
    teamNames.team1 = document.getElementById('team1Name').value || "Team 1";
    teamNames.team2 = document.getElementById('team2Name').value || "Team 2";
    advancedMode = document.getElementById('advanced').checked;

    minCountdown = parseInt(document.getElementById('countMin').value);
    maxCountdown = parseInt(document.getElementById('countMax').value);
    hitChance = parseInt(document.getElementById('hitChance').value);
    bottleChance = parseInt(document.getElementById('bottleChance').value);
    beerChance = parseInt(document.getElementById('beerChance').value);

    let players = [];
    const totalCount = parseInt(document.getElementById('playerCount').value);
    for (let i = 1; i <= totalCount; i++) {
        players.push(document.getElementById('p' + i).value);
    }
    players = players.sort(() => Math.random() - 0.5);
    teams.team1 = players.filter((_, i) => i % 2 === 0);
    teams.team2 = players.filter((_, i) => i % 2 === 1);

    document.getElementById('players').classList.add('hidden');
    document.getElementById('game').classList.remove('hidden');
    updateTeams();
    showTurnMessage();
}

function updateTeams() {
    document.getElementById('team1').innerHTML = `<h3>${teamNames.team1}</h3>` + teams.team1.map((p, i) => `<div class="${turnIndex % 2 === 0 && Math.floor(turnIndex / 2) % teams.team1.length === i ? 'current' : ''}">${p}</div>`).join("");
    document.getElementById('team2').innerHTML = `<h3>${teamNames.team2}</h3>` + teams.team2.map((p, i) => `<div class="${turnIndex % 2 === 1 && Math.floor(turnIndex / 2) % teams.team2.length === i ? 'current' : ''}">${p}</div>`).join("");
    document.getElementById('teamTurn').textContent = `Am Zug: ${(turnIndex % 2 === 0 ? teamNames.team1 : teamNames.team2)}`;
}

function currentPlayer() {
    return turnIndex % 2 === 0 ?
        teams.team1[Math.floor(turnIndex / 2) % teams.team1.length] :
        teams.team2[Math.floor(turnIndex / 2) % teams.team2.length];
}

function showTurnMessage() {
    document.getElementById('game').classList.add('hidden');
    showVisual(`${currentPlayer()} ist dran.`, showMainAction);
}

function showMainAction() {
    document.getElementById('visual').classList.add('hidden');
    document.getElementById('game').classList.remove('hidden');
    document.getElementById('throwBtn').disabled = false;
    document.getElementById('actionText').textContent = "";
    document.getElementById('countdown').textContent = "";
    updateTeams();
}

function doThrow() {
    document.getElementById('throwBtn').disabled = true;

    // TRICK: Den Hup-Sound stumm beim Klick vorstarten, um den Autoplay-Filter zu umgehen
    const horn = document.getElementById('hornSound');
    if (horn) {
        horn.volume = 0;
        horn.play().then(() => {
            horn.pause();
            horn.currentTime = 0;
            horn.volume = 1;
        }).catch(e => console.log(e));
    }

    if (advancedMode && Math.random() * 100 < beerChance) {
        showVisual(`🍺⚠️ <span class="penalty">STRAFBIER!</span><br>${currentPlayer()} muss ein Strafbier trinken!`, () => {
            evaluateHit();
        });
    } else {
        evaluateHit();
    }
}

function evaluateHit() {
    if (Math.random() * 100 < hitChance) {
        startCountdown();
    } else {
        showVisual("🙈<br>Nicht getroffen!", () => {
            turnIndex++;
            showTurnMessage();
        });
    }
}

function startCountdown() {
    let time = Math.floor(Math.random() * (maxCountdown - minCountdown + 1)) + minCountdown;
    let opponent = (turnIndex % 2 === 0 ? teams.team2 : teams.team1);
    const randomOpponent = opponent[Math.floor(Math.random() * opponent.length)];

    document.getElementById('actionText').textContent = `${randomOpponent} läuft und stellt die Flasche wieder auf.`;
    document.getElementById('countdown').textContent = `🍺 ${time} Sekunden`;

    const music = document.getElementById('countdownMusic');
    if (music) {
        music.currentTime = 0;
        music.play().catch(e => console.log(e));
    }

    let timeChanged = false;
    const interval = setInterval(() => {
        time--;
        if (!timeChanged && Math.random() * 100 < bottleChance && time > 2) {
            timeChanged = true;
            if (Math.random() < 0.5) {
                time = Math.max(1, time - 3);
                document.getElementById('actionText').textContent = `${randomOpponent} ist geschickt, er hat die Flasche schneller aufgestellt.`;
            } else {
                time += 3;
                document.getElementById('actionText').textContent = `${randomOpponent} ist ein Bodschie und braucht viel länger.`;
            }
        }
        document.getElementById('countdown').textContent = `🍺 ${time} Sekunden`;

        if (time <= 0) {
            clearInterval(interval);
            if (music) {
                music.pause();
                music.currentTime = 0;
            }

            // Sound direkt starten
            const horn = document.getElementById('hornSound');
            if (horn) {
                horn.volume = 1;
                horn.currentTime = 0;
                horn.play().catch(e => console.log(e));
            }

            showVisual("🛑✋<br>Stopp!", () => {
                turnIndex++;
                showTurnMessage();
            });
        }
    }, 1000);
}

function showVisual(text, onOk) {
    document.getElementById('game').classList.add('hidden');
    const vis = document.getElementById('visual');
    vis.innerHTML = `<p>${text}</p><button id="visualOk">OK</button>`;
    vis.classList.remove('hidden');

    document.getElementById('visualOk').onclick = () => {
        vis.classList.add('hidden');
        onOk();
    };
}

// Settings live-Update
document.getElementById('countMin').oninput = e => document.getElementById('countMinVal').textContent = e.target.value;
document.getElementById('countMax').oninput = e => document.getElementById('countMaxVal').textContent = e.target.value;
document.getElementById('hitChance').oninput = e => document.getElementById('hitChanceVal').textContent = e.target.value;
document.getElementById('bottleChance').oninput = e => document.getElementById('bottleChanceVal').textContent = e.target.value;
document.getElementById('beerChance').oninput = e => document.getElementById('beerChanceVal').textContent = e.target.value;