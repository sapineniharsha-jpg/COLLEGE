<?php
/**
 * Sample config template.
 * Copy this file to includes/config.php and fill real values.
 * Do not commit real credentials.
 */

// Optional timezone (adjust if needed)
date_default_timezone_set('Asia/Kolkata');

// Database credentials
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'your_db_name');

// Log directory used by includes/db.php
define('LOG_DIR', __DIR__ . '/../logs/');

?>
