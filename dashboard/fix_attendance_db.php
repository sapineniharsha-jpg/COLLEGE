<?php
// fix_attendance_db.php
// PURPOSE: Repair database schema for Attendance Module (Enterprise v44.0)

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

// Auth + Access Control
if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}
$role = strtoupper(trim((string) ($_SESSION['role'] ?? '')));
$allowed_roles = ['ADMIN', 'PRINCIPAL'];
if (!in_array($role, $allowed_roles, true)) {
    http_response_code(404);
    echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>404 Not Found</div>";
    exit();
}

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    echo "<h2>Database Repair Tool</h2>";
    echo "<div style='font-family: monospace; background: #f4f4f4; padding: 20px; border-radius: 10px; border: 1px solid #ddd;'>";
    echo "<p style='color:red'>Database connection not initialized.</p></div>";
    exit();
}

if (!function_exists('index_exists')) {
    function index_exists(mysqli $mysqli, string $table, string $indexName): bool
    {
        $safeIndex = $mysqli->real_escape_string($indexName);
        $query = "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$safeIndex}'";
        $res = $mysqli->query($query);
        return $res && $res->num_rows > 0;
    }
}

echo "<h2>Database Repair Tool</h2>";
echo "<div style='font-family: monospace; background: #f4f4f4; padding: 20px; border-radius: 10px; border: 1px solid #ddd;'>";

if ($mysqli->connect_error) {
    echo "<h3 style='color:red'>Connection Failed: " . vh_e($mysqli->connect_error) . "</h3></div>";
    exit();
}

// 1. ENSURE TABLE EXISTS
$tableName = 'attendance_logs';
$tableNameEsc = $mysqli->real_escape_string($tableName);
$checkTable = $mysqli->query("SHOW TABLES LIKE '{$tableNameEsc}'");

if (!$checkTable || $checkTable->num_rows === 0) {
    // Create Table Logic
    $sql = "CREATE TABLE `{$tableName}` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `unique_code` varchar(100) NOT NULL,
        `date` date NOT NULL,
        `day_order` int(11) NOT NULL,
        `hour` int(11) NOT NULL,
        `student_id` varchar(50) NOT NULL,
        `subject_code` varchar(50) NOT NULL,
        `faculty_id` varchar(50) NOT NULL,
        `marked_by` varchar(50) NOT NULL,
        `status` varchar(5) NOT NULL DEFAULT 'P',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_main` (`date`,`hour`,`subject_code`),
        KEY `idx_student` (`student_id`),
        KEY `idx_unique` (`unique_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if ($mysqli->query($sql)) {
        echo "<p style='color:green'>Table '" . vh_e($tableName) . "' created successfully.</p>";
    } else {
        echo "<p style='color:red'>Error creating table: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:blue'>Table '" . vh_e($tableName) . "' exists. Checking columns...</p>";
}

// If table is still missing, stop before column/index operations
$recheckTable = $mysqli->query("SHOW TABLES LIKE '{$tableNameEsc}'");
if (!$recheckTable || $recheckTable->num_rows === 0) {
    echo "<p style='color:red'>Cannot continue because table '" . vh_e($tableName) . "' does not exist.</p>";
    echo "</div>";
    echo "<br><a href='attendance_selection.php' style='padding: 10px 20px; background: #22c55e; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go to Attendance Page &rarr;</a>";
    exit();
}

// 2. CHECK & ADD MISSING COLUMNS
$columnsToAdd = [
    'unique_code' => "VARCHAR(100) NOT NULL AFTER `id`",
    'marked_by'   => "VARCHAR(50) NOT NULL AFTER `status`",
    'day_order'   => "INT(11) NOT NULL AFTER `date`",
    'faculty_id'  => "VARCHAR(50) NOT NULL AFTER `student_id`",
];

foreach ($columnsToAdd as $col => $def) {
    $safeCol = $mysqli->real_escape_string($col);
    $checkCol = $mysqli->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$safeCol}'");
    if (!$checkCol || $checkCol->num_rows === 0) {
        $alter = "ALTER TABLE `{$tableName}` ADD `{$col}` {$def}";
        if ($mysqli->query($alter)) {
            echo "<p style='color:green'>Added missing column: <strong>" . vh_e($col) . "</strong></p>";
        } else {
            echo "<p style='color:red'>Failed to add " . vh_e($col) . ": " . vh_e($mysqli->error) . "</p>";
        }
    } else {
        echo "<p style='color:green'>Column <strong>" . vh_e($col) . "</strong> OK.</p>";
    }
}

// 3. OPTIMIZE INDEXES (For faster Reports)
if (!index_exists($mysqli, $tableName, 'idx_report')) {
    $added = $mysqli->query("ALTER TABLE `{$tableName}` ADD INDEX `idx_report` (`unique_code`, `date`)");
    if ($added) {
        echo "<p style='color:green'>Performance index <strong>idx_report</strong> added.</p>";
    } else {
        echo "<p style='color:red'>Failed to add index idx_report: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:green'>Index <strong>idx_report</strong> OK.</p>";
}

echo "</div>";
echo "<br><a href='attendance_selection.php' style='padding: 10px 20px; background: #22c55e; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go to Attendance Page &rarr;</a>";
?>
