<?php
/**
 * Assign Vessel form: as-you-type suggestions for the Vessel Name field,
 * drawn from vessel names already used in past surveys — so a repeat vessel
 * (the common case) can be picked instead of retyped, typo-free. Read-only,
 * so GET is fine (checkAuth() still applies for login).
 */
require_once __DIR__ . '/../config/config.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Client'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'names' => []]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'names' => []]);
    exit;
}

$db = getDB();
try {
    $stmt = $db->prepare("
        SELECT vessel_name, MAX(assign_date) AS last_used
        FROM surveys
        WHERE vessel_name LIKE ?
        GROUP BY vessel_name
        ORDER BY last_used DESC
        LIMIT 8
    ");
    $stmt->execute(['%' . $q . '%']);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('vessel_autocomplete.php: ' . $e->getMessage());
    $names = [];
}

echo json_encode(['success' => true, 'names' => $names]);
