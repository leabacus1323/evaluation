<?php
// -----------------------------------------------------------------------------
// DATABASE CONFIGURATION
// Replace these values with the credentials shown in your hosting control panel.
// InfinityFree example host: sqlXXX.infinityfree.com
// -----------------------------------------------------------------------------
define('DB_HOST', 'sqlXXX.infinityfree.com');
define('DB_NAME', 'if0_XXXXXXXX_evaluation');
define('DB_USER', 'if0_XXXXXXXX');
define('DB_PASS', 'CHANGE_ME');
define('DB_PORT', 3306);

// Base URL WITHOUT trailing slash. Example: https://example.infinityfreeapp.com
// Leave blank to auto-detect for most installations.
define('BASE_URL', '');

// App behavior
define('APP_TIMEZONE', 'Asia/Manila');
define('SESSION_NAME', 'EVALSYSSESSID');
define('DEFAULT_ADMIN_USERNAME', 'admin');

date_default_timezone_set(APP_TIMEZONE);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}
