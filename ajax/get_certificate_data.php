<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$security_path = dirname(__DIR__) . '/platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}
$include_paths = [dirname(dirname(__DIR__)), dirname(__DIR__)];
function find_include_path(array $paths, $relative) {
    foreach ($paths as $base) {
        $full = rtrim($base, '/') . '/' . ltrim($relative, '/');
        if (file_exists($full)) {
            return $full;
        }
    }
    return null;
}

$db_path = find_include_path($include_paths, 'includes/db.php');
if (!$db_path) {
    echo json_encode(['success' => false, 'error' => 'Missing include: db.php']);
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
if (!isset($mysqli) && isset($db) && is_object($db) && method_exists($db, 'getConnection')) {
    $mysqli = $db->getConnection();
}
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    foreach ($GLOBALS as $value) {
        if ($value instanceof mysqli) {
            $mysqli = $value;
            break;
        }
    }
}
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $pdo_found = false;
    foreach ($GLOBALS as $value) {
        if ($value instanceof PDO) {
            $pdo_found = true;
            break;
        }
    }
    echo json_encode([
        'success' => false,
        'error' => $pdo_found ? 'Database connection uses PDO; mysqli expected.' : 'Database connection not initialized.'
    ]);
    exit();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'principal'], true)) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$cert_id = (int) ($_GET['id'] ?? 0);
if ($cert_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid certificate ID']);
    exit();
}

$stmt = $mysqli->prepare("SELECT * FROM bonafide_certificates WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $cert_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Certificate not found']);
    exit();
}

$cert = $res->fetch_assoc();

echo json_encode([
    'success' => true,
    'certificate_type' => $cert['certificate_type'],
    'tuition_fee' => $cert['tuition_fee'],
    'other_fee' => $cert['other_fee'],
    'hostel_fee' => $cert['hostel_fee'],
    'bus_fee' => $cert['bus_fee'],
    'bus_type' => $cert['bus_type'],
    'bus_zone' => $cert['bus_zone'],
    'facility_option' => $cert['facility_option'],
    'academic_years' => $cert['academic_year']
]);
