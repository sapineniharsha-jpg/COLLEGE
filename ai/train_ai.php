<?php
require_once dirname(__DIR__) . '/includes/auth_guard.php';
require_login(['admin', 'principal', 'dean', 'dean_academic', 'dean_academics']);

header('Content-Type: application/json');

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (!file_exists($dbPath)) {
    echo json_encode(['success' => false, 'message' => 'db.php missing']);
    exit;
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
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$question = trim((string) ($_POST['question'] ?? ''));
$answer = trim((string) ($_POST['answer'] ?? ''));
$keywords = trim((string) ($_POST['keywords'] ?? ''));
$sector = trim((string) ($_POST['sector_access'] ?? 'all'));
$category = trim((string) ($_POST['category'] ?? 'general'));
$createdBy = (string) ($_SESSION['user_id'] ?? 'admin');

if ($question === '' || $answer === '') {
    echo json_encode(['success' => false, 'message' => 'Question and answer are required']);
    exit;
}
if ($keywords === '') {
    $keywords = strtolower(str_replace(' ', ',', preg_replace('/\s+/', ' ', $question)));
}

// Ensure table exists
$mysqli->query("CREATE TABLE IF NOT EXISTS ai_qa_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    answer LONGTEXT NOT NULL,
    category VARCHAR(80) DEFAULT 'general',
    created_by VARCHAR(80) DEFAULT NULL,
    keywords TEXT,
    sector_access VARCHAR(40) DEFAULT 'all',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $mysqli->prepare("INSERT INTO ai_qa_master (question, answer, category, created_by, keywords, sector_access, created_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare query']);
    exit;
}
$stmt->bind_param("ssssss", $question, $answer, $category, $createdBy, $keywords, $sector);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $stmt->error]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'AI knowledge updated']);
?>
