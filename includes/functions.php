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
