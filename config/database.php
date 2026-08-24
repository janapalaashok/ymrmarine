<?php
/**
 * YMR Marine - Cloud SQL Database Configuration
 *
 * Cloud Run + Google Cloud SQL (MySQL)
 */

define('DB_HOST', getenv('YMR_DB_HOST') ?: '/cloudsql/ymr-sms:asia-south1:ymrmarine');
define('DB_USER', getenv('YMR_DB_USER') ?: 'ymrmarine');
define('DB_PASS', getenv('YMR_DB_PASS') ?: '');
define('DB_NAME', getenv('YMR_DB_NAME') ?: 'ymrmarine');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // Check required environment variables
    if (empty(DB_HOST)) {
        die('Database configuration error: YMR_DB_HOST is not set.');
    }

    if (empty(DB_USER)) {
        die('Database configuration error: YMR_DB_USER is not set.');
    }

    if (empty(DB_PASS)) {
        die('Database configuration error: YMR_DB_PASS is not set.');
    }

    if (empty(DB_NAME)) {
        die('Database configuration error: YMR_DB_NAME is not set.');
    }

    /*
     * Cloud Run connects to Cloud SQL through the Unix socket:
     *
     * /cloudsql/PROJECT_ID:REGION:INSTANCE_NAME
     *
     * Example:
     * /cloudsql/ymr-sms:asia-south1:ymrmarine
     */
   $socketPath = DB_HOST;

    $dsn = 'mysql:unix_socket=' . $socketPath .
           ';dbname=' . DB_NAME .
           ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    try {
        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            $options
        );

        return $pdo;

    } catch (PDOException $e) {
    error_log('Cloud SQL connection failed: ' . $e->getMessage());

    die(
        '<div style="font-family:Arial;padding:40px;">
            <h2>Database Connection Failed</h2>
            <p><strong>Actual Error:</strong></p>
            <pre>' . htmlspecialchars($e->getMessage()) . '</pre>
        </div>'
    );
}
}