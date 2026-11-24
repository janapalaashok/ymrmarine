<?php
/**
 * Common helper functions
 */

function getSetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $stmt = getDB()->query('SELECT setting_key, setting_value FROM settings');
            $cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function setSetting(string $key, string $value): void {
    $pdo = getDB();
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function uploadImage(array $file, string $subdir = ''): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) return null;
    require_once __DIR__ . '/upload_validation.php';
    // Real content check, not just the browser-supplied $file['type'].
    if (upload_validate($file, UPLOAD_MIMES_IMAGE) !== '') return null;

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = uniqid('img_') . '.' . strtolower($ext);
    $dir = __DIR__ . '/../assets/uploads/' . ($subdir ? trim($subdir, '/') . '/' : '');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir . $name;
    if (move_uploaded_file($file['tmp_name'], $path)) {
        return 'assets/uploads/' . ($subdir ? trim($subdir, '/') . '/' : '') . $name;
    }
    return null;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

/**
 * Admin passwords that must never stay in use: the install default (admin123, see
 * install/schema.sql) and other obvious guesses. An admin still signing in with one is sent to
 * the Change Password page and can't open any other admin page until it is changed.
 */
const ADMIN_WEAK_PASSWORDS = ['admin123', 'admin', 'admin@123', 'password', 'password123', '12345678', '123456789', 'ymrmarine', 'ymrmarine123'];

function admin_password_is_weak(string $plain): bool {
    return in_array(strtolower($plain), ADMIN_WEAK_PASSWORDS, true);
}

/** True when the stored hash matches one of the weak passwords above. */
function admin_hash_is_weak(string $hash): bool {
    foreach (ADMIN_WEAK_PASSWORDS as $p) {
        if (password_verify($p, $hash)) return true;
    }
    return false;
}

/** Why a new admin password is not accepted, or '' when it is fine. */
function admin_password_problem(string $new, string $username): string {
    if (strlen($new) < 10) return 'The new password must be at least 10 characters.';
    if (strlen($new) > 128) return 'The new password is too long (128 characters at most).';
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        return 'The new password must have at least one letter and one number.';
    }
    if (admin_password_is_weak($new) || strcasecmp($new, $username) === 0) {
        return 'This password is too easy to guess. Please choose another one.';
    }
    return '';
}
