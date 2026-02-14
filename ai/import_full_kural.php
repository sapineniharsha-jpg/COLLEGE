<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('max_execution_time', 300);

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (!file_exists($dbPath)) {
    die('db.php missing');
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
    die('Database unavailable');
}

echo "<h2>Importing Tirukkural...</h2>";
$mysqli->query("CREATE TABLE IF NOT EXISTS tirukkural (
    kural_no INT PRIMARY KEY,
    section VARCHAR(120) NOT NULL,
    tamil TEXT NOT NULL,
    english TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$url = "https://raw.githubusercontent.com/tk120404/thirukkural/master/thirukkural.json";
$raw = @file_get_contents($url);
if ($raw === false) {
    die('Unable to download source json');
}
$json = json_decode($raw, true);
$rows = $json['kural'] ?? $json;
if (!is_array($rows)) {
    die('Invalid source format');
}

$stmt = $mysqli->prepare("INSERT INTO tirukkural (kural_no, section, tamil, english)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE section = VALUES(section), tamil = VALUES(tamil), english = VALUES(english)");
if (!$stmt) {
    die('Prepare failed');
}

$count = 0;
foreach ($rows as $r) {
    $no = (int) ($r['Number'] ?? $r['number'] ?? 0);
    if ($no <= 0) {
        continue;
    }
    $line1 = (string) ($r['Line1'] ?? $r['line1'] ?? '');
    $line2 = (string) ($r['Line2'] ?? $r['line2'] ?? '');
    $tamil = trim($line1 . "\n" . $line2);
    $english = (string) ($r['Translation'] ?? $r['translation'] ?? '');
    $section = 'Virtue (Aram)';
    if ($no > 380 && $no <= 1080) {
        $section = 'Wealth (Porul)';
    } elseif ($no > 1080) {
        $section = 'Love (Inbam)';
    }

    $stmt->bind_param("isss", $no, $section, $tamil, $english);
    if ($stmt->execute()) {
        $count++;
    }
}
echo "<p><b>Imported/updated:</b> {$count}</p>";
echo "<p><a href='/velai.php'>Open VEL AI</a></p>";
?>
