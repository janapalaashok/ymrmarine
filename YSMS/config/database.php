<?php
declare(strict_types=1);

class Database
{
    private ?PDO $conn = null;

    public function connect(): PDO
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        try {
            /*
             * YSMS Cloud SQL configuration
             *
             * Cloud Run environment variables:
             * YSMS_DB_HOST = /cloudsql/PROJECT:REGION:INSTANCE
             * YSMS_DB_NAME = ysms_db
             * YSMS_DB_USER = ysms_user
             * YSMS_DB_PASS = Cloud SQL password
             */

            $host = getenv('YSMS_DB_HOST') ?: '/cloudsql/ymr-sms:asia-south1:ymrmarine';
            $dbName = getenv('YSMS_DB_NAME') ?: 'ysms_db';
            $username = getenv('YSMS_DB_USER') ?: 'ysms_user';
            $password = getenv('YSMS_DB_PASS') ?: '';

            /*
             * Cloud Run + Cloud SQL uses Unix socket.
             */
            if (str_starts_with($host, '/cloudsql/')) {
                $dsn = "mysql:unix_socket={$host};dbname={$dbName};charset=utf8mb4";
            } else {
                /*
                 * Local development fallback.
                 */
                $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
            }

            $this->conn = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return $this->conn;

        } catch (PDOException $e) {

            error_log(
                "YSMS Database Error: " . $e->getMessage()
            );

            throw new Exception(
                "Application environment configuration mismatch."
            );
        }
    }
}