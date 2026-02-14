<?php
ob_start();
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
    echo '<div class="text-danger">Missing include: db.php</div>';
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
    echo $pdo_found
        ? '<div class="text-danger">Database connection uses PDO; mysqli expected.</div>'
        : '<div class="text-danger">Database connection not initialized.</div>';
    exit();
}

$allowed_roles = ['admin', 'principal', 'dean', 'hod', 'staff', 'student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    echo '<div class="text-danger">Access denied.</div>';
    exit();
}

$user_role = $_SESSION['role'];
$employee_id = $_SESSION['employee_id'] ?? $_SESSION['user_id'];
$user_department = $_SESSION['department'] ?? $_SESSION['dept'] ?? '';
$student_id = $_SESSION['student_id'] ?? $_SESSION['user_id'];

$request_id = (int) ($_GET['id'] ?? 0);
if ($request_id <= 0) {
    echo '<div class="text-danger">Invalid request.</div>';
    exit();
}

$conditions = ["r.id = ?"];
$params = [$request_id];
$types = "i";

if ($user_role === 'student') {
    $conditions[] = "r.student_id = ?";
    $params[] = $student_id;
    $types .= "s";
} elseif ($user_role === 'staff') {
    $conditions[] = "r.student_id IN (SELECT Student_ID_No FROM mentor_mentee WHERE Employee_ID_No = ? OR ClassAdvisorID = ?)";
    $params[] = $employee_id;
    $params[] = $employee_id;
    $types .= "ss";
} elseif ($user_role === 'hod' && $user_department !== '') {
    $conditions[] = "r.department = ?";
    $params[] = $user_department;
    $types .= "s";
}

$sql = "SELECT r.* FROM bonafide_requests r WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    echo '<div class="text-danger">Request not found.</div>';
    exit();
}

$request = $res->fetch_assoc();
$sid = $request['student_id'];

// Fetch student raw data
$student = null;
$source = '';
$raw = [];

$stmt = $mysqli->prepare("SELECT * FROM students_batch_25_26 WHERE id_no = ? LIMIT 1");
$stmt->bind_param("s", $sid);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $raw = $res->fetch_assoc();
    $source = 'fresher';
} else {
    $stmt = $mysqli->prepare("SELECT * FROM students_login_master WHERE IDNo = ? LIMIT 1");
    $stmt->bind_param("s", $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $raw = $res->fetch_assoc();
        $source = 'senior';
    }
}

if (!$raw) {
    echo '<div class="text-danger">Student data not found.</div>';
    exit();
}

// Fee details
$fee = ['scholarship_type' => '', 'tuition_fees' => 0, 'other_fees' => 0];
$stmt = $mysqli->prepare("SELECT * FROM bonafide_fee_details WHERE id_no = ? LIMIT 1");
$stmt->bind_param("s", $sid);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $fee = $res->fetch_assoc();
}

// Transport / Hostel
$transport = null;
$hostel = null;

$stmt = $mysqli->prepare("SELECT * FROM transport_allocation WHERE id_no = ? LIMIT 1");
$stmt->bind_param("s", $sid);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $transport = $res->fetch_assoc();
}

$stmt = $mysqli->prepare(
    "SELECT 'Padmavathy Girls Hostel' AS hostel_name, room_no FROM hostel_girls_padmavathy WHERE id_no = ?
     UNION ALL
     SELECT 'Titans Boys Hostel' AS hostel_name, room_no FROM hostel_boys_titans WHERE id_no = ?"
);
$stmt->bind_param("ss", $sid, $sid);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $hostel = $res->fetch_assoc();
}

function normalize_display($value) {
    $value = is_null($value) ? '' : trim((string) $value);
    if ($value === '' || $value === '0' || strtoupper($value) === 'NULL') {
        return 'N/A';
    }
    return $value;
}

function get_raw_value(array $raw, array $keys, $default = 'N/A') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $raw)) {
            $val = normalize_display($raw[$key]);
            if ($val !== 'N/A') {
                return $val;
            }
        }
    }
    return $default;
}

function calculate_transport_fee($route, $amount) {
    $fee_numeric = preg_replace('/[^0-9.]/', '', (string) $amount);
    if ($fee_numeric !== '' && is_numeric($fee_numeric)) {
        return (float) $fee_numeric;
    }
    switch ($route) {
        case 'R1': return 20000;
        case 'R2': return 37000;
        case 'R3': return 40000;
        default:
            return (stripos((string) $route, 'AC') !== false) ? 50000 : 0;
    }
}

function calculate_hostel_fee($room_type) {
    return ($room_type === 'AC') ? 110000 : 90000;
}

$blocked = $source === 'fresher'
    ? ['serial_no', 'password', 'profile_photo', 'is_default_password', 'current_status']
    : ['id', 'IDNo', 'Password', 'profile_photo', 'is_default_password', 'current_status', 'FirstLogin'];

// Primary header values
$student_name = get_raw_value($raw, ['student_name', 'Name'], 'Student');
$register_no = get_raw_value($raw, ['register_no', 'RegisterNo'], '');
$department = get_raw_value($raw, ['department', 'Dept', 'Department'], '');
$batch = get_raw_value($raw, ['batch', 'Batch'], '');

$facility_text = 'Day Scholar (Own Transport)';
if ($hostel) {
    $room_no = $hostel['room_no'] ?? '';
    $room_type = stripos($room_no, 'AC') !== false ? 'AC' : 'Non-AC';
    $facility_text = $hostel['hostel_name'] . ' (' . $room_type . ')';
} elseif ($transport) {
    $route = $transport['route_no'] ?? '';
    $facility_text = $route ? ('Route ' . $route) : 'Transport';
}

$hosteller_status = 'Day Scholar (Own Transport)';
if ($hostel) {
    $hosteller_status = 'Hosteller';
} elseif ($transport) {
    $route = $transport['route_no'] ?? '';
    $hosteller_status = $route ? ('Transport (Route ' . $route . ')') : 'Transport';
}

$class_advisor_name = '';
$advisor_id = $request['advisor_id'] ?? '';
if ($advisor_id) {
    $stmt = $mysqli->prepare("SELECT NAME FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
    $stmt->bind_param("s", $advisor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $class_advisor_name = $res->fetch_assoc()['NAME'] ?? '';
    }
}
if ($class_advisor_name === '') {
    $stmt = $mysqli->prepare("SELECT Employee_ID_No, ClassAdvisorID FROM mentor_mentee WHERE Student_ID_No = ? LIMIT 1");
    $stmt->bind_param("s", $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $advisor_id = $row['Employee_ID_No'] ?: ($row['ClassAdvisorID'] ?? '');
        if ($advisor_id) {
            $stmt2 = $mysqli->prepare("SELECT NAME FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
            $stmt2->bind_param("s", $advisor_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2 && $res2->num_rows > 0) {
                $class_advisor_name = $res2->fetch_assoc()['NAME'] ?? '';
            }
        }
    }
}
?>

<div class="student-info-card">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="fw-bold mb-1"><?= htmlspecialchars($student_name) ?></h5>
            <div class="text-muted small"><?= htmlspecialchars($register_no) ?> | <?= htmlspecialchars($department) ?> | <?= htmlspecialchars($batch) ?></div>
        </div>
        <span class="badge bg-primary"><?= ucfirst($source) ?></span>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="p-3 border rounded bg-light h-100">
                <div class="fw-bold mb-2">Scholarship & Fees</div>
                <div><strong>Scholarship:</strong> <?= htmlspecialchars($fee['scholarship_type'] ?? 'None') ?></div>
                <?php if(in_array($user_role, ['admin', 'principal'], true)): ?>
                    <div><strong>Tuition Fee:</strong> ₹<?= number_format((float) ($fee['tuition_fees'] ?? 0), 2) ?></div>
                    <div><strong>Other Fee:</strong> ₹<?= number_format((float) ($fee['other_fees'] ?? 0), 2) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 border rounded bg-light h-100">
                <div class="fw-bold mb-2">Facility</div>
                <div><strong>Facility:</strong> <?= htmlspecialchars($facility_text) ?></div>
                <?php if($hostel): ?>
                    <div><strong>Hostel Fee:</strong> ₹<?= number_format(calculate_hostel_fee(stripos(($hostel['room_no'] ?? ''), 'AC') !== false ? 'AC' : 'Non-AC'), 2) ?></div>
                <?php elseif($transport): ?>
                    <div><strong>Transport Fee:</strong> ₹<?= number_format(calculate_transport_fee($transport['route_no'] ?? '', $transport['fee_amount'] ?? ''), 2) ?></div>
                <?php else: ?>
                    <div class="text-muted">No facility assigned</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <?php
        $profile_fields = [
            ['label' => 'Register No', 'value' => $register_no],
            ['label' => 'Student Name', 'value' => $student_name],
            ['label' => 'Gender', 'value' => get_raw_value($raw, ['gender', 'Gender'])],
            ['label' => 'College Email', 'value' => get_raw_value($raw, ['college_email', 'CollegeEmail', 'email', 'email_id', 'Email', 'student_email'])],
            ['label' => 'Degree Type', 'value' => get_raw_value($raw, ['degree_type', 'DegreeType'])],
            ['label' => 'Degree', 'value' => get_raw_value($raw, ['degree', 'Degree'])],
            ['label' => 'Batch', 'value' => $batch],
            ['label' => 'Section', 'value' => get_raw_value($raw, ['section', 'Section'])],
            ['label' => 'Department', 'value' => $department],
            ['label' => 'Dept Code', 'value' => get_raw_value($raw, ['dept_code', 'deptcode', 'DeptCode'])],
            ['label' => 'Semester', 'value' => get_raw_value($raw, ['semester', 'Semester', 'sem'])],
            ['label' => 'Is Hosteller', 'value' => $hosteller_status],
            ['label' => 'Parent Contact', 'value' => get_raw_value($raw, ['parent_contact', 'parent_contact_no', 'parent_mobile', 'parent_phone', 'guardian_contact', 'FatherMobile', 'MotherMobile'])],
            ['label' => 'Father Name', 'value' => get_raw_value($raw, ['parent_name', 'father_name', 'fathername'])],
            ['label' => 'Mother Name', 'value' => get_raw_value($raw, ['mother_name', 'mothername'])],
            ['label' => 'Year of Admission', 'value' => get_raw_value($raw, ['yearofadmission', 'year_of_admission', 'admission_year', 'Yearofadmission'])],
            ['label' => 'Lateral Entry', 'value' => get_raw_value($raw, ['lateralentry', 'lateral_entry', 'LateralEntry'])],
            ['label' => 'Transfer Student', 'value' => get_raw_value($raw, ['transferstudent', 'transfer_student', 'TransferStudent'])],
            ['label' => 'Type of Admission', 'value' => get_raw_value($raw, ['type_of_admission', 'admission_type', 'Typeofadmission', 'typeadmission'])],
            ['label' => 'Class Advisor', 'value' => normalize_display($class_advisor_name)],
            ['label' => 'Date of Birth', 'value' => get_raw_value($raw, ['dob', 'date_of_birth', 'DateOfBirth'])],
            ['label' => 'Student Contact', 'value' => get_raw_value($raw, ['student_contact', 'student_mobile', 'student_phone', 'mobile_no', 'MobileNo', 'StudentMobile'])],
            ['label' => 'Aadhaar No', 'value' => get_raw_value($raw, ['aadhaar_no', 'aadhaar_number', 'aadhar_no', 'aadhar_number'])],
            ['label' => 'Community', 'value' => get_raw_value($raw, ['community', 'Community'])],
            ['label' => 'Address', 'value' => get_raw_value($raw, ['address', 'Address', 'residential_address', 'ResidentialAddress'])],
            ['label' => 'Pincode', 'value' => get_raw_value($raw, ['pincode', 'pin_code', 'PinCode'])]
        ];
        foreach ($profile_fields as $field):
        ?>
            <div class="col-md-6">
                <div class="border rounded p-2 h-100">
                    <div class="text-muted small"><?= htmlspecialchars($field['label']) ?></div>
                    <div class="fw-semibold"><?= htmlspecialchars(normalize_display($field['value'])) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
