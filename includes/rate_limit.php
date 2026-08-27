<?php
/**
 * Centralized login rate-limiting / brute-force protection.
 * Shared by admin/login.php and YSMS/login.php.
 *
 * Storage: plain files under the OS temp directory (NOT the database — the
 * audit explicitly asked to avoid a schema change, and to stop and ask if
 * persistent storage were required). This is a deliberate tradeoff, and has
 * a real limitation worth knowing before relying on it:
 *
 *   - It resets whenever the container restarts or a new revision deploys
 *     (temp storage is not durable).
 *   - Cloud Run can run more than one instance; each instance has its own
 *     temp storage, so a distributed attacker spreading requests across
 *     instances is only partially slowed, not fully stopped.
 *   - It IS effective against the common case: repeated guesses from one
 *     browser session hitting whichever instance handles it.
 *
 * If you want this to hold up under multiple instances/restarts, it needs to
 * move to a shared store (e.g. a database table or Memorystore/Redis) —
 * flagging that as a REQUIRES MY APPROVAL item rather than doing it here.
 *
 * Behavior: progressive delay, not a permanent lock. A successful login
 * clears the counter for that identifier. Error messages are identical
 * whether the username exists or not, and whether it's locked out or not,
 * so this can't be used to enumerate valid usernames.
 */

if (!function_exists('rate_limit_check')) {

    function _rate_limit_dir(): string {
        $dir = sys_get_temp_dir() . '/ysms_login_attempts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    function _rate_limit_key(string $identifier): string {
        // Identifier = ip + username, so one username can't be used to lock
        // out a different username sharing the same IP (e.g. an office NAT).
        return sha1($identifier);
    }

    /**
     * Returns 0 if the login attempt may proceed now, or the number of
     * seconds the caller must wait before trying again.
     */
    function rate_limit_check(string $identifier): int {
        $file = _rate_limit_dir() . '/' . _rate_limit_key($identifier) . '.json';
        if (!is_file($file)) {
            return 0;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || empty($data['failures']) || empty($data['last_failure_at'])) {
            return 0;
        }
        $failures = (int)$data['failures'];
        // Progressive backoff: 5 failures -> ~15s, 10 -> ~2min, capped at 15 min.
        $delay = 0;
        if ($failures >= 5) {
            $over = $failures - 4;
            $delay = min(900, (int)(pow(2, min($over, 8)) * 2));
        }
        if ($delay <= 0) {
            return 0;
        }
        $elapsed = time() - (int)$data['last_failure_at'];
        $remaining = $delay - $elapsed;
        return $remaining > 0 ? $remaining : 0;
    }

    function rate_limit_record_failure(string $identifier): void {
        $file = _rate_limit_dir() . '/' . _rate_limit_key($identifier) . '.json';
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $data = ['failures' => 0];
        }
        $data['failures'] = (int)($data['failures'] ?? 0) + 1;
        $data['last_failure_at'] = time();
        @file_put_contents($file, json_encode($data));
    }

    function rate_limit_clear(string $identifier): void {
        $file = _rate_limit_dir() . '/' . _rate_limit_key($identifier) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
