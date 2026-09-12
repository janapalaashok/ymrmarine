<?php
/**
 * Assign Vessel form: before actually submitting, check whether a vessel
 * with this same name already has an entry in Pending Vessels — so the
 * form can show "Are you sure you want to add this?" with the existing
 * entry's details instead of silently creating a second one. This is a
 * warning, not a hard block: the caller can still confirm and proceed
 * (a vessel legitimately gets surveyed more than once).
 *
 * GET so checkAuth()'s CSRF check (POST-only) doesn't apply — this is a
 * read-only lookup, same as ajax/recovery_details.php.
 */
require_once __DIR__ . '/../config/config.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Client'], true)) {
    http_response_code(403);
    echo json_encode(['exists' => false, 'error' => 'Not authorized.']);
    exit;
}

$db = getDB();
$role = $_SESSION['role'] ?? '';
$vesselNameRaw = trim((string)($_GET['vessel_name'] ?? ''));

if ($vesselNameRaw === '') {
    echo json_encode(['exists' => false]);
    exit;
}

// Same normalization assign_vessel.php applies before saving (uppercased,
// "MV. " prefixed), so "Pacific Dawn" / "mv pacific dawn" / "MV. PACIFIC
// DAWN" etc. all match whatever form is actually stored.
$normalized = normalizeVesselName($vesselNameRaw);

try {
    $sql = "
        SELECT s.id, s.report_number, s.vessel_name, s.assign_date, c.company_name, p.port_name
        FROM surveys s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Pending Vessel' AND s.vessel_name = ?
    ";
    $params = [$normalized];
    // A Client only sees a duplicate warning against their own company's
    // pending vessels — not another company's report number/client name,
    // matching how Client is scoped everywhere else in this app.
    if ($role === 'Client') {
        $sql .= " AND s.client_id = ?";
        $params[] = getClientIdForUser($db, (int)($_SESSION['user_id'] ?? 0));
    }
    $sql .= " ORDER BY s.id DESC LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('check_pending_vessel_duplicate: ' . $e->getMessage());
    echo json_encode(['exists' => false]);
    exit;
}

if (!$row) {
    echo json_encode(['exists' => false]);
    exit;
}

echo json_encode([
    'exists' => true,
    'entry' => [
        'vessel_name'   => $row['vessel_name'],
        'report_number' => $row['report_number'] ?: 'N/A',
        'client_name'   => $row['company_name'] ?: 'N/A',
        'port_name'     => $row['port_name'] ?: 'N/A',
        'assign_date'   => !empty($row['assign_date']) ? date('d M Y', strtotime($row['assign_date'])) : 'N/A',
    ],
]);
