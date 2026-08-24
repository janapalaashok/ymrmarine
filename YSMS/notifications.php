<?php
/**
 * In-app notification helpers
 */

function ensureNotificationsTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `type` VARCHAR(50) NOT NULL DEFAULT 'info',
          `title` VARCHAR(255) NOT NULL,
          `message` TEXT NULL,
          `link` VARCHAR(500) DEFAULT NULL,
          `is_read` TINYINT(1) NOT NULL DEFAULT 0,
          `created_by` INT(11) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_notif_user_read` (`user_id`, `is_read`),
          KEY `idx_notif_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureNotificationsTable: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Create a notification for one user.
 */
function createNotification(PDO $db, int $userId, string $title, string $message = '', string $type = 'info', ?string $link = null, ?int $createdBy = null): bool {
    if ($userId <= 0) return false;
    try {
        ensureNotificationsTable($db);
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $userId,
            substr($type, 0, 50),
            substr($title, 0, 255),
            $message,
            $link,
            $createdBy,
        ]);
    } catch (Throwable $e) {
        error_log('createNotification: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify all Active Admin users (role_id = 1).
 */
function notifyAllAdmins(PDO $db, string $title, string $message = '', string $type = 'info', ?string $link = null, ?int $createdBy = null): int {
    $count = 0;
    try {
        ensureNotificationsTable($db);
        $rows = $db->query("SELECT id FROM users WHERE role_id = 1 AND status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $uid) {
            $uid = (int)$uid;
            if ($createdBy && $uid === $createdBy) continue; // don't notify self
            if (createNotification($db, $uid, $title, $message, $type, $link, $createdBy)) {
                $count++;
            }
        }
    } catch (Throwable $e) {
        error_log('notifyAllAdmins: ' . $e->getMessage());
    }
    return $count;
}

/**
 * Notify all Active Surveyors (role_id = 2).
 */
function notifyAllSurveyors(PDO $db, string $title, string $message = '', string $type = 'info', ?string $link = null, ?int $createdBy = null): int {
    $count = 0;
    try {
        ensureNotificationsTable($db);
        $rows = $db->query("SELECT id FROM users WHERE role_id = 2 AND status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $uid) {
            $uid = (int)$uid;
            if ($createdBy && $uid === $createdBy) continue;
            if (createNotification($db, $uid, $title, $message, $type, $link, $createdBy)) {
                $count++;
            }
        }
    } catch (Throwable $e) {
        error_log('notifyAllSurveyors: ' . $e->getMessage());
    }
    return $count;
}

function getUnreadNotificationCount(PDO $db, int $userId): int {
    try {
        ensureNotificationsTable($db);
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function getNotifications(PDO $db, int $userId, int $limit = 30): array {
    try {
        ensureNotificationsTable($db);
        $limit = max(1, min(100, $limit));
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function markNotificationRead(PDO $db, int $userId, int $notifId): bool {
    try {
        ensureNotificationsTable($db);
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notifId, $userId]);
    } catch (Throwable $e) {
        return false;
    }
}

function markAllNotificationsRead(PDO $db, int $userId): bool {
    try {
        ensureNotificationsTable($db);
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$userId]);
    } catch (Throwable $e) {
        return false;
    }
}

function notificationTimeAgo(?string $datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return (int)floor($diff / 60) . 'm ago';
    if ($diff < 86400) return (int)floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return (int)floor($diff / 86400) . 'd ago';
    return date('d M Y', $ts);
}

function notificationIcon(string $type): string {
    $map = [
        'assign'   => 'fa-ship',
        'edit'     => 'fa-pen',
        'cancel'   => 'fa-ban',
        'delete'   => 'fa-trash',
        'format'   => 'fa-file-excel',
        'surveyor' => 'fa-user-plus',
        'status'   => 'fa-comment-dots',
        'card'     => 'fa-id-card',
        'upload'   => 'fa-cloud-arrow-up',
        'report'   => 'fa-file-lines',
        'expense'  => 'fa-receipt',
        'info'     => 'fa-bell',
    ];
    return $map[$type] ?? 'fa-bell';
}
