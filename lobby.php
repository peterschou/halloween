<?php
session_start();
require_once __DIR__ . '/config.php';

$pdo = createPDO($DB_CONFIG);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $message = 'Please fill in both username and password.';
        } else {
            $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash']) && $user['role'] === 'scarer') {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                header('Location: lobby.php');
                exit;
            }
            $message = 'Invalid credentials or unauthorized role.';
        }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        session_destroy();
        header('Location: lobby.php');
        exit;
    }
}

$isScarer = isset($_SESSION['user_id']) && $_SESSION['role'] === 'scarer';
$scarerName = $_SESSION['username'] ?? '';
$scarerId = $_SESSION['user_id'] ?? 0;

$rooms = [];
$stmt = $pdo->prepare('SELECT gi.id, gi.room_id, gi.status, u.username AS host_username, gi.created_at FROM game_instances gi JOIN users u ON u.id = gi.host_user_id WHERE gi.status IN ("waiting","active") ORDER BY gi.created_at DESC LIMIT 50');
$stmt->execute();
$rooms = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_TITLE) ?> Lobby</title>
    <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #090913; color: #f5f5f7; }
        .page { max-width: 1100px; margin: 0 auto; padding: 24px; }
        h1, h2 { margin: .4em 0; }
        .card { background: rgba(16, 18, 32, .94); border: 1px solid #2a2d46; border-radius: 18px; padding: 20px; margin-bottom: 16px; }
        button, input { font: inherit; }
        input { width: 100%; padding: 12px; border-radius: 12px; border: 1px solid #3b3f5d; background: #13162a; color: #eef; }
        button { padding: 12px 18px; border: none; border-radius: 12px; background: #7c3aed; color: #fff; cursor: pointer; }
        button:hover { background: #5b21b6; }
        .hidden { display: none; }
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
        .room-list { list-style: none; padding: 0; margin: 0; }
        .room-list li { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,.06); }
        .room-list button { flex-shrink: 0; }
    </style>
</head>
<body>
    <div class="page">
        <header class="card">
            <h1><?= htmlspecialchars(SITE_TITLE) ?></h1>
            <p>Host the haunted path as a Scarer or join as a Walker. Signaling is handled through PHP/MySQL, while gameplay runs P2P over WebRTC DataChannels.</p>
        </header>

        <?php if ($message): ?>
            <div class="card"><strong>Notice:</strong> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="card">
            <h2>Public Lobby</h2>
            <p>Choose an active room and connect directly to the Host.</p>
            <ul class="room-list">
                <?php foreach ($rooms as $room): ?>
                    <li>
                        <span><strong><?= htmlspecialchars($room['room_id']) ?></strong> by <?= htmlspecialchars($room['host_username']) ?> — <?= htmlspecialchars($room['status']) ?></span>
                        <button class="join-room" data-room-id="<?= htmlspecialchars($room['room_id']) ?>" data-instance-id="<?= (int)$room['id'] ?>">Join</button>
                        <?php if ($isScarer): ?>
                            <button class="close-room-btn" data-instance-id="<?= (int)$room['id'] ?>" style="margin-left:10px;background:#7f1d1d;color:#fff;">Close</button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.close-room-btn').forEach(function(btn) {
                    btn.onclick = async function() {
                        if (!confirm('Are you sure you want to close this game?')) return;
                        btn.disabled = true;
                        btn.textContent = 'Closing...';
                        try {
                            const resp = await fetch('close_game.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ instance_id: btn.dataset.instanceId })
                            });
                            const data = await resp.json();
                            if (data.success) {
                                window.location.reload();
                            } else {
                                alert('Failed to close: ' + (data.error || 'Unknown error'));
                            }
                        } catch (e) {
                            alert('Error: ' + (e.message || e));
                        } finally {
                            btn.disabled = false;
                            btn.textContent = 'Close';
                        }
                    };
                });
            });
            </script>
            <?php if (empty($rooms)): ?>
                <p>No active rooms found. Check back later.</p>
            <?php endif; ?>
            <div id="peerControls" class="card hidden" style="margin-top: 18px;">
                <h3>Walker Controls</h3>
                <p>Once you join, use arrow keys to move along the haunted pathway.</p>
                <p>Watch for real-time scare effects from the Host.</p>
            </div>
        </section>

        <?php if (!$isScarer): ?>
            <section class="card">
                <h2>Scarer Login</h2>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="login">
                    <label>Username<br><input name="username" required></label><br><br>
                    <label>Password<br><input type="password" name="password" required></label><br><br>
                    <button type="submit">Log in as Scarer</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($isScarer): ?>
            <section class="card">
                <h2>Create Room Instance</h2>
                <label>Room code <input id="roomInput" placeholder="spooky-room-007"></label>
                <button id="createRoomBtn">Create Instance</button>
            </section>
        <?php endif; ?>

        <!-- Gameplay panel moved to gamepanel.php -->
    </div>

    <script>
        window.SCARER_USER_ID = <?= $isScarer ? (int)$_SESSION['user_id'] : 0 ?>;
    </script>
    <script src="game.js"></script>
</body>
</html>
