<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/report_number.php';
checkAuth();
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$company_name = trim($_POST['company_name'] ?? '');

if ($company_name === '') {
    echo json_encode(['success' => false, 'message' => 'Client name is required.']);
    exit;
}

$db = getDB();

try {
    ensureClientShortCodeColumn($db);
    $chk = $db->prepare("SELECT id, company_name, short_code FROM clients WHERE company_name = ?");
    $chk->execute([$company_name]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $sid = (int)$existing['id'];
        $code = trim((string)($existing['short_code'] ?? ''));
        if ($code === '') {
            $code = ensureClientHasShortCode($db, $sid);
        }
        echo json_encode(['success' => true, 'id' => $sid, 'name' => $existing['company_name'], 'short_code' => $code, 'existed' => true]);
        exit;
    }

    $code = allocateClientShortCode($db, $company_name);
    $ins = $db->prepare("INSERT INTO clients (company_name, short_code) VALUES (?, ?)");
    try {
        $ins->execute([$company_name, $code]);
    } catch (Throwable $e) {
        // short_code column may be missing briefly
        $ins = $db->prepare("INSERT INTO clients (company_name) VALUES (?)");
        $ins->execute([$company_name]);
        $new_id = (int)$db->lastInsertId();
        $code = ensureClientHasShortCode($db, $new_id);
        echo json_encode(['success' => true, 'id' => $new_id, 'name' => $company_name, 'short_code' => $code, 'existed' => false]);
        exit;
    }
    $new_id = (int)$db->lastInsertId();

    echo json_encode(['success' => true, 'id' => $new_id, 'name' => $company_name, 'short_code' => $code, 'existed' => false]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error while saving client.']);
}
