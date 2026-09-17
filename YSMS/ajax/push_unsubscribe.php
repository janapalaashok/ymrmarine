<?php
/** Removes a browser's Web Push subscription (called when the user turns notifications off). */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/push_helpers.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

$endpoint = trim((string)($_POST['endpoint'] ?? ''));
if ($endpoint === '') {
    echo json_encode(['success' => false]);
    exit;
}

$db = getDB();
// Scope the delete to this user's own row — never let one user delete another's subscription.
$stmt = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
$ok = $stmt->execute([$endpoint, (int)$_SESSION['user_id']]);
echo json_encode(['success' => $ok]);
