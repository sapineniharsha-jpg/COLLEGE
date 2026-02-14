<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if (!function_exists('fail')) {
    function fail(string $msg)
    {
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}
if (!function_exists('ok')) {
    function ok(string $redirect, string $msg = '')
    {
        echo json_encode(['success' => true, 'redirect' => $redirect, 'message' => $msg]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request');
}

$captcha = trim((string) ($_POST['captcha'] ?? ''));
if ($captcha === '' || $captcha !== (string) ($_SESSION['captcha_code'] ?? '')) {
    fail('Incorrect captcha');
}

$name = trim((string) ($_POST['public_name'] ?? ''));
$mobile = preg_replace('/\D+/', '', (string) ($_POST['public_mobile'] ?? $_POST['user_id'] ?? ''));
if ($name === '') {
    fail('Name is required');
}
if (!preg_match('/^\d{10}$/', $mobile)) {
    fail('Enter a valid 10 digit mobile number');
}

$publicId = 'PUB_' . $mobile;
$_SESSION['user_id'] = $publicId;
$_SESSION['ID_NO'] = $publicId;
$_SESSION['NAME'] = $name;
$_SESSION['name'] = $name;
$_SESSION['role'] = 'public';
$_SESSION['DEPARTMENT'] = 'Guest';
$_SESSION['dept'] = 'Guest';
$_SESSION['logged_in'] = true;

// Optional logging
$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (file_exists($dbPath)) {
    require_once $dbPath;
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        foreach (['conn', 'con', 'db', 'connection'] as $candidate) {
            if (isset($$candidate) && $$candidate instanceof mysqli) {
                $mysqli = $$candidate;
                break;
            }
        }
    }
    if (isset($mysqli) && ($mysqli instanceof mysqli) && !$mysqli->connect_error) {
        $mysqli->query("CREATE TABLE IF NOT EXISTS ai_public_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(50) UNIQUE,
            name VARCHAR(150) NOT NULL,
            mobile VARCHAR(20) NOT NULL,
            email VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_access TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $up = $mysqli->prepare("INSERT INTO ai_public_users (public_id, name, mobile, last_access) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE name = VALUES(name), mobile = VALUES(mobile), last_access = NOW()");
        if ($up) {
            $up->bind_param("sss", $publicId, $name, $mobile);
            $up->execute();
        }
    }
}

ok('/velai.php', 'Welcome to VEL AI');
?>
