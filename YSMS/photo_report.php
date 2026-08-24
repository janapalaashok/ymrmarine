<?php
require_once 'config/config.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// రోల్ బేస్డ్ డేటా క్వెరి - LEFT JOIN ద్వారా అప్‌డేట్ చేసిన యూజర్ వివరాలు తెచ్చుకుంటున్నాం
if ($role === 'Admin') {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, uu.full_name as modifier_name 
        FROM surveys s 
        JOIN clients c ON s.client_id = c.id 
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        WHERE s.status = 'Pending Vessel' 
        ORDER BY s.id DESC
    ");
    $stmt->execute();
} else {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, uu.full_name as modifier_name 
        FROM surveys s 
        JOIN clients c ON s.client_id = c.id 
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        WHERE s.status = 'Pending Vessel' AND s.surveyor_id = ? 
        ORDER BY s.id DESC
    ");
    $stmt->execute([$user_id]);
}
$surveys = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .vessel-live-status-bar { background: #f8fafc; border-top: 1px dashed var(--border-color); padding: 10px 15px; margin-top: 10px; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; font-size: 11.5px; }
    @media (max-width: 991px) {
        .vessel-card { flex-direction: column; align-items: stretch !important; padding: 0 !important; overflow: hidden; }
        .vessel-card-top-content { display: flex; justify-content: space-between; align-items: center; padding: 15px 15px 5px 15px; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Photo Report'; $back_url = 'index.php'; $page_testid = 'photo-report'; include 'includes/top_app_bar.php'; ?>
    <div class="bg-white p-3 d-flex align-items-center border-bottom">
        <h1 class="fw-bold m-0 text-center flex-1" style="font-size: 18px; width: 100%;">Pending Vessels</h1>
    </div>

    <div class="search-filter-row">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="vesselSearchInput" class="search-control" placeholder="Search by vessel name...">
        </div>
        <button class="filter-btn"><i class="fa-solid fa-sliders"></i></button>
    </div>

    <div class="vessel-list-container" id="vesselListContainer">
        <?php if (!empty($surveys) && count($surveys) > 0): ?>
            
            <?php foreach ($surveys as $survey): ?>
                <?php 
                    $vessel_name = !empty($survey['vessel_name']) ? $survey['vessel_name'] : 'Vessel';
                    $first_letter = strtoupper(substr($vessel_name, 0, 1));
                    
                    // 1970 ఎర్రర్ రాకుండా పక్కా డేట్ వాలిడేషన్
                    $display_date = '--/--/----';
                    if (!empty($survey['assign_date']) && $survey['assign_date'] != '0000-00-00 00:00:00' && $survey['assign_date'] != '0000-00-00') {
                        $display_date = date('d M Y', strtotime($survey['assign_date']));
                    } elseif (!empty($survey['created_at'])) {
                        $display_date = date('d M Y', strtotime($survey['created_at']));
                    }
                ?>
                <div class="vessel-card" onclick="location.href='vessel_detail.php?id=<?= $survey['id'] ?>'">
                    <div class="vessel-card-top-content">
                        <div class="vessel-avatar-info">
                            <div class="vessel-initial-avatar"><?= $first_letter ?></div>
                            <div>
                                <h4 class="vessel-name-title"><?= sanitize($vessel_name) ?></h4>
                                <p class="vessel-client-sub">Client: <?= sanitize($survey['company_name']) ?></p>
                            </div>
                        </div>
                        <div class="vessel-badge-date">
                            <span class="badge-status badge-assigned">Assigned</span>
                            <div class="badge-date-text"><?= $display_date ?></div>
                        </div>
                    </div>

                    <div class="vessel-live-status-bar">
                        <?php if (!empty($survey['custom_live_status'])): ?>
                            <div class="text-dark fw-semibold mb-1" style="line-height: 1.3;">
                                <i class="fa-solid fa-comment-dots text-primary me-1"></i> <?= sanitize($survey['custom_live_status']) ?>
                            </div>
                            <div class="text-muted" style="font-size: 10px;">
                                Updated: <span class="fw-bold"><?= date('d M Y - h:i A', strtotime($survey['status_updated_at'])) ?></span> 
                                by <span class="text-primary fw-bold"><?= !empty($survey['modifier_name']) ? sanitize($survey['modifier_name']) : 'User' ?></span>
                            </div>
                        <?php else: ?>
                            <div class="text-muted italic" style="font-size: 11px;">
                                <i class="fa-solid fa-info-circle text-warning"></i> No live status updates recorded yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
        <?php else: ?>
            <div class="text-center py-5 px-3 mx-3 mt-4 bg-white rounded-4 border border-dashed shadow-sm">
                <h5>No Pending Vessels</h5>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>