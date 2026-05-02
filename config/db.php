<?php
// ============================================================
// db.php — PDO connection for InfinityFree
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'wmsu_documents');
define('DB_USER', 'root'); // ← change to your DB user
define('DB_PASS', ''); // ← change to your DB password
define('DB_PORT', '3306');

function getPDO(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Production-friendly error
            http_response_code(500);
            die('Database connection failed. Please contact the administrator.');
        }
    }

    return $pdo;
}