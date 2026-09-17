<?php
/** Saves a browser's Web Push subscription for the logged-in user. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/push_helpers.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

$endpoint = trim((string)($_POST['endpoint'] ?? ''));
$p256dh = trim((string)($_POST['p256dh'] ?? ''));
$auth = trim((string)($_POST['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid subscription.']);
    exit;
}

$db = getDB();
$ok = savePushSubscription($db, (int)$_SESSION['user_id'], $endpoint, $p256dh, $auth);
echo json_encode(['success' => $ok]);
