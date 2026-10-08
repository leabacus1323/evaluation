<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(503);
        $message = 'The system cannot connect to the database. Check the database settings in includes/config.php.';
        if (stripos($e->getMessage(), 'Access denied') !== false) {
            $message = 'Database authentication failed. Verify DB_HOST, DB_NAME, DB_USER, and DB_PASS in includes/config.php.';
        } elseif (stripos($e->getMessage(), 'Unknown database') !== false) {
            $message = 'The configured database was not found. Create the database in your hosting panel and import schema.sql.';
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database Error</title><style>body{font-family:system-ui;background:#f5f7fb;color:#1f2937;padding:30px}.box{max-width:760px;margin:8vh auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:24px;box-shadow:0 12px 30px #00000010}h1{font-size:1.4rem}p{color:#667085;line-height:1.6}</style></head><body><div class="box"><h1>Database Connection Unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
        exit;
    }
    return $pdo;
}
