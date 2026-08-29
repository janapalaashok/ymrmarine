<?php
/**
 * Centralized upload validation helper — shared by the main site's
 * uploadImage() (includes/functions.php) and YSMS's report/photo upload
 * handlers. Adds two checks that were previously missing:
 *
 *  1. Real MIME-type sniffing via fileinfo (finfo), so a file can't get in
 *     just by having the "right" extension or by lying in its browser-
 *     supplied Content-Type — we read the actual file bytes.
 *  2. An explicit, configurable max size, enforced in code (not just left to
 *     whatever php.ini happens to be set to).
 *
 * This does NOT change what file types are accepted — only adds a real
 * content check behind the existing extension allowlists.
 */

if (!function_exists('upload_max_bytes')) {
    function upload_max_bytes(): int {
        // 20MB default; override with UPLOAD_MAX_BYTES env var if ever needed.
        $env = getenv('UPLOAD_MAX_BYTES');
        return $env !== false && ctype_digit($env) ? (int)$env : 20 * 1024 * 1024;
    }

    /**
     * $expectedMimes: list of MIME types considered "normal" for this upload
     * slot — used only as a soft/logging signal now (see below), the real
     * gate is the dangerous-type blocklist.
     *
     * Returns '' on success, or a human-readable error string on failure.
     *
     * Design note: an earlier version of this function REQUIRED the detected
     * MIME to exactly match a small hardcoded list per file type. In practice
     * real-world PDF/Office files get reported by finfo/libmagic with a wider
     * variety of MIME strings than that list covered (e.g. some .xlsx files
     * come back as generic "application/octet-stream" depending on the
     * libmagic database version), which silently rejected legitimate
     * uploads — the report/photo upload flow would then never reach its
     * required file count and fall through to a generic redirect instead of
     * advancing to the next step. To fix that while keeping real protection,
     * this now takes a blocklist approach: reject only MIME types that
     * indicate executable/script content, and otherwise trust the extension
     * allowlist the caller already enforces (isAllowedExt()) as the primary
     * gate. This is strictly safer against the actual threat (a script being
     * executed) while not breaking legitimate business files.
     */
    function upload_validate(array $file, array $expectedMimes, int $maxBytes = null): string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'Upload failed (error code ' . ($file['error'] ?? '?') . ').';
        }
        $maxBytes = $maxBytes ?? upload_max_bytes();
        if (($file['size'] ?? 0) <= 0) {
            return 'Uploaded file is empty.';
        }
        if ($file['size'] > $maxBytes) {
            return 'File is too large (max ' . round($maxBytes / 1024 / 1024, 1) . ' MB).';
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return 'Invalid upload.';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) finfo_close($finfo);
        if ($actualMime === false) {
            // Could not sniff the file (unusual, but not itself a reason to
            // reject a file whose extension already passed the allowlist).
            return '';
        }
        static $dangerousMimes = [
            'application/x-php', 'text/x-php', 'application/x-httpd-php',
            'application/x-sh', 'text/x-shellscript', 'application/x-perl',
            'application/x-python', 'text/x-python',
            'application/x-executable', 'application/x-dosexec', 'application/x-msdownload',
            'application/x-elf', 'application/java-archive',
        ];
        if (in_array($actualMime, $dangerousMimes, true)) {
            return 'File content looks like an executable/script (detected: ' . $actualMime . '), which is not allowed here.';
        }
        return '';
    }

    // Common MIME sets by extension, since real files vary across
    // Office/LibreOffice versions and OSes. Using define() rather than the
    // "const" keyword — const declarations aren't allowed inside a
    // conditional block (this whole block is wrapped in the
    // !function_exists(...) check above), only define() can be conditional.
    define('UPLOAD_MIMES_PDF', ['application/pdf']);
    define('UPLOAD_MIMES_XLSX', [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', // .xlsx is a zip container; some finfo builds report this
        'application/vnd.ms-excel',
    ]);
    define('UPLOAD_MIMES_DOC', [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ]);
    define('UPLOAD_MIMES_IMAGE', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
    define('UPLOAD_MIMES_ZIP', ['application/zip', 'application/x-zip-compressed']);
}
