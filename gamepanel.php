<?php
session_start();
require_once __DIR__ . '/config.php';

$isScarer = isset($_SESSION['user_id']) && $_SESSION['role'] === 'scarer';
$scarerName = $_SESSION['username'] ?? '';
$roomId = $_GET['room'] ?? '';
$instanceId = $_GET['instance'] ?? '';

$scarerAbilitySounds = [];
foreach (['ability1', 'ability2', 'ability3', 'ability4'] as $ability) {
    // Collect success sounds directly in the ability folder
    $scarerAbilitySounds[$ability] = [];
    $dir = __DIR__ . "/assets/sound_effects/scarer/{$ability}";
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.{mp3,wav}', GLOB_BRACE) as $filePath) {
            $scarerAbilitySounds[$ability][] = 'assets/sound_effects/scarer/' . $ability . '/' . basename($filePath);
        }
        sort($scarerAbilitySounds[$ability], SORT_NATURAL);

        // Collect misfire sounds from the /misfire subfolder
        $misfireKey = $ability . '_misfire';
        $scarerAbilitySounds[$misfireKey] = [];
        $misfireDir = $dir . '/misfire';
        if (is_dir($misfireDir)) {
            foreach (glob($misfireDir . '/*.{mp3,wav}', GLOB_BRACE) as $filePath) {
                $scarerAbilitySounds[$misfireKey][] = 'assets/sound_effects/scarer/' . $ability . '/misfire/' . basename($filePath);
            }
            sort($scarerAbilitySounds[$misfireKey], SORT_NATURAL);
        }
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
        .player-avatar.frozen { filter: grayscale(1) brightness(0.7); }
        /* Guardian Light Aura */
        .player-avatar.has-aura::before {
            content: '';
            position: absolute;
            width: 120px; height: 120px;
            background: radial-gradient(circle, rgba(250, 204, 21, 0.4) 0%, rgba(250, 204, 21, 0) 70%);
            border: 2px solid rgba(250, 204, 21, 0.3);
            border-radius: 50%;
            animation: pulse-aura 2s infinite;
        }
        /* Panic Visual Effect */
        .player-avatar.panic {
            border: 2px solid #ef4444;
            box-shadow: 0 0 15px #ef4444;
            animation: jitter 0.1s infinite;
        }
        @keyframes jitter {
            0% { transform: translate(-50%, -50%) translate(-1px, 1px); }
            50% { transform: translate(-50%, -50%) translate(1px, -1px); }
            100% { transform: translate(-50%, -50%) translate(-1px, 1px); }
        }
        @keyframes pulse-aura { 0% { transform: scale(0.9); opacity: 0.5; } 50% { transform: scale(1.1); opacity: 0.8; } 100% { transform: scale(0.9); opacity: 0.5; } }
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
        #debugPanel { display: none; margin-top: 24px; }
        #debugPanel.open { display: block; }

        /* New styles for compact layout */
        html, body { height: 100%; overflow: hidden; }
        .page.game-container { display: flex; flex-direction: column; height: 100vh; max-width: 100%; padding: 0; }
        .main-game-card { flex: 1; display: flex; flex-direction: column; border-radius: 0; margin: 0; border: none; }
        #gamePath { flex: 1; min-height: 100px; margin-top: 10px; }
        
        #gameControls {
            display: flex; align-items: center; justify-content: flex-start; gap: 15px;
            padding: 15px; background: #13162a; border-top: 1px solid #2a2d46;
            overflow-x: auto; -webkit-overflow-scrolling: touch;
        }
        
        .dpad-container { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 120px; }
        .dpad-row { display: flex; gap: 4px; }
        .dpad-btn { width: 42px; height: 42px; font-size: 1.2rem; border-radius: 8px; border: none; background: #23244a; color: #fff; cursor: pointer; }
        
        .ability-group { display: flex; gap: 10px; align-items: flex-end; }
        .ability-slot { display: flex; flex-direction: column; align-items: center; }
        .key-hint { font-size: 0.7rem; color: #a5b4fc; font-weight: 800; margin-bottom: 2px; text-transform: uppercase; }
        .ability-btn {
            position: relative;
            width: 46px; height: 46px; border-radius: 10px;
            background: #4f46e5; color: #fff; border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
            transition: transform 0.1s, filter 0.2s, opacity 0.2s;
        }
        .ability-btn:active { transform: scale(0.95); }
        .ability-btn.cooldown { filter: grayscale(1); opacity: 0.5; cursor: not-allowed; }
        .ability-btn .cd-label {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.6); border-radius: 10px;
            font-size: 0.9rem; font-weight: bold; color: #fff;
            opacity: 0; pointer-events: none; transition: opacity 0.2s;
        }
        .ability-btn.cooldown .cd-label { opacity: 1; }
        #walker-btn-q { background: #facc15; color: #111; }
        #scareEffectButtons { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        #scareEffectButtons button { padding: 10px 14px; font-size: 0.85rem; border-radius: 8px; background: #dc2626; color: #fff; border: none; cursor: pointer; }
        #scareEffectButtons button { background: #dc2626; }
        #closeGameBtn { background: #7f1d1d !important; }

        .game-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 20px; background: #101220; }
        .game-header h1 { margin: 0; font-size: 1.2rem; color: #a5b4fc; }
        
        #peerList, #debugLog { font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="page game-container">
        <header class="game-header">
            <h1>Room: <?= htmlspecialchars($roomId) ?></h1>
            <div>
                <button id="muteToggle" onclick="window.toggleMute()" style="background:#374151;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;">🔊</button>
                <button type="button" onclick="if(confirm('Leave game?')) window.location.href='lobby.php'" style="background:#374151;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;margin-left:4px;">Leave</button>
                <?php if ($isScarer): ?>
                <button type="button" id="closeGameBtn" style="background:#7f1d1d;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;margin-left:4px;">Close Game</button>
                <?php endif; ?>
            </div>
        </header>

        <section class="card main-game-card">
            <audio id="booSound" src="" preload="auto"></audio>
            <?php if ($isScarer): ?>
            <div id="soulCounterBar" style="margin:10px 20px 0;padding:4px 12px;background:#23244a;border-radius:6px;display:inline-block;font-size:0.9rem;align-self:flex-start;">
                <span>Souls: <span id="soulCounter">0</span></span>
            </div>
            <?php endif; ?>
            
            <div id="gamePath">
                <div id="pathTrack"></div>
                <div id="playerLayer"></div>
                <div id="scareOverlay"></div>
            </div>

            <div id="gameControls">
                <div class="dpad-container">
                    <button class="dpad-btn" data-dir="up">▲</button>
                    <div class="dpad-row">
                        <button class="dpad-btn" data-dir="left">◀</button>
                        <button class="dpad-btn" data-dir="down">▼</button>
                        <button class="dpad-btn" data-dir="right">▶</button>
                    </div>
                </div>

                <?php if ($isScarer): ?>
                <div id="abilityButtons" class="ability-group">
                    <div class="ability-slot">
                        <div class="key-hint">Q</div>
                        <button type="button" class="ability-btn" id="scarer-btn-q" onclick="window.triggerScarerAbility('q')">
                            👻
                            <span class="cd-label"></span>
                        </button>
                    </div>
                    <div class="ability-slot">
                        <div class="key-hint">W</div>
                        <button type="button" class="ability-btn" id="scarer-btn-w" onclick="window.triggerScarerAbility('w')">
                            💀
                            <span class="cd-label"></span>
                        </button>
                    </div>
                    <div class="ability-slot">
                        <div class="key-hint">E</div>
                        <button type="button" class="ability-btn" id="scarer-btn-e" onclick="window.triggerScarerAbility('e')">
                            ❄️
                            <span class="cd-label"></span>
                        </button>
                    </div>
                    <div class="ability-slot">
                        <div class="key-hint">R</div>
                        <button type="button" class="ability-btn" id="scarer-btn-r" onclick="window.triggerScarerAbility('r')">
                            ⚡
                            <span class="cd-label"></span>
                        </button>
                    </div>
                </div>
                <div id="scareEffectButtons">
                    <button type="button" onclick="sendScare('Spectral Chill', 'ability1')">Chill</button>
                    <button type="button" onclick="sendScare('Flashbang Fear', 'ability1')">Flash</button>
                    <button type="button" onclick="sendScare('Ghostly Whisper', 'ability1')">Whisper</button>
                </div>
                <?php endif; ?>

                <?php if (!$isScarer): ?>
                <div id="walkerAbilityButtons" class="ability-group">
                    <div class="ability-slot">
                        <div class="key-hint">Q</div>
                        <button type="button" class="ability-btn" id="walker-btn-q" onclick="window.activateWalkerAbility('q')">
                            🛡️
                            <span class="cd-label"></span>
                        </button>
                    </div>
                    <div class="ability-slot">
                        <div class="key-hint">W</div>
                        <button type="button" class="ability-btn" id="walker-btn-w" onclick="window.activateWalkerAbility('w')">
                            🏃
                            <span class="cd-label"></span>
                        </button>
                    </div>
                    <div class="ability-slot">
                        <div class="key-hint">E</div>
                        <button type="button" class="ability-btn" id="walker-btn-e" onclick="window.activateWalkerAbility('e')">
                            👁️
                            <span class="cd-label"></span>
                        </button>
                    </div>
                    <div class="ability-slot">
                        <div class="key-hint">R</div>
                        <button type="button" class="ability-btn" id="walker-btn-r" onclick="window.activateWalkerAbility('r')">
                            🕯️
                            <span class="cd-label"></span>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div id="roomInfoBar" style="padding: 0 10px;"></div>
            <p class="status" id="statusMessage" style="font-size: 0.8rem; padding: 0 10px;">Initializing...</p>
        </section>

        <footer style="padding: 10px; background: #090913;">
            <div id="connectionBadge" class="status-badge offline" style="font-size: 0.7rem;">Offline</div>
            <button class="debug-toggle" onclick="document.getElementById('debugPanel').classList.toggle('open')" style="font-size: 0.8rem; margin-left: 10px;">Debug</button>
            <div id="debugPanel">
                <pre id="debugLog" style="white-space: pre-wrap; background: rgba(12, 14, 28, 0.9); border: 1px solid #262a45; color: #c6d9ff; padding: 8px; border-radius: 8px; max-height: 100px; overflow:auto; font-size:0.7rem;">Debug log ready.</pre>
            </div>
        </footer>
    </div>
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
        border: none;
        color: #e5e7eb;
        font-size: 0.8rem;
    }
    #roomInfoTable th, #roomInfoTable td {
        padding: 6px 12px;
        text-align: left;
        vertical-align: top;
    }
    #roomInfoTable th {
        background: #23244a;
        font-weight: normal;
        color: #9ca3af;
    }
    </style>
    <script>
    // D-pad movement for touch devices
    function dpadMove(dir) {
        if (typeof window.updateMovementDir === 'function') {
            window.updateMovementDir(dir);
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.dpad-btn').forEach(function(btn) {
            const handle = (e) => {
                e.preventDefault();
                dpadMove(btn.dataset.dir);
            };
            btn.addEventListener('touchstart', handle, {passive: false});
            btn.addEventListener('mousedown', handle);
        });
        // Initial hide for debug panel
        const dp = document.getElementById('debugPanel');
        if (dp) dp.classList.remove('open');
    });

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

    // Close Game button logic (moved from original host section)
    document.addEventListener('DOMContentLoaded', function() {
        var closeBtn = document.getElementById('closeGameBtn');
        if (closeBtn) {
            closeBtn.onclick = async function() {
                if (!window.GAME_INSTANCE_ID) { alert('No game instance.'); return; }
                if (!confirm('Are you sure you want to close this game?')) return;
                if (window.notifyPeersGameClosed) window.notifyPeersGameClosed();
                closeBtn.disabled = true;
                closeBtn.textContent = 'Closing...';
                try {
                    const resp = await fetch('close_game.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ instance_id: window.GAME_INSTANCE_ID })
                    });
                    const data = await resp.json();
                    if (data.success) { window.location.href = 'lobby.php'; }
                    else { alert('Failed to close: ' + (data.error || 'Unknown error')); }
                } catch (e) { alert('Error: ' + (e.message || e)); }
                finally { closeBtn.disabled = false; closeBtn.textContent = 'Close Game'; }
            };
        }
    });
    </script>
</body>
</html>
