<?php
/**
 * Centralized CSRF protection helper.
 * Shared by both the main site/admin (../includes/csrf.php from admin/includes/)
 * and YSMS (../includes/csrf.php from YSMS/config/) so there is exactly one
 * implementation, not a duplicated/inconsistent one per app.
 *
 * Usage:
 *   csrf_field()   — echo inside a <form>...</form> to embed the hidden token input
 *   csrf_require() — call once, early, on any page that accepts POST; it aborts
 *                    the request with 403 if the token is missing/invalid.
 *
 * Design notes:
 * - Token is generated once per session and reused (not per-request), so users
 *   with multiple tabs open don't get spurious failures.
 * - Token is only ever read from $_POST — a GET parameter is never accepted,
 *   so a token can't leak via a URL/referrer and still be used to pass validation.
 * - Comparison uses hash_equals() to avoid timing side-channels.
 */

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Defensive: this should never happen since every caller starts a
            // session before reaching here, but fail safe rather than fatal.
            return '';
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    function csrf_field(): string {
        $token = csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    function csrf_valid(): bool {
        $expected = csrf_token();
        $supplied = $_POST['csrf_token'] ?? '';
        if ($expected === '' || !is_string($supplied) || $supplied === '') {
            return false;
        }
        return hash_equals($expected, $supplied);
    }

    /**
     * Call this on any page/endpoint that may receive POST. It only checks
     * when the request actually is a POST — GET/HEAD requests are untouched.
     * Also accepts the token via the X-CSRF-Token header, for fetch()-based
     * AJAX calls that send JSON instead of a form body (see the header.php /
     * admin_header.php <script> that auto-attaches this header to fetch()).
     */
    function csrf_require(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $expected = csrf_token();
        $supplied = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($expected === '' || !is_string($supplied) || $supplied === '' || !hash_equals($expected, (string)$supplied)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Request could not be verified (missing or expired security token). Please go back, refresh the page, and try again.";
            exit;
        }
    }
}
