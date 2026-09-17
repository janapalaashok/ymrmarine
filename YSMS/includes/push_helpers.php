<?php
/**
 * Web Push (PWA notifications). One subscription row per browser/device the
 * user enabled notifications on (a user can have several — phone + laptop).
 * Sending is best-effort and never blocks the caller: createNotification()
 * (includes/notifications.php) calls sendPushToUser() right after writing the
 * in-app notification, so every existing notification call site gets push
 * "for free" without being touched individually.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function ensurePushSubscriptionsTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `endpoint` varchar(500) NOT NULL,
            `p256dh` varchar(255) NOT NULL,
            `auth` varchar(255) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_push_endpoint` (`endpoint`(255)),
            KEY `idx_push_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensurePushSubscriptionsTable: ' . $e->getMessage());
    }
}

function savePushSubscription(PDO $db, int $userId, string $endpoint, string $p256dh, string $auth): bool {
    if ($userId <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') return false;
    try {
        ensurePushSubscriptionsTable($db);
        $stmt = $db->prepare("
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)
        ");
        return $stmt->execute([$userId, $endpoint, $p256dh, $auth]);
    } catch (Throwable $e) {
        error_log('savePushSubscription: ' . $e->getMessage());
        return false;
    }
}

function removePushSubscription(PDO $db, string $endpoint): void {
    try {
        ensurePushSubscriptionsTable($db);
        $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
    } catch (Throwable $e) {
        error_log('removePushSubscription: ' . $e->getMessage());
    }
}

/**
 * Push a notification to every device the given user has enabled. No-ops
 * quietly (no exception) if VAPID keys aren't configured or the user has no
 * subscriptions — this is always an enhancement on top of the in-app
 * notification, never a requirement for it.
 */
function sendPushToUser(PDO $db, int $userId, string $title, string $body = '', ?string $url = null): void {
    if ($userId <= 0 || trim(VAPID_PUBLIC_KEY) === '' || trim(VAPID_PRIVATE_KEY) === '') return;
    try {
        ensurePushSubscriptionsTable($db);
        $stmt = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($subs)) return;

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => VAPID_SUBJECT,
                'publicKey'  => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ]);
        $webPush->setReuseVAPIDHeaders(true); // same signature works for every subscription in this batch

        $payload = json_encode([
            'title' => mb_substr($title, 0, 120),
            'body'  => mb_substr($body, 0, 200),
            'url'   => $url ?: 'index.php',
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $s) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                // 404/410 = the browser dropped this subscription permanently — stop trying it.
                if (in_array($code, [404, 410], true)) {
                    removePushSubscription($db, $report->getEndpoint());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('sendPushToUser: ' . $e->getMessage());
    }
}
