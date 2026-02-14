<?php
// fix_topic_coverage_db.php
// Initializes and repairs schema for topic coverage tracking.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);

$include_paths = [__DIR__ . '/..', dirname(__DIR__)];
if (!function_exists('find_include_path')) {
    function find_include_path(array $paths, $relative)
    {
        foreach ($paths as $base) {
            $full = rtrim($base, '/') . '/' . ltrim($relative, '/');
            if (file_exists($full)) {
                return $full;
            }
        }
        return null;
    }
}

$db_path = find_include_path($include_paths, 'includes/db.php');
if (!$db_path) {
    http_response_code(500);
    echo "<h3 style='color:red'>Missing include: db.php</h3>";
    exit();
}
require_once $db_path;

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    foreach (['conn', 'con', 'db', 'connection'] as $candidate) {
        if (isset($$candidate) && $$candidate instanceof mysqli) {
            $mysqli = $$candidate;
            break;
        }
    }
}

$security_path = __DIR__ . '/../platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}
if (!function_exists('vh_e')) {
    function vh_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}
$role = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
if (!in_array($role, ['ADMIN', 'PRINCIPAL'], true)) {
    http_response_code(404);
    echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>404 Not Found</div>";
    exit();
}

if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    echo "<h2>Topic Coverage DB Repair</h2>";
    echo "<div style='font-family: monospace; background: #f4f4f4; padding: 20px; border-radius: 10px; border: 1px solid #ddd;'>";
    echo "<p style='color:red'>Database connection not initialized.</p></div>";
    exit();
}

if (!function_exists('index_exists')) {
    function index_exists(mysqli $mysqli, string $table, string $indexName): bool
    {
        $safe = $mysqli->real_escape_string($indexName);
        $res = $mysqli->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

echo "<h2>Topic Coverage DB Repair</h2>";
echo "<div style='font-family: monospace; background: #f4f4f4; padding: 20px; border-radius: 10px; border: 1px solid #ddd;'>";

$table = 'topic_coverage';
$tableEsc = $mysqli->real_escape_string($table);
$check = $mysqli->query("SHOW TABLES LIKE '{$tableEsc}'");

if (!$check || $check->num_rows === 0) {
    $sql = "CREATE TABLE `{$table}` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `unique_code` VARCHAR(150) NOT NULL,
        `date` DATE NOT NULL,
        `day_order` INT(11) NOT NULL,
        `hour` INT(11) NOT NULL,
        `subject_code` VARCHAR(50) NOT NULL,
        `subject_name` VARCHAR(255) DEFAULT NULL,
        `faculty_id` VARCHAR(50) NOT NULL,
        `faculty_name` VARCHAR(150) DEFAULT NULL,
        `department` VARCHAR(120) DEFAULT NULL,
        `topic_title` VARCHAR(255) NOT NULL,
        `topic_details` LONGTEXT DEFAULT NULL,
        `file_path` VARCHAR(500) DEFAULT NULL,
        `file_name` VARCHAR(255) DEFAULT NULL,
        `created_by` VARCHAR(50) NOT NULL,
        `updated_by` VARCHAR(50) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_topic_slot` (`unique_code`,`date`,`hour`,`subject_code`,`faculty_id`),
        KEY `idx_date_hour` (`date`,`hour`),
        KEY `idx_faculty_date` (`faculty_id`,`date`),
        KEY `idx_class_day` (`unique_code`,`day_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($mysqli->query($sql)) {
        echo "<p style='color:green'>Table topic_coverage created successfully.</p>";
    } else {
        echo "<p style='color:red'>Failed to create table: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:blue'>Table topic_coverage already exists. Checking columns...</p>";
}

$recheck = $mysqli->query("SHOW TABLES LIKE '{$tableEsc}'");
if (!$recheck || $recheck->num_rows === 0) {
    echo "<p style='color:red'>Cannot continue without topic_coverage table.</p>";
    echo "</div>";
    echo "<br><a href='topic_coverage.php' style='padding: 10px 20px; background: #1d4ed8; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Open Topic Coverage Page</a>";
    exit();
}

$columns = [
    'unique_code' => "VARCHAR(150) NOT NULL AFTER `id`",
    'date' => "DATE NOT NULL AFTER `unique_code`",
    'day_order' => "INT(11) NOT NULL AFTER `date`",
    'hour' => "INT(11) NOT NULL AFTER `day_order`",
    'subject_code' => "VARCHAR(50) NOT NULL AFTER `hour`",
    'subject_name' => "VARCHAR(255) DEFAULT NULL AFTER `subject_code`",
    'faculty_id' => "VARCHAR(50) NOT NULL AFTER `subject_name`",
    'faculty_name' => "VARCHAR(150) DEFAULT NULL AFTER `faculty_id`",
    'department' => "VARCHAR(120) DEFAULT NULL AFTER `faculty_name`",
    'topic_title' => "VARCHAR(255) NOT NULL AFTER `department`",
    'topic_details' => "LONGTEXT DEFAULT NULL AFTER `topic_title`",
    'file_path' => "VARCHAR(500) DEFAULT NULL AFTER `topic_details`",
    'file_name' => "VARCHAR(255) DEFAULT NULL AFTER `file_path`",
    'created_by' => "VARCHAR(50) NOT NULL AFTER `file_name`",
    'updated_by' => "VARCHAR(50) DEFAULT NULL AFTER `created_by`",
    'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `updated_by`",
    'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
];

foreach ($columns as $col => $def) {
    $colEsc = $mysqli->real_escape_string($col);
    $c = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'");
    if (!$c || $c->num_rows === 0) {
        $alter = "ALTER TABLE `{$table}` ADD `{$col}` {$def}";
        if ($mysqli->query($alter)) {
            echo "<p style='color:green'>Added column: <strong>" . vh_e($col) . "</strong></p>";
        } else {
            echo "<p style='color:red'>Failed to add " . vh_e($col) . ": " . vh_e($mysqli->error) . "</p>";
        }
    } else {
        echo "<p style='color:green'>Column <strong>" . vh_e($col) . "</strong> OK.</p>";
    }
}

if (!index_exists($mysqli, $table, 'uq_topic_slot')) {
    $q = "ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_topic_slot` (`unique_code`,`date`,`hour`,`subject_code`,`faculty_id`)";
    if ($mysqli->query($q)) {
        echo "<p style='color:green'>Index uq_topic_slot added.</p>";
    } else {
        echo "<p style='color:red'>Failed to add uq_topic_slot: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:green'>Index <strong>uq_topic_slot</strong> OK.</p>";
}

foreach ([
    'idx_date_hour' => "(`date`,`hour`)",
    'idx_faculty_date' => "(`faculty_id`,`date`)",
    'idx_class_day' => "(`unique_code`,`day_order`)"
] as $idx => $expr) {
    if (!index_exists($mysqli, $table, $idx)) {
        $q = "ALTER TABLE `{$table}` ADD INDEX `{$idx}` {$expr}";
        if ($mysqli->query($q)) {
            echo "<p style='color:green'>Index " . vh_e($idx) . " added.</p>";
        } else {
            echo "<p style='color:red'>Failed to add " . vh_e($idx) . ": " . vh_e($mysqli->error) . "</p>";
        }
    } else {
        echo "<p style='color:green'>Index <strong>" . vh_e($idx) . "</strong> OK.</p>";
    }
}

echo "</div>";
echo "<br><a href='topic_coverage.php' style='padding: 10px 20px; background: #1d4ed8; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Open Topic Coverage Page</a>";
?>
