<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if (!function_exists('send_fail')) {
    function send_fail(string $message)
    {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
if (!function_exists('send_ok')) {
    function send_ok(string $message, string $link = '')
    {
        echo json_encode(['success' => true, 'message' => $message, 'reset_link' => $link]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_fail('Invalid request');
}

$userId = trim((string) ($_POST['user_id'] ?? ''));
$role = strtolower(trim((string) ($_POST['role'] ?? 'auto')));
if ($userId === '') {
    send_fail('User ID is required');
}

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (!file_exists($dbPath)) {
    send_fail('Database bootstrap missing');
}
require_once $dbPath;

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    foreach (['conn', 'con', 'db', 'connection'] as $candidate) {
        if (isset($$candidate) && $$candidate instanceof mysqli) {
            $mysqli = $$candidate;
            break;
        }
    }
}
if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    send_fail('Database unavailable');
}

$mysqli->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_type VARCHAR(20) NOT NULL,
    source_table VARCHAR(80) NOT NULL,
    id_column VARCHAR(80) NOT NULL,
    password_column VARCHAR(80) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sourceTable = '';
$idColumn = '';
$passwordColumn = '';
$userType = 'student';
$candidate = strtoupper($userId);

if ($role === 'faculty' || $role === 'employee' || strpos($candidate, 'HTS') === 0) {
    $sourceTable = 'employee_details1';
    $idColumn = 'ID_NO';
    $passwordColumn = 'PASSWORD';
    $userType = 'staff';
    $chk = $mysqli->prepare("SELECT ID_NO FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param("s", $candidate);
        $chk->execute();
        $rr = $chk->get_result();
        if (!$rr || $rr->num_rows === 0) {
            send_fail('Faculty ID not found');
        }
    }
} else {
    // Check student master first
    $candidateRaw = $userId;
    $chk1 = $mysqli->prepare("SELECT IDNo FROM students_login_master WHERE IDNo = ? OR RegisterNo = ? LIMIT 1");
    if ($chk1) {
        $chk1->bind_param("ss", $candidateRaw, $candidateRaw);
        $chk1->execute();
        $r1 = $chk1->get_result();
        if ($r1 && $r1->num_rows > 0) {
            $row = $r1->fetch_assoc();
            $candidateRaw = (string) ($row['IDNo'] ?? $candidateRaw);
            $sourceTable = 'students_login_master';
            $idColumn = 'IDNo';
            $passwordColumn = 'Password';
        }
    }
    if ($sourceTable === '') {
        $chk2 = $mysqli->prepare("SELECT id_no FROM students_batch_25_26 WHERE id_no = ? OR register_no = ? LIMIT 1");
        if ($chk2) {
            $chk2->bind_param("ss", $candidateRaw, $candidateRaw);
            $chk2->execute();
            $r2 = $chk2->get_result();
            if ($r2 && $r2->num_rows > 0) {
                $row = $r2->fetch_assoc();
                $candidateRaw = (string) ($row['id_no'] ?? $candidateRaw);
                $sourceTable = 'students_batch_25_26';
                $idColumn = 'id_no';
                $passwordColumn = 'password';
            }
        }
    }
    if ($sourceTable === '') {
        send_fail('Student ID not found');
    }
    $candidate = $candidateRaw;
}

try {
    $token = bin2hex(random_bytes(32));
} catch (Throwable $e) {
    $token = hash('sha256', uniqid((string) mt_rand(), true));
}
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 30 * 60);

$ins = $mysqli->prepare("INSERT INTO password_reset_tokens
    (user_id, user_type, source_table, id_column, password_column, token_hash, expires_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)");
if (!$ins) {
    send_fail('Unable to generate reset token');
}
$ins->bind_param("sssssss", $candidate, $userType, $sourceTable, $idColumn, $passwordColumn, $tokenHash, $expiresAt);
if (!$ins->execute()) {
    send_fail('Unable to generate reset token');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$resetLink = $scheme . '://' . $host . '/reset_password.php?token=' . urlencode($token);
send_ok('Reset link generated. Use this link within 30 minutes: ' . $resetLink, $resetLink);
?>
