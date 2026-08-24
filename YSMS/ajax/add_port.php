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

$port_name = trim($_POST['port_name'] ?? '');

if ($port_name === '') {
    echo json_encode(['success' => false, 'message' => 'Port name is required.']);
    exit;
}

$db = getDB();

try {
    // ఆల్రెడీ ఉందేమో చెక్ చేయడం (డూప్లికేట్ పోర్ట్స్ ఆపడానికి)
    $chk = $db->prepare("SELECT id, port_name FROM ports WHERE port_name = ?");
    $chk->execute([$port_name]);
    $existing = $chk->fetch();

    if ($existing) {
        echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'name' => $existing['port_name'], 'existed' => true]);
        exit;
    }

    $ins = $db->prepare("INSERT INTO ports (port_name) VALUES (?)");
    $ins->execute([$port_name]);
    $new_id = $db->lastInsertId();

    echo json_encode(['success' => true, 'id' => (int)$new_id, 'name' => $port_name, 'existed' => false]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error while saving port.']);
}
