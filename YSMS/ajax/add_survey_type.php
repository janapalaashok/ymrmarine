<?php
require_once __DIR__ . '/../config/config.php';
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

$type_name = trim($_POST['type_name'] ?? '');

if ($type_name === '') {
    echo json_encode(['success' => false, 'message' => 'Survey type name is required.']);
    exit;
}

$db = getDB();

try {
    // ఆల్రెడీ ఉందేమో చెక్ చేయడం (డూప్లికేట్ సర్వే టైప్స్ ఆపడానికి)
    $chk = $db->prepare("SELECT id, type_name FROM survey_types WHERE type_name = ?");
    $chk->execute([$type_name]);
    $existing = $chk->fetch();

    if ($existing) {
        echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'name' => $existing['type_name'], 'existed' => true]);
        exit;
    }

    $ins = $db->prepare("INSERT INTO survey_types (type_name) VALUES (?)");
    $ins->execute([$type_name]);
    $new_id = $db->lastInsertId();

    echo json_encode(['success' => true, 'id' => (int)$new_id, 'name' => $type_name, 'existed' => false]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error while saving survey type.']);
}
