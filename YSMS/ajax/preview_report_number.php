<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/report_number.php';
checkAuth();
header('Content-Type: application/json');

$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
if ($clientId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Select a client first.', 'report_number' => '']);
    exit;
}

try {
    $db = getDB();
    $rn = generateNextReportNumberForClient($db, $clientId);
    $short = ensureClientHasShortCode($db, $clientId);
    echo json_encode([
        'success' => true,
        'report_number' => $rn,
        'short_code' => $short,
        'format' => 'YMR/{CLIENT}/{YYYY}/{MM}/{NNNN}',
    ]);
} catch (Throwable $e) {
    error_log('Report number preview error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not generate a preview number right now.', 'report_number' => '']);
}
