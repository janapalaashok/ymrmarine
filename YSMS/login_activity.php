<?php
require_once 'config/config.php';
checkAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
ensureLoginLogsTable($db);

$per_page = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$total = (int)$db->query("SELECT COUNT(*) FROM login_logs")->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

$stmt = $db->prepare("SELECT * FROM login_logs ORDER BY logged_in_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

include 'includes/header.php';
?>
<style>
    .la-page { padding: 20px 16px 110px; }
    .la-heading { font-size: 19px; font-weight: 750; color: var(--text-dark); margin: 0 0 4px; }
    .la-sub { font-size: 12.5px; color: var(--text-muted); margin: 0 0 16px; }
    .la-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 14px 15px; margin-bottom: 10px; }
    .la-row-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 4px; }
    .la-user { font-size: 14px; font-weight: 700; color: var(--text-dark); }
    .la-role { font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: #eef2ff; color: #4338ca; text-transform: uppercase; letter-spacing: .3px; }
    .la-meta { font-size: 12px; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 4px 14px; }
    .la-meta span i { width: 14px; opacity: .7; }
    .la-pagination { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 18px; font-size: 13px; }
    .la-pagination a, .la-pagination span.disabled { padding: 6px 14px; border-radius: 8px; background: #fff; border: 1px solid var(--border-color); color: var(--text-dark); text-decoration: none; }
    .la-pagination span.disabled { opacity: .4; }
</style>
<div class="scroll-content">
    <?php $page_title = 'Login Activity'; $back_url = 'index.php'; $page_testid = 'login-activity'; include 'includes/top_app_bar.php'; ?>
    <main class="la-page" data-testid="login-activity-page">
        <h2 class="la-heading">Login Activity</h2>
        <p class="la-sub"><?= (int)$total ?> total logins recorded</p>

        <?php if (empty($logs)): ?>
            <div class="text-center text-muted py-4">No login activity recorded yet.</div>
        <?php endif; ?>

        <?php foreach ($logs as $log): ?>
            <div class="la-card" data-testid="login-log-<?= (int)$log['id'] ?>">
                <div class="la-row-top">
                    <div class="la-user"><?= sanitize($log['username']) ?></div>
                    <?php if (!empty($log['role_name'])): ?>
                        <span class="la-role"><?= sanitize($log['role_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="la-meta">
                    <span><i class="fa-regular fa-clock"></i> <?= date('jS M Y, h:i A', strtotime($log['logged_in_at'])) ?></span>
                    <?php if (!empty($log['ip_address'])): ?>
                        <span><i class="fa-solid fa-globe"></i> <?= sanitize($log['ip_address']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($total_pages > 1): ?>
        <div class="la-pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" data-testid="login-activity-prev">&larr; Prev</a>
            <?php else: ?>
                <span class="disabled">&larr; Prev</span>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $total_pages ?></span>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>" data-testid="login-activity-next">Next &rarr;</a>
            <?php else: ?>
                <span class="disabled">Next &rarr;</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
