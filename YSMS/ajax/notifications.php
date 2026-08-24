<?php
/**
 * Notifications API
 * GET  ?action=list|count
 * POST action=read|read_all  (+ id for read)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/notifications.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = getDB();
ensureNotificationsTable($db);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'count') {
        echo json_encode(['ok' => true, 'count' => getUnreadNotificationCount($db, $userId)]);
        exit;
    }

    if ($action === 'list') {
        $items = getNotifications($db, $userId, 40);
        $out = [];
        foreach ($items as $n) {
            $out[] = [
                'id' => (int)$n['id'],
                'type' => $n['type'],
                'title' => $n['title'],
                'message' => $n['message'] ?? '',
                'link' => $n['link'] ?? '',
                'is_read' => (int)$n['is_read'] === 1,
                'created_at' => $n['created_at'],
                'time_ago' => notificationTimeAgo($n['created_at']),
                'icon' => notificationIcon($n['type'] ?? 'info'),
            ];
        }
        echo json_encode([
            'ok' => true,
            'count' => getUnreadNotificationCount($db, $userId),
            'items' => $out,
        ]);
        exit;
    }

    if ($action === 'read') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        markNotificationRead($db, $userId, $id);
        echo json_encode(['ok' => true, 'count' => getUnreadNotificationCount($db, $userId)]);
        exit;
    }

    if ($action === 'read_all') {
        markAllNotificationsRead($db, $userId);
        echo json_encode(['ok' => true, 'count' => 0]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
