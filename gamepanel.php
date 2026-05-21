<?php
session_start();
require_once __DIR__ . '/config.php';

$isScarer = isset($_SESSION['user_id']) && $_SESSION['role'] === 'scarer';
$scarerName = $_SESSION['username'] ?? '';
$roomId = $_GET['room'] ?? '';
$instanceId = $_GET['instance'] ?? '';

$scarerAbilitySounds = [];
foreach (['ability1', 'ability2', 'ability3', 'ability4'] as $ability) {
    $scarerAbilitySounds[$ability] = [];
    $dir = __DIR__ . "/assets/sound_effects/scarer/{$ability}";
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.{mp3,wav}', GLOB_BRACE) as $filePath) {
            $scarerAbilitySounds[$ability][] = 'assets/sound_effects/scarer/' . $ability . '/' . basename($filePath);
        }
        sort($scarerAbilitySounds[$ability], SORT_NATURAL);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_TITLE) ?> Gameplay</title>
    <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #090913; color: #f5f5f7; }
        .page { max-width: 1100px; margin: 0 auto; padding: 24px; }
        h1, h2 { margin: .4em 0; }
        .card { background: rgba(16, 18, 32, .94); border: 1px solid #2a2d46; border-radius: 18px; padding: 20px; margin-bottom: 16px; }
        .status { margin: 12px 0; color: #d6d6ff; }
        #gamePath { position: relative; width: 100%; height: 280px; margin-top: 18px; background: radial-gradient(circle at top, #181a31, #090913 45%); border: 2px solid #35386e; border-radius: 20px; overflow: hidden; }
        #pathTrack { position: absolute; left: 8%; top: 8%; width: 84%; height: 84%; border-radius: 24px; background: linear-gradient(180deg, rgba(100,80,180,.18), rgba(54,56,92,.95)); box-shadow: inset 0 0 30px rgba(0,0,0,.35); }
        #playerLayer { position: absolute; left: 0; top: 0; width: 100%; height: 100%; pointer-events: none; }
        .player-avatar { position: absolute; width: 28px; height: 28px; border-radius: 50%; transform: translate(-50%, -50%); display: flex; align-items: center; justify-content: center; color: #111; font-weight: 700; font-size: 0.75rem; text-shadow: 0 0 4px rgba(0,0,0,.7); }
        .player-avatar.host { background: #a855f7; box-shadow: 0 0 16px rgba(168,85,247,.75); }
        .player-avatar.walker { background: #14b8a6; box-shadow: 0 0 16px rgba(20,184,166,.75); }
        .player-avatar.near { border: 2px solid #facc15; box-shadow: 0 0 18px rgba(250,204,21,.8); }
        .player-avatar::after { content: attr(data-label); position: absolute; top: -18px; left: 50%; transform: translateX(-50%); color: #eef; font-size: 0.7rem; white-space: nowrap; }
        #playerMarker { display: none; }
        #scareOverlay { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none; color: #fff; font-size: 2rem; opacity: 0; transition: opacity .2s ease; text-shadow: 0 0 20px #ff2471, 0 0 80px rgba(255,36,113,.3); }
        #scareOverlay.active { opacity: 1; }
        .status-badge { display: inline-block; margin-top: 10px; padding: 8px 14px; border-radius: 999px; font-size: 0.9rem; font-weight: 600; letter-spacing: .02em; background: #111827; color: #e5e7eb; }
        .status-badge.connected { background: #064e3b; color: #d1fae5; }
        .status-badge.online { background: #1d4ed8; color: #dbeafe; }
        .status-badge.waiting { background: #78350f; color: #fde68a; }
        .status-badge.error { background: #7f1d1d; color: #fee2e2; }
        .status-badge.offline { background: #111827; color: #cbd5e1; }
        .debug-toggle { margin: 10px 0 0 0; cursor: pointer; color: #a5b4fc; background: none; border: none; font-size: 1rem; text-align: left; }
        #debugPanel { display: none; }
        #debugPanel.open { display: block; }
    </style>
</head>
<body>
    <div class="page">
        <header class="card">
            <h1><?= htmlspecialchars(SITE_TITLE) ?> Gameplay</h1>
            <p>Use the arrow keys to move. Host and walkers are shown on the path. Scare effects will appear if triggered by the host.</p>
        </header>
        <?php if ($isScarer): ?>
        <section class="card">
            <h2>Host Scare Controls</h2>
            <button type="button" onclick="sendScare('Spectral Chill')">Trigger Spectral Chill</button>
            <button type="button" onclick="sendScare('Flashbang Fear')">Trigger Flashbang Fear</button>
            <button type="button" onclick="sendScare('Ghostly Whisper')">Trigger Ghostly Whisper</button>
            <button type="button" id="closeGameBtn" style="margin-left:24px;background:#7f1d1d;color:#fff;">Close Game</button>
        </section>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var closeBtn = document.getElementById('closeGameBtn');
            if (closeBtn) {
                closeBtn.onclick = async function() {
                    if (!window.GAME_INSTANCE_ID) { alert('No game instance.'); return; }
                    if (!confirm('Are you sure you want to close this game?')) return;
                    closeBtn.disabled = true;
                    closeBtn.textContent = 'Closing...';
                    try {
                        const resp = await fetch('close_game.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ instance_id: window.GAME_INSTANCE_ID })
                        });
                        const data = await resp.json();
                        if (data.success) {
                            window.location.href = 'lobby.php';
                        } else {
                            alert('Failed to close: ' + (data.error || 'Unknown error'));
                        }
                    } catch (e) {
                        alert('Error: ' + (e.message || e));
                    } finally {
                        closeBtn.disabled = false;
                        closeBtn.textContent = 'Close Game';
                    }
                };
            }
        });
        </script>
        <?php endif; ?>
        <section class="card">
            <h2>Gameplay</h2>
            <audio id="booSound" src="" preload="auto"></audio>
            <?php if ($isScarer): ?>
            <div id="soulCounterBar" style="margin:10px 0 0 0;padding:8px 18px;background:#23244a;border-radius:10px;display:inline-block;font-size:1.1rem;">
                <span>Souls Collected: <span id="soulCounter">0</span></span>
            </div>
            <?php endif; ?>
            <button type="button" onclick="if(confirm('Are you sure you want to leave the game?')) window.location.href='lobby.php'" style="float:right;margin-top:-8px;margin-right:-8px;background:#374151;color:#fff;">Leave</button>
            <div id="gamePath">
                <div id="pathTrack"></div>
                <div id="playerLayer"></div>
                <div id="scareOverlay"></div>
            </div>
            <div id="connectionBadge" class="status-badge offline">Offline</div>
            <button class="debug-toggle" onclick="document.getElementById('debugPanel').classList.toggle('open')">Show Debug Panel</button>
            <div id="debugPanel">
                <pre id="debugLog" style="white-space: pre-wrap; background: rgba(12, 14, 28, 0.9); border: 1px solid #262a45; color: #c6d9ff; padding: 12px; border-radius: 12px; max-height: 180px; overflow:auto; margin-top: 16px; font-size:0.9rem;">Debug output will appear here.</pre>
            </div>
            <ul id="peerList"></ul>
            <p class="status" id="statusMessage">Status will appear here.</p>
            <div id="roomInfoBar"></div>
        </section>
    </div>
    <!-- Hidden host info for JS compatibility -->
    <div style="display:none">
        <span id="hostPeerId"></span>
        <span id="hostRoomId"></span>
    </div>
    <script>
        window.SCARER_USER_ID = <?= $isScarer ? (int)$_SESSION['user_id'] : 0 ?>;
        window.SCARER_USERNAME = <?= $isScarer ? json_encode($scarerName) : 'null' ?>;
        window.WALKER_USERNAME = <?= !$isScarer && isset($_SESSION['username']) ? json_encode($_SESSION['username']) : 'null' ?>;
        window.GAME_INSTANCE_ID = <?= $instanceId ? (int)$instanceId : 0 ?>;
        window.GAME_ROOM_ID = <?= $roomId ? json_encode($roomId) : 'null' ?>;
        window.SCARER_ABILITY_SOUNDS = <?= json_encode($scarerAbilitySounds) ?>;
    </script>
    <style>
    #roomInfoTable {
        width: 100%;
        margin: 18px 0 0 0;
        background: #181a31;
        border-radius: 12px;
        border: 1px solid #35386e;
        color: #e5e7eb;
        font-size: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    #roomInfoTable th, #roomInfoTable td {
        padding: 12px 18px;
        text-align: left;
        vertical-align: top;
    }
    #roomInfoTable th {
        background: #23244a;
        font-weight: 600;
        font-size: 1.05rem;
        border-bottom: 1px solid #35386e;
    }
    #roomInfoTable td {
        background: #181a31;
    }
    #roomInfoTable ul {
        margin: 0; padding-left: 18px;
    }
    </style>
    <script>
    // Update room info panel with live data as a table
    function updateRoomInfo(players) {
        console.log('[updateRoomInfo] called with:', players);
        var scarer = '—';
        var walkers = [];
        for (const id in players) {
            const p = players[id];
            if (id === 'host' || p.role === 'host') {
                scarer = p.username || id;
            } else {
                walkers.push(p.username || id);
            }
        }
        var tableHtml = '<table id="roomInfoTable">';
        tableHtml += '<tr>';
        tableHtml += '<th style="width:60%">Walkers</th>';
        tableHtml += '<th style="width:40%">Scarer</th>';
        tableHtml += '</tr>';
        tableHtml += '<tr>';
        // Walkers list as vertical list
        tableHtml += '<td>';
        if (walkers.length) {
            tableHtml += '<ul>' + walkers.map(function(w) { return '<li>' + w + '</li>'; }).join('') + '</ul>';
        } else {
            tableHtml += '—';
        }
        tableHtml += '</td>';
        // Scarer cell
        tableHtml += '<td>' + scarer + '</td>';
        tableHtml += '</tr>';
        tableHtml += '</table>';
        var infoBar = document.getElementById('roomInfoBar');
        if (infoBar) infoBar.innerHTML = tableHtml;
        window._lastRoomInfoPlayers = players; // debug
    }
    window.updateRoomInfo = updateRoomInfo;
    </script>
    <script src="game.js"></script>
    <script>
    // Robustly patch renderGameState only after game.js is loaded and the function is defined
    (function patchRenderGameStateWhenReady() {
        function doPatch() {
            if (typeof window.renderGameState === 'function' && !window.renderGameState._roomInfoPatched) {
                var origRenderGameState = window.renderGameState;
                window.renderGameState = function(payload) {
                    origRenderGameState.call(this, payload);
                    if (payload && payload.players) window.updateRoomInfo(payload.players);
                };
                window.renderGameState._roomInfoPatched = true;
                // Initial info bar update
                if (window.gameState && window.gameState.players) {
                    window.updateRoomInfo(window.gameState.players);
                }
                // Also update after 1s in case of async join
                setTimeout(function() {
                    if (window.gameState && window.gameState.players) {
                        window.updateRoomInfo(window.gameState.players);
                    }
                }, 1000);
                console.log('[patchRenderGameStateWhenReady] Patch applied.');
            }
        }
        // Try immediately, then poll every 100ms until ready (max 3s)
        let waited = 0;
        const interval = setInterval(function() {
            doPatch();
            waited += 100;
            if (window.renderGameState && window.renderGameState._roomInfoPatched) {
                clearInterval(interval);
            } else if (waited > 3000) {
                clearInterval(interval);
                console.warn('[patchRenderGameStateWhenReady] Timed out waiting for renderGameState.');
            }
        }, 100);
    })();
    </script>
</body>
</html>
