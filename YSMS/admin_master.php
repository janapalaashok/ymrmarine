<?php
require_once __DIR__ . '/../config/config.php';
checkAuth();
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$modules = require __DIR__ . '/../config/admin_modules.php';

$module = isset($_REQUEST['module']) ? trim((string)$_REQUEST['module']) : '';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'list';

if ($module === '' || !isset($modules[$module])) {
    echo json_encode(['success' => false, 'message' => 'Unknown module.']);
    exit;
}

$cfg = $modules[$module];
$table = $cfg['table'];
$idField = $cfg['id_field'];
$nameField = $cfg['name_field'];
$where = trim((string)($cfg['where'] ?? ''));
$orderBy = $cfg['order_by'] ?? $nameField;
$fields = $cfg['fields'];
$fkChecks = $cfg['fk_checks'] ?? [];

$db = getDB();

// Ensure clients address columns exist (safe if already present)
if ($module === 'clients') {
    try { $db->exec("ALTER TABLE clients ADD COLUMN address_line1 VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE clients ADD COLUMN address_line2 VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
}

/**
 * Builds "<where> AND ..." / "WHERE ..." safely depending on whether a
 * base module WHERE clause exists.
 */
function buildWhere(string $base, array $extra): string {
    $parts = [];
    if ($base !== '') $parts[] = $base;
    foreach ($extra as $p) $parts[] = $p;
    if (empty($parts)) return '';
    return 'WHERE ' . implode(' AND ', $parts);
}

try {
    if ($action === 'list') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $extra = [];
        $params = [];
        if ($q !== '') {
            $extra[] = "`$nameField` LIKE ?";
            $params[] = "%$q%";
        }
        $sql = "SELECT * FROM `$table` " . buildWhere($where, $extra) . " ORDER BY `$orderBy` ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        // 🌟 FETCH_ASSOC వాడటం తప్పనిసరి — డిఫాల్ట్ PDO FETCH_BOTH మోడ్‌లో numeric-index
        // కాపీ కూడా ఉంటుంది కాబట్టి, కింద unset($row['password']) చేసినా ఆ నంబర్ కీ ద్వారా
        // పాస్‌వర్డ్ హ్యాష్ ఇంకా క్లయింట్‌కి లీక్ అవుతుంది.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Never leak password hashes to the client.
        foreach ($rows as &$row) {
            if (isset($row['password'])) unset($row['password']);
        }
        unset($row);

        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }

    if ($action === 'add' || $action === 'edit') {
        $id = ($action === 'edit') ? (int)($_POST['id'] ?? 0) : 0;
        if ($action === 'edit' && $id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid record.']);
            exit;
        }

        $data = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            $val = trim((string)($_POST[$name] ?? ''));

            if ($f['type'] === 'password') {
                // Password only participates when a value was actually supplied.
                if ($val === '') continue;
            }

            if (!empty($f['required']) && $val === '') {
                echo json_encode(['success' => false, 'message' => $f['label'] . ' is required.']);
                exit;
            }

            if ($f['type'] === 'select' && !empty($f['options']) && $val !== '' && !in_array($val, $f['options'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid value for ' . $f['label'] . '.']);
                exit;
            }

            $data[$name] = $val;
        }

        // Duplicate check on the main name field (case-sensitive exact match, mirrors existing add_client/add_port behaviour).
        // Surveyors are identified by username (checked further below), not full name, so two surveyors may share a name.
        if ($module !== 'surveyors') {
            $dupSql = "SELECT `$idField` FROM `$table` " . buildWhere($where, ["`$nameField` = ?" . ($action === 'edit' ? " AND `$idField` != ?" : '')]);
            $dupParams = [$data[$nameField] ?? ''];
            if ($action === 'edit') $dupParams[] = $id;
            $dupStmt = $db->prepare($dupSql);
            $dupStmt->execute($dupParams);
            if ($dupStmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => $cfg['singular'] . ' with this name already exists.']);
                exit;
            }
        }

        // Extra per-module rules (surveyor username uniqueness + password hashing).
        if ($module === 'surveyors') {
            if (isset($data['username'])) {
                $chk = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
                $chk->execute([$data['username'], $id]);
                if ($chk->fetchColumn()) {
                    echo json_encode(['success' => false, 'message' => 'Username already exists. Please choose another username.']);
                    exit;
                }
            }
            if (isset($data['password'])) {
                $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
            } elseif ($action === 'add') {
                // A brand new surveyor account must have a password.
                echo json_encode(['success' => false, 'message' => 'Password is required for a new surveyor.']);
                exit;
            }
        }

        if ($action === 'add') {
            foreach (($cfg['insert_extra'] ?? []) as $k => $v) $data[$k] = $v;
            $cols = array_keys($data);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
            $stmt = $db->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
            $stmt->execute(array_values($data));
            $newId = (int)$db->lastInsertId();
            echo json_encode(['success' => true, 'id' => $newId, 'message' => $cfg['singular'] . ' added successfully.']);
            exit;
        } else {
            if (empty($data)) {
                echo json_encode(['success' => true, 'message' => 'Nothing to update.']);
                exit;
            }
            $setSql = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
            $stmt = $db->prepare("UPDATE `$table` SET $setSql WHERE `$idField` = ?" . ($where !== '' ? " AND $where" : ''));
            $stmt->execute([...array_values($data), $id]);
            echo json_encode(['success' => true, 'message' => $cfg['singular'] . ' updated successfully.']);
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid record.']);
            exit;
        }

        foreach ($fkChecks as $fk) {
            $chk = $db->prepare("SELECT COUNT(*) FROM `{$fk['table']}` WHERE `{$fk['column']}` = ?");
            $chk->execute([$id]);
            if ((int)$chk->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete this ' . strtolower($cfg['singular']) . ' - it is already in use by one or more surveys.']);
                exit;
            }
        }

        $stmt = $db->prepare("DELETE FROM `$table` WHERE `$idField` = ?" . ($where !== '' ? " AND $where" : ''));
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => $cfg['singular'] . ' deleted successfully.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Exception $e) {
    error_log('admin_master.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
