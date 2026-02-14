<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$role = strtolower((string) ($_SESSION['role'] ?? 'public'));
if ($role !== 'admin') {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
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
if ((!isset($mysqli) || !($mysqli instanceof mysqli)) && isset($GLOBALS['db']) && is_object($GLOBALS['db']) && method_exists($GLOBALS['db'], 'getConnection')) {
    $candidate = $GLOBALS['db']->getConnection();
    if ($candidate instanceof mysqli) {
        $mysqli = $candidate;
    }
}
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    foreach ($GLOBALS as $value) {
        if ($value instanceof mysqli) {
            $mysqli = $value;
            break;
        }
    }
}
if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    die('Database unavailable');
}

function q(mysqli $db, string $sql, string $ok): void
{
    echo '<div style="font-family:monospace;margin:6px 0;">';
    if ($db->query($sql)) {
        echo '<span style="color:#15803d">OK</span> ' . htmlspecialchars($ok, ENT_QUOTES, 'UTF-8');
    } else {
        echo '<span style="color:#b91c1c">ERR</span> ' . htmlspecialchars($db->error, ENT_QUOTES, 'UTF-8');
    }
    echo '</div>';
}

echo '<h2>AI Governance DB Fix</h2>';

q($mysqli, "CREATE TABLE IF NOT EXISTS ai_pending_ai_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question MEDIUMTEXT NOT NULL,
    proposed_answer MEDIUMTEXT NOT NULL,
    user_type VARCHAR(50) DEFAULT 'public',
    source_model VARCHAR(120) DEFAULT NULL,
    source_engine VARCHAR(80) DEFAULT 'external_ai',
    confidence FLOAT DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    reviewed_by VARCHAR(80) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_created(status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "ai_pending_ai_answers created/verified");

q($mysqli, "CREATE TABLE IF NOT EXISTS ai_interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) DEFAULT NULL,
    user_type ENUM('student','employee','public') DEFAULT NULL,
    user_message TEXT DEFAULT NULL,
    ai_response TEXT DEFAULT NULL,
    intent VARCHAR(100) DEFAULT NULL,
    confidence FLOAT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "ai_interactions created/verified");

q($mysqli, "CREATE TABLE IF NOT EXISTS ai_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    response TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "ai_logs created/verified");

q($mysqli, "CREATE TABLE IF NOT EXISTS ai_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL UNIQUE,
    total_queries INT DEFAULT 0,
    successful_responses INT DEFAULT 0,
    local_responses INT DEFAULT 0,
    ai_responses INT DEFAULT 0,
    avg_response_time FLOAT DEFAULT 0,
    user_types LONGTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "ai_metrics created/verified");

echo '<p><a href="/dashboard/ai_admin.php">Open AI Admin Console</a></p>';
?>
