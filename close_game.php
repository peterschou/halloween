<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST allowed']);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scarer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$instanceId = isset($input['instance_id']) ? (int)$input['instance_id'] : 0;
if ($instanceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid instance_id']);
    exit;
}

$pdo = createPDO($DB_CONFIG);
$stmt = $pdo->prepare('UPDATE game_instances SET status = "closed" WHERE id = :id AND host_user_id = :uid');
$stmt->execute([
    ':id' => $instanceId,
    ':uid' => $_SESSION['user_id'],
]);
if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Game not found or not your game']);
}
