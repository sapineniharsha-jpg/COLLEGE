<?php
// fix_seminar_hall_db.php
// DB initializer/repair for seminar hall booking and inventory.

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
if (!function_exists('index_exists')) {
    function index_exists(mysqli $mysqli, string $table, string $indexName): bool
    {
        $safe = $mysqli->real_escape_string($indexName);
        $res = $mysqli->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
if (!in_array($role, ['admin', 'principal', 'ao', 'a.o', 'administrative_officer', 'administrative officer'], true)) {
    http_response_code(404);
    echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>404 Not Found</div>";
    exit();
}

if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    echo "<h2>Seminar Hall DB Repair</h2>";
    echo "<div style='font-family: monospace; background: #f4f4f4; padding: 20px; border-radius: 10px; border: 1px solid #ddd;'>";
    echo "<p style='color:red'>Database connection not initialized.</p></div>";
    exit();
}

echo "<h2>Seminar Hall DB Repair</h2>";
echo "<div style='font-family: monospace; background: #f4f4f4; padding: 20px; border-radius: 10px; border: 1px solid #ddd;'>";

// 1) seminar_hall_bookings
$bookTable = 'seminar_hall_bookings';
$bookExists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($bookTable) . "'");
if (!$bookExists || $bookExists->num_rows === 0) {
    $create = "CREATE TABLE `{$bookTable}` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `hall_name` VARCHAR(120) NOT NULL,
        `booking_date` DATE NOT NULL,
        `start_hour` TINYINT(4) NOT NULL,
        `end_hour` TINYINT(4) NOT NULL,
        `subject_name` VARCHAR(255) NOT NULL,
        `class_name` VARCHAR(120) DEFAULT NULL,
        `event_title` VARCHAR(255) DEFAULT NULL,
        `purpose` TEXT DEFAULT NULL,
        `requested_items` TEXT DEFAULT NULL,
        `booked_by_id` VARCHAR(50) NOT NULL,
        `booked_by_name` VARCHAR(150) NOT NULL,
        `booked_by_role` VARCHAR(50) NOT NULL,
        `department` VARCHAR(120) DEFAULT NULL,
        `status` ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
        `ao_remarks` TEXT DEFAULT NULL,
        `approved_by` VARCHAR(50) DEFAULT NULL,
        `approved_at` DATETIME DEFAULT NULL,
        `key_collected` TINYINT(1) NOT NULL DEFAULT 0,
        `key_collected_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($mysqli->query($create)) {
        echo "<p style='color:green'>Table seminar_hall_bookings created.</p>";
    } else {
        echo "<p style='color:red'>Failed to create seminar_hall_bookings: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:blue'>Table seminar_hall_bookings already exists. Checking columns...</p>";
}

$bookCols = [
    'hall_name' => "VARCHAR(120) NOT NULL AFTER `id`",
    'booking_date' => "DATE NOT NULL AFTER `hall_name`",
    'start_hour' => "TINYINT(4) NOT NULL AFTER `booking_date`",
    'end_hour' => "TINYINT(4) NOT NULL AFTER `start_hour`",
    'subject_name' => "VARCHAR(255) NOT NULL AFTER `end_hour`",
    'class_name' => "VARCHAR(120) DEFAULT NULL AFTER `subject_name`",
    'event_title' => "VARCHAR(255) DEFAULT NULL AFTER `class_name`",
    'purpose' => "TEXT DEFAULT NULL AFTER `event_title`",
    'requested_items' => "TEXT DEFAULT NULL AFTER `purpose`",
    'booked_by_id' => "VARCHAR(50) NOT NULL AFTER `requested_items`",
    'booked_by_name' => "VARCHAR(150) NOT NULL AFTER `booked_by_id`",
    'booked_by_role' => "VARCHAR(50) NOT NULL AFTER `booked_by_name`",
    'department' => "VARCHAR(120) DEFAULT NULL AFTER `booked_by_role`",
    'status' => "ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING' AFTER `department`",
    'ao_remarks' => "TEXT DEFAULT NULL AFTER `status`",
    'approved_by' => "VARCHAR(50) DEFAULT NULL AFTER `ao_remarks`",
    'approved_at' => "DATETIME DEFAULT NULL AFTER `approved_by`",
    'key_collected' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `approved_at`",
    'key_collected_at' => "DATETIME DEFAULT NULL AFTER `key_collected`",
    'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `key_collected_at`",
    'updated_at' => "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
];
foreach ($bookCols as $col => $def) {
    $c = $mysqli->query("SHOW COLUMNS FROM `{$bookTable}` LIKE '" . $mysqli->real_escape_string($col) . "'");
    if (!$c || $c->num_rows === 0) {
        $q = "ALTER TABLE `{$bookTable}` ADD `{$col}` {$def}";
        if ($mysqli->query($q)) {
            echo "<p style='color:green'>Added column {$col}.</p>";
        } else {
            echo "<p style='color:red'>Failed to add {$col}: " . vh_e($mysqli->error) . "</p>";
        }
    } else {
        echo "<p style='color:green'>Column {$col} OK.</p>";
    }
}

foreach ([
    'idx_slot' => "(`booking_date`,`hall_name`,`start_hour`,`end_hour`,`status`)",
    'idx_owner' => "(`booked_by_id`,`booking_date`)"
] as $idx => $expr) {
    if (!index_exists($mysqli, $bookTable, $idx)) {
        if ($mysqli->query("ALTER TABLE `{$bookTable}` ADD INDEX `{$idx}` {$expr}")) {
            echo "<p style='color:green'>Index {$idx} added.</p>";
        } else {
            echo "<p style='color:red'>Failed to add {$idx}: " . vh_e($mysqli->error) . "</p>";
        }
    } else {
        echo "<p style='color:green'>Index {$idx} OK.</p>";
    }
}

// 2) seminar_hall_inventory
$invTable = 'seminar_hall_inventory';
$invExists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($invTable) . "'");
if (!$invExists || $invExists->num_rows === 0) {
    $create = "CREATE TABLE `{$invTable}` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_name` VARCHAR(150) NOT NULL,
        `quantity` INT(11) NOT NULL DEFAULT 1,
        `is_available` TINYINT(1) NOT NULL DEFAULT 1,
        `notes` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_item_name` (`item_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($mysqli->query($create)) {
        echo "<p style='color:green'>Table seminar_hall_inventory created.</p>";
    } else {
        echo "<p style='color:red'>Failed to create seminar_hall_inventory: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:blue'>Table seminar_hall_inventory already exists. Checking columns...</p>";
}

$invCols = [
    'item_name' => "VARCHAR(150) NOT NULL AFTER `id`",
    'quantity' => "INT(11) NOT NULL DEFAULT 1 AFTER `item_name`",
    'is_available' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `quantity`",
    'notes' => "VARCHAR(255) DEFAULT NULL AFTER `is_available`",
    'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `notes`",
];
foreach ($invCols as $col => $def) {
    $c = $mysqli->query("SHOW COLUMNS FROM `{$invTable}` LIKE '" . $mysqli->real_escape_string($col) . "'");
    if (!$c || $c->num_rows === 0) {
        $q = "ALTER TABLE `{$invTable}` ADD `{$col}` {$def}";
        if ($mysqli->query($q)) {
            echo "<p style='color:green'>Added inventory column {$col}.</p>";
        } else {
            echo "<p style='color:red'>Failed to add inventory column {$col}: " . vh_e($mysqli->error) . "</p>";
        }
    } else {
        echo "<p style='color:green'>Inventory column {$col} OK.</p>";
    }
}
if (!index_exists($mysqli, $invTable, 'uq_item_name')) {
    if ($mysqli->query("ALTER TABLE `{$invTable}` ADD UNIQUE KEY `uq_item_name` (`item_name`)")) {
        echo "<p style='color:green'>Unique key uq_item_name added.</p>";
    } else {
        echo "<p style='color:red'>Failed to add uq_item_name: " . vh_e($mysqli->error) . "</p>";
    }
} else {
    echo "<p style='color:green'>Index uq_item_name OK.</p>";
}

// Seed inventory items
$seedItems = [
    ['System with Keyboard and Mouse', 1, 1, 'Desktop unit in hall'],
    ['Collar Mike', 2, 1, 'Wireless collar microphone'],
    ['White Board', 1, 1, 'Main board'],
    ['Duster', 2, 1, 'White board duster'],
];
$seedStmt = $mysqli->prepare("INSERT INTO `{$invTable}` (item_name, quantity, is_available, notes)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE
                                  quantity = VALUES(quantity),
                                  is_available = VALUES(is_available),
                                  notes = VALUES(notes)");
if ($seedStmt) {
    foreach ($seedItems as $it) {
        [$name, $qty, $avail, $notes] = $it;
        $seedStmt->bind_param("siis", $name, $qty, $avail, $notes);
        $seedStmt->execute();
    }
    echo "<p style='color:green'>Default seminar hall inventory seeded/updated.</p>";
} else {
    echo "<p style='color:red'>Unable to seed inventory: " . vh_e($mysqli->error) . "</p>";
}

echo "</div>";
echo "<br><a href='seminar_hall_booking.php' style='padding: 10px 20px; background: #1d4ed8; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Open Seminar Hall Booking</a>";
?>
