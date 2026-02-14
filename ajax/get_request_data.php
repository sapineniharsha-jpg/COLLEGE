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

$request_id = (int) ($_GET['id'] ?? 0);
if ($request_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request ID']);
    exit();
}

$stmt = $mysqli->prepare("SELECT * FROM bonafide_requests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Request not found']);
    exit();
}

$request = $res->fetch_assoc();
$student_id = $request['student_id'];

// Student details (community)
$community = '';
$stmt = $mysqli->prepare("SELECT community FROM students_batch_25_26 WHERE id_no = ? LIMIT 1");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $community = $res->fetch_assoc()['community'] ?? '';
} else {
    $stmt = $mysqli->prepare("SELECT community FROM students_login_master WHERE IDNo = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $community = $res->fetch_assoc()['community'] ?? '';
    }
}

// Fee details
$fee = ['scholarship_type' => '', 'tuition_fees' => 0, 'other_fees' => 0];
$stmt = $mysqli->prepare("SELECT * FROM bonafide_fee_details WHERE id_no = ? LIMIT 1");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $fee_row = $res->fetch_assoc();
    $fee['scholarship_type'] = $fee_row['scholarship_type'] ?? '';
    $fee['tuition_fees'] = $fee_row['tuition_fees'] ?? 0;
    $fee['other_fees'] = $fee_row['other_fees'] ?? 0;
}

// Transport
$transport = null;
$stmt = $mysqli->prepare("SELECT * FROM transport_allocation WHERE id_no = ? LIMIT 1");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $transport = $res->fetch_assoc();
}

// Hostel
$hostel = null;
$stmt = $mysqli->prepare(
    "SELECT 'Padmavathy Girls Hostel' AS hostel_name, room_no FROM hostel_girls_padmavathy WHERE id_no = ?
     UNION ALL
     SELECT 'Titans Boys Hostel' AS hostel_name, room_no FROM hostel_boys_titans WHERE id_no = ?"
);
$stmt->bind_param("ss", $student_id, $student_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $hostel = $res->fetch_assoc();
}

$facility_from_request = trim($request['facility_option'] ?? '');
$defaults = [
    'tuition_fee' => is_numeric($request['tuition_fee'] ?? null) ? (float) $request['tuition_fee'] : (float) ($fee['tuition_fees'] ?? 0),
    'other_fee' => is_numeric($request['other_fee'] ?? null) ? (float) $request['other_fee'] : (float) ($fee['other_fees'] ?? 0),
    'hostel_fee' => is_numeric($request['hostel_fee'] ?? null) ? (float) $request['hostel_fee'] : 0,
    'bus_fee' => is_numeric($request['bus_fee'] ?? null) ? (float) $request['bus_fee'] : 0,
    'paid_tuition_fee' => is_numeric($request['paid_tuition_fee'] ?? null) ? (float) $request['paid_tuition_fee'] : 0,
    'paid_other_fee' => is_numeric($request['paid_other_fee'] ?? null) ? (float) $request['paid_other_fee'] : 0,
    'paid_hostel_fee' => is_numeric($request['paid_hostel_fee'] ?? null) ? (float) $request['paid_hostel_fee'] : 0,
    'facility_option' => $facility_from_request !== '' ? $facility_from_request : 'None',
    'bus_type' => $request['bus_type'] ?? 'None',
    'bus_zone' => $request['bus_zone'] ?? 'None',
    'facility_detail' => '',
    'academic_year' => $request['academic_year'] ?? '',
    'note_details' => $request['note_details'] ?? 'No'
];

if ($defaults['facility_option'] === 'None') {
    $defaults['facility_detail'] = 'Day Scholar (Own Transport)';
}

if ($facility_from_request === '' && $defaults['facility_option'] === 'None' && $hostel) {
    $defaults['facility_option'] = 'Hostel';
    $room_no = $hostel['room_no'] ?? '';
    $room_type = stripos($room_no, 'AC') !== false ? 'AC' : 'Non-AC';
    $defaults['hostel_fee'] = $defaults['hostel_fee'] ?: (($room_type === 'AC') ? 110000 : 90000);
    $defaults['facility_detail'] = $hostel['hostel_name'] . ' (' . $room_type . ')';
} elseif ($facility_from_request === '' && $defaults['facility_option'] === 'None' && $transport) {
    $defaults['facility_option'] = 'Transport';
    $route = $transport['route_no'] ?? '';
    $defaults['bus_zone'] = $defaults['bus_zone'] ?: ($route ?: 'None');
    if ($defaults['bus_zone'] !== 'None' && stripos($defaults['bus_zone'], 'AC') !== false) {
        $defaults['bus_zone'] = 'AC';
    }
    $defaults['bus_type'] = $defaults['bus_type'] !== 'None' ? $defaults['bus_type'] : (stripos($route, 'AC') !== false ? 'AC' : 'Regular');

    $fee_amount = $transport['fee_amount'] ?? '';
    $fee_numeric = preg_replace('/[^0-9.]/', '', $fee_amount);
    if ($defaults['bus_fee'] <= 0) {
        if ($fee_numeric !== '' && is_numeric($fee_numeric)) {
            $defaults['bus_fee'] = (float) $fee_numeric;
        } else {
        switch ($route) {
            case 'R1': $defaults['bus_fee'] = 20000; break;
            case 'R2': $defaults['bus_fee'] = 37000; break;
            case 'R3': $defaults['bus_fee'] = 40000; break;
            default:
                if (stripos($route, 'AC') !== false) {
                    $defaults['bus_fee'] = 50000;
                } else {
                    $defaults['bus_fee'] = 0;
                }
                break;
            }
        }
    }
    $defaults['facility_detail'] = 'Route ' . $route;
}

echo json_encode([
    'success' => true,
    'request' => [
        'student_id' => $request['student_id'],
        'student_name' => $request['student_name'],
        'register_number' => $request['register_number'],
        'department' => $request['department'],
        'batch' => $request['batch'],
        'bonafide_type' => $request['bonafide_type'],
        'purpose' => $request['purpose']
    ],
    'student' => ['community' => $community],
    'fee' => $fee,
    'defaults' => $defaults
]);
