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
     * $expectedMimes: list of acceptable MIME types for this upload slot,
     * e.g. ['application/pdf'] or the several MIME strings real-world Office/
     * Excel files are saved with (these vary by OS/Office version, which is
     * why each extension maps to more than one acceptable MIME below).
     *
     * Returns '' on success, or a human-readable error string on failure.
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
            return 'Could not verify file type.';
        }
        if (!in_array($actualMime, $expectedMimes, true)) {
            return 'File content does not match the expected type (detected: ' . $actualMime . ').';
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
