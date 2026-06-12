<?php
// signal.php
// Lightweight signaling endpoint for "The Scare Path" P2P WebRTC lobby.
// This endpoint only stores and retrieves SDP/ICE exchange data in MySQL.

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/db_credentials.php')) {
    http_response_code(503);
    exit(json_encode(['success' => false, 'error' => 'Application not configured.']));
}

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Only POST requests are supported.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonError('Invalid JSON payload.', 400);
}

$action = $input['action'] ?? '';
if ($action === '') {
    jsonError('Missing action parameter.', 400);
}

$pdo = createPDO($DB_CONFIG);

switch ($action) {
    case 'create_room':
        createRoom($pdo, $input);
        break;
    case 'list_rooms':
        listRooms($pdo);
        break;
    case 'send_signal':
        sendSignal($pdo, $input);
        break;
    case 'poll_signals':
        pollSignals($pdo, $input);
        break;
    case 'consume_signals':
        consumeSignals($pdo, $input);
        break;
    default:
        jsonError('Unknown action: ' . $action, 400);
}

function createRoom(PDO $pdo, array $input): void
{
    $roomId = trim((string)($input['room_id'] ?? ''));
    $hostUserId = isset($input['host_user_id']) ? (int)$input['host_user_id'] : 0;
    if ($roomId === '' || $hostUserId <= 0) {
        jsonError('Invalid room_id or host_user_id.', 400);
    }

    $stmt = $pdo->prepare("INSERT INTO `{$GLOBALS['DB_CONFIG']['prefix']}game_instances` (room_id, host_user_id, status) VALUES (:room_id, :host_user_id, :status)");
    try {
        $stmt->execute([
            ':room_id' => $roomId,
            ':host_user_id' => $hostUserId,
            ':status' => 'waiting',
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('Room ID already exists.', 409);
        }
        jsonError('Database error creating room.', 500);
    }

    jsonResponse(['success' => true, 'room_id' => $roomId, 'instance_id' => (int)$pdo->lastInsertId()]);
}

function listRooms(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT gi.room_id, gi.status, u.username AS host_username, gi.created_at
         FROM `{$GLOBALS['DB_CONFIG']['prefix']}game_instances` gi
         JOIN `{$GLOBALS['DB_CONFIG']['prefix']}users` u ON u.id = gi.host_user_id
         WHERE gi.status IN ('waiting','active')
         ORDER BY gi.created_at DESC
         LIMIT 50"
    );
    $stmt->execute();
    $rooms = $stmt->fetchAll();

    jsonResponse(['success' => true, 'rooms' => $rooms]);
}

function sendSignal(PDO $pdo, array $input): void
{
    $instanceId = isset($input['instance_id']) ? (int)$input['instance_id'] : 0;
    $fromPeer = trim((string)($input['from_peer'] ?? ''));
    $toPeer = trim((string)($input['to_peer'] ?? ''));
    $kind = trim((string)($input['kind'] ?? ''));
    $payload = $input['payload'] ?? null;

    if ($instanceId <= 0 || $fromPeer === '' || $toPeer === '' || !in_array($kind, ['offer', 'answer', 'ice'], true) || $payload === null) {
        jsonError('Invalid signal payload. Required fields: instance_id, from_peer, to_peer, kind, payload.', 400);
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        jsonError('Failed to encode payload as JSON.', 400);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO `{$GLOBALS['DB_CONFIG']['prefix']}signaling_queue` (instance_id, from_peer, to_peer, kind, payload) VALUES (:instance_id, :from_peer, :to_peer, :kind, :payload)"
    );
    $stmt->execute([
        ':instance_id' => $instanceId,
        ':from_peer' => $fromPeer,
        ':to_peer' => $toPeer,
        ':kind' => $kind,
        ':payload' => $payloadJson,
    ]);

    jsonResponse(['success' => true, 'queued_id' => (int)$pdo->lastInsertId()]);
}

function pollSignals(PDO $pdo, array $input): void
{
    $instanceId = isset($input['instance_id']) ? (int)$input['instance_id'] : 0;
    $targetPeer = trim((string)($input['target_peer'] ?? ''));
    $kindFilter = isset($input['kind']) ? trim((string)$input['kind']) : null;

    if ($instanceId <= 0 || $targetPeer === '') {
        jsonError('Invalid poll request. Required fields: instance_id and target_peer.', 400);
    }

    $query = "SELECT id, instance_id, from_peer, to_peer, kind, payload, created_at FROM `{$GLOBALS['DB_CONFIG']['prefix']}signaling_queue` WHERE instance_id = :instance_id AND to_peer = :to_peer AND consumed_at IS NULL";
    if ($kindFilter !== null && in_array($kindFilter, ['offer', 'answer', 'ice'], true)) {
        $query .= ' AND kind = :kind';
    }
    $query .= ' ORDER BY created_at ASC LIMIT 25';

    $stmt = $pdo->prepare($query);
    $params = [
        ':instance_id' => $instanceId,
        ':to_peer' => $targetPeer,
    ];
    if ($kindFilter !== null && in_array($kindFilter, ['offer', 'answer', 'ice'], true)) {
        $params[':kind'] = $kindFilter;
    }

    $stmt->execute($params);
    $signals = $stmt->fetchAll();
    foreach ($signals as &$signal) {
        $signal['payload'] = json_decode((string)$signal['payload'], true);
    }

    jsonResponse(['success' => true, 'signals' => $signals]);
}

function consumeSignals(PDO $pdo, array $input): void
{
    $signalIds = $input['signal_ids'] ?? null;
    if (!is_array($signalIds) || count($signalIds) === 0) {
        jsonError('Invalid consume request. Provide signal_ids array.', 400);
    }

    $ids = array_filter(array_map('intval', $signalIds), static fn($id) => $id > 0);
    if (count($ids) === 0) {
        jsonError('No valid signal IDs found.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE `{$GLOBALS['DB_CONFIG']['prefix']}signaling_queue` SET consumed_at = NOW() WHERE id IN (" . $placeholders . ")");
    $stmt->execute($ids);

    jsonResponse(['success' => true, 'consumed' => $stmt->rowCount()]);
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $status = 400): void
{
    jsonResponse(['success' => false, 'error' => $message], $status);
}
