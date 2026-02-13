<?php
// bonafide.php - Bonafide Certificate System (Role Based)
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/dept_errors.log');

$include_paths = [dirname(__DIR__), __DIR__];
$log_path = '';
foreach ([
    __DIR__ . '/logs/dept_errors.log',
    dirname(__DIR__) . '/logs/dept_errors.log'
] as $candidate) {
    if (is_dir(dirname($candidate))) {
        $log_path = $candidate;
        break;
    }
}
if ($log_path !== '') {
    ini_set('error_log', $log_path);
}

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
    http_response_code(500);
    echo 'Missing include: db.php';
    exit();
}
require_once $db_path;

// Normalize DB handle for different db.php conventions
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
    http_response_code(500);
    echo $pdo_found ? 'Database connection uses PDO; mysqli expected.' : 'Database connection not initialized.';
    exit();
}

$header_path = find_include_path($include_paths, 'includes/header.php');
if (!$header_path) {
    http_response_code(500);
    echo 'Missing include: header.php';
    exit();
}
require_once $header_path;

// Role-based access control
$allowed_roles = ['admin', 'principal', 'dean', 'hod', 'staff', 'student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    header("Location: ../login.php");
    exit();
}

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$employee_id = $_SESSION['employee_id'] ?? $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? $_SESSION['username'] ?? $_SESSION['user_id'];
$user_department = $_SESSION['department'] ?? $_SESSION['dept'] ?? '';

$is_student = ($user_role === 'student');
$is_admin = in_array($user_role, ['admin', 'principal'], true);
$is_dean = ($user_role === 'dean');
$is_hod = ($user_role === 'hod');
$is_staff = ($user_role === 'staff');

function current_academic_year() {
    $year = (int) date('Y');
    $month = (int) date('n');
    return ($month >= 6) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
}

function calculate_year_pursuing($batch) {
    $start = (int) (explode('-', $batch)[0] ?? date('Y'));
    $year = (int) date('Y');
    $month = (int) date('n');
    $diff = $year - $start + ($month >= 6 ? 1 : 0);
    if ($diff <= 1) return 'I';
    if ($diff === 2) return 'II';
    if ($diff === 3) return 'III';
    if ($diff === 4) return 'IV';
    return 'Completed';
}

function normalize_academic_years($input) {
    $years = [];
    if (is_string($input)) {
        $input = explode(',', $input);
    }
    if (!is_array($input)) {
        return ['list' => [], 'csv' => ''];
    }
    foreach ($input as $year) {
        $year = trim($year);
        if ($year === '') {
            continue;
        }
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    return ['list' => $years, 'csv' => implode(',', $years)];
}

function format_datetime_display($dt) {
    if (empty($dt)) {
        return '-';
    }
    return date('d M Y, h:i A', strtotime($dt));
}

function status_badge_class($status) {
    $status = strtolower((string) $status);
    if ($status === 'approved') return 'badge-approved';
    if ($status === 'rejected') return 'badge-rejected';
    if ($status === 'revoked') return 'badge-revoked';
    return 'badge-pending';
}

function profile_field_label($column) {
    $map = [
        'parent_name' => 'Father Name',
        'fathername' => 'Father Name',
        'mother_name' => 'Mother Name',
        'mothername' => 'Mother Name',
        'student_name' => 'Student Name',
        'Name' => 'Student Name',
        'register_no' => 'Register No',
        'RegisterNo' => 'Register No',
        'gender' => 'Gender',
        'Gender' => 'Gender',
        'department' => 'Department',
        'Dept' => 'Department',
        'section' => 'Section',
        'Section' => 'Section',
        'batch' => 'Batch',
        'Batch' => 'Batch',
        'community' => 'Community',
        'Community' => 'Community'
    ];
    return $map[$column] ?? ucwords(str_replace('_', ' ', $column));
}

function fetch_employee_name($mysqli, $employee_id) {
    if (!$employee_id) return '';
    $stmt = $mysqli->prepare("SELECT NAME FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
    $stmt->bind_param("s", $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc()['NAME'];
    }
    return '';
}

function fetch_department_faculty($mysqli, $department) {
    static $cache = [];
    $key = strtolower(trim((string) $department));
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if ($key === '') {
        return [];
    }

    static $dept_col = null;
    if ($dept_col === null) {
        $dept_col = '';
        $res = $mysqli->query("SHOW COLUMNS FROM employee_details1");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $field = strtolower($row['Field']);
                if (in_array($field, ['department', 'dept', 'department_name', 'dept_name'], true)) {
                    $dept_col = $row['Field'];
                    break;
                }
            }
        }
    }

    $faculty = [];
    if ($dept_col !== '') {
        $stmt = $mysqli->prepare("SELECT ID_NO, NAME FROM employee_details1 WHERE {$dept_col} = ? ORDER BY NAME ASC");
        $stmt->bind_param("s", $department);
    } else {
        $stmt = $mysqli->prepare("SELECT ID_NO, NAME FROM employee_details1 ORDER BY NAME ASC");
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['ID_NO']) && !empty($row['NAME'])) {
                $faculty[] = ['id' => $row['ID_NO'], 'name' => $row['NAME']];
            }
        }
    }

    $cache[$key] = $faculty;
    return $faculty;
}

function table_has_column($mysqli, $table, $column) {
    static $columns_cache = [];
    $table = trim($table);
    $column = strtolower(trim($column));
    if ($table === '' || $column === '') {
        return false;
    }
    if (!isset($columns_cache[$table])) {
        $columns_cache[$table] = [];
        $res = $mysqli->query("SHOW COLUMNS FROM {$table}");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns_cache[$table][strtolower($row['Field'])] = true;
            }
        }
    }
    return !empty($columns_cache[$table][$column]);
}

function is_science_humanities_dept($department) {
    $dep = strtolower(preg_replace('/[^a-z]/', '', (string) $department));
    return strpos($dep, 'science') !== false && strpos($dep, 'humanities') !== false;
}

function session_student_identity_tokens() {
    $tokens = [];
    foreach (['student_id', 'ID_NO', 'id_no', 'user_id', 'register_no', 'register_number', 'RegisterNo'] as $key) {
        $value = trim((string) ($_SESSION[$key] ?? ''));
        if ($value !== '' && !in_array($value, $tokens, true)) {
            $tokens[] = $value;
        }
    }
    return $tokens;
}

function facility_display_for_request($mysqli, $request) {
    static $transport_cache = [];
    static $hostel_cache = [];

    $student_id = $request['student_id'] ?? '';
    $facility_option = $request['facility_option'] ?? 'None';
    $bus_zone = $request['bus_zone'] ?? '';

    if ($student_id !== '' && !array_key_exists($student_id, $transport_cache)) {
        $transport_cache[$student_id] = fetch_transport_details($mysqli, $student_id);
    }
    if ($student_id !== '' && !array_key_exists($student_id, $hostel_cache)) {
        $hostel_cache[$student_id] = fetch_hostel_details($mysqli, $student_id);
    }

    $transport = $student_id !== '' ? ($transport_cache[$student_id] ?? null) : null;
    $hostel = $student_id !== '' ? ($hostel_cache[$student_id] ?? null) : null;

    if ($facility_option === 'Hostel') {
        if ($hostel) {
            $room_no = $hostel['room_no'] ?? '';
            $room_type = stripos($room_no, 'AC') !== false ? 'AC' : 'Non-AC';
            return $hostel['hostel_name'] . ' (' . $room_type . ')';
        }
        return 'Hostel';
    }

    if ($facility_option === 'Transport') {
        $route = $bus_zone ?: ($transport['route_no'] ?? '');
        $route = strtoupper(trim((string) $route));
        if ($route === 'AC') {
            return 'Bus (AC)';
        }
        return $route ? ('Bus (' . $route . ')') : 'Bus';
    }

    if ($facility_option === 'None' || $facility_option === '') {
        if ($hostel) {
            $room_no = $hostel['room_no'] ?? '';
            $room_type = stripos($room_no, 'AC') !== false ? 'AC' : 'Non-AC';
            return $hostel['hostel_name'] . ' (' . $room_type . ')';
        }
        if ($transport) {
            $route = strtoupper(trim((string) ($transport['route_no'] ?? '')));
            if ($route === 'AC') {
                return 'Bus (AC)';
            }
            return $route ? ('Bus (' . $route . ')') : 'Bus';
        }
        return 'Day Scholar (Own Transport)';
    }

    return 'Day Scholar (Own Transport)';
}

function fetch_class_advisor_id($mysqli, $student_id) {
    $stmt = $mysqli->prepare("SELECT Employee_ID_No, ClassAdvisorID FROM mentor_mentee WHERE Student_ID_No = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return !empty($row['Employee_ID_No']) ? $row['Employee_ID_No'] : ($row['ClassAdvisorID'] ?? '');
    }
    return '';
}

function fetch_student_details($mysqli, $student_id) {
    $stmt = $mysqli->prepare("SELECT * FROM students_batch_25_26 WHERE id_no = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return [
            'source' => 'fresher',
            'raw' => $row,
            'id' => $row['id_no'],
            'register_no' => $row['register_no'],
            'name' => $row['student_name'],
            'father_name' => $row['parent_name'],
            'mother_name' => $row['mother_name'] ?? '',
            'gender' => $row['gender'],
            'batch' => $row['batch'],
            'department' => $row['department'],
            'section' => $row['section'] ?? '',
            'community' => $row['community'] ?? '',
            'degree' => $row['degree'] ?? 'B.E/B.Tech',
            'degree_type' => $row['degree_type'] ?? 'UG'
        ];
    }

    $stmt = $mysqli->prepare("SELECT * FROM students_login_master WHERE IDNo = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return [
            'source' => 'senior',
            'raw' => $row,
            'id' => $row['IDNo'],
            'register_no' => $row['RegisterNo'],
            'name' => $row['Name'],
            'father_name' => $row['fathername'],
            'mother_name' => $row['mothername'] ?? '',
            'gender' => $row['Gender'],
            'batch' => $row['Batch'],
            'department' => $row['Dept'],
            'section' => $row['Section'] ?? '',
            'community' => $row['community'] ?? '',
            'degree' => $row['Degree'] ?? 'B.E/B.Tech',
            'degree_type' => $row['DegreeType'] ?? 'UG'
        ];
    }
    return null;
}

function fetch_fee_details($mysqli, $student_id) {
    $stmt = $mysqli->prepare("SELECT * FROM bonafide_fee_details WHERE id_no = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function fetch_transport_details($mysqli, $student_id) {
    $stmt = $mysqli->prepare("SELECT * FROM transport_allocation WHERE id_no = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function fetch_hostel_details($mysqli, $student_id) {
    $stmt = $mysqli->prepare(
        "SELECT 'Padmavathy Girls Hostel' AS hostel_name, room_no FROM hostel_girls_padmavathy WHERE id_no = ?
         UNION ALL
         SELECT 'Titans Boys Hostel' AS hostel_name, room_no FROM hostel_boys_titans WHERE id_no = ?"
    );
    $stmt->bind_param("ss", $student_id, $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $room_type = 'Non-AC';
        if (!empty($row['room_no']) && stripos($row['room_no'], 'AC') !== false) {
            $room_type = 'AC';
        }
        $row['room_type'] = $room_type;
        return $row;
    }
    return null;
}

function detect_facility_option($transport, $hostel) {
    if ($hostel) return 'Hostel';
    if ($transport) return 'Transport';
    return 'None';
}

function calculate_transport_fee($route, $amount) {
    $fee_numeric = preg_replace('/[^0-9.]/', '', (string) $amount);
    if ($fee_numeric !== '' && is_numeric($fee_numeric)) {
        return (float) $fee_numeric;
    }
    $route_clean = strtoupper(trim((string) $route));
    switch ($route_clean) {
        case 'R1': return 20000;
        case 'R2': return 37000;
        case 'R3': return 40000;
        case 'AC':
        case 'AC BUS':
            return 50000;
        default:
            return (stripos((string) $route, 'AC') !== false) ? 50000 : 0;
    }
}

function calculate_hostel_fee($room_type) {
    return ($room_type === 'AC') ? 110000 : 90000;
}

function fetch_student_profile_summary($mysqli, $student_id) {
    static $cache = [];
    if (isset($cache[$student_id])) {
        return $cache[$student_id];
    }

    $profile = [
        'section' => '',
        'community' => '',
        'father_name' => '',
        'mother_name' => '',
        'scholarship' => '',
        'tuition_fee' => 0,
        'other_fee' => 0,
        'facility' => 'None',
        'facility_fee' => 0,
        'facility_detail' => ''
    ];

    $student = fetch_student_details($mysqli, $student_id);
    if ($student) {
        $profile['section'] = $student['section'] ?? '';
        $profile['community'] = $student['community'] ?? '';
        $profile['father_name'] = $student['father_name'] ?? '';
        $profile['mother_name'] = $student['mother_name'] ?? '';
    }

    $fee = fetch_fee_details($mysqli, $student_id);
    if ($fee) {
        $profile['scholarship'] = $fee['scholarship_type'] ?? '';
        $profile['tuition_fee'] = (float) $fee['tuition_fees'];
        $profile['other_fee'] = (float) $fee['other_fees'];
    }

    $transport = fetch_transport_details($mysqli, $student_id);
    $hostel = fetch_hostel_details($mysqli, $student_id);
    if ($hostel) {
        $profile['facility'] = 'Hostel';
        $profile['facility_fee'] = calculate_hostel_fee($hostel['room_type'] ?? 'Non-AC');
        $profile['facility_detail'] = $hostel['hostel_name'] . ' (' . ($hostel['room_type'] ?? 'Non-AC') . ')';
    } elseif ($transport) {
        $profile['facility'] = 'Transport';
        $route = $transport['route_no'] ?? '';
        $profile['facility_fee'] = calculate_transport_fee($route, $transport['fee_amount'] ?? '');
        $profile['facility_detail'] = 'Route ' . $route;
    }

    $cache[$student_id] = $profile;
    return $profile;
}

function generate_request_number($request_id) {
    return 'REQ' . str_pad($request_id, 6, '0', STR_PAD_LEFT);
}

function generate_reference_number($mysqli) {
    $acad = current_academic_year();
    $res = $mysqli->query("SELECT id FROM bonafide_certificates ORDER BY id DESC LIMIT 1");
    $last_id = $res && $res->num_rows > 0 ? (int) $res->fetch_assoc()['id'] : 0;
    $next = $last_id + 1;
    return "Ref/VH/BONA/{$acad}/" . str_pad($next, 3, '0', STR_PAD_LEFT);
}

// ========================= STUDENT PROFILE =========================
$student_id = '';
$student_details = null;
$table_source = '';
$profile_complete = false;
$profile_missing_fields = [];
$fee_details = null;
$transport_details = null;
$hostel_details = null;
$facility_option = 'None';
$class_advisor_id = '';
$class_advisor_name = '';
$department_faculty = [];

if ($is_student) {
    $student_id = $_SESSION['student_id'] ?? $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
    if ($student_id) {
        $student_details = fetch_student_details($mysqli, $student_id);
        if ($student_details) {
            $table_source = $student_details['source'];
            $class_advisor_id = fetch_class_advisor_id($mysqli, $student_id);
            $class_advisor_name = fetch_employee_name($mysqli, $class_advisor_id);
            $department_faculty = [];
            if (empty($class_advisor_id)) {
                $department_faculty = fetch_department_faculty($mysqli, $student_details['department'] ?? $user_department);
            }
            $fee_details = fetch_fee_details($mysqli, $student_id);
            $transport_details = fetch_transport_details($mysqli, $student_id);
            $hostel_details = fetch_hostel_details($mysqli, $student_id);
            $facility_option = detect_facility_option($transport_details, $hostel_details);

            $raw = $student_details['raw'] ?? [];
            $blocked = $table_source === 'fresher'
                ? ['serial_no', 'password', 'profile_photo', 'is_default_password', 'current_status']
                : ['id', 'IDNo', 'Password', 'profile_photo', 'is_default_password', 'current_status', 'FirstLogin'];

            foreach ($raw as $col => $val) {
                if (in_array($col, $blocked, true)) {
                    continue;
                }
                $value = is_null($val) ? '' : trim((string) $val);
                if ($value === '' || $value === '0' || strtoupper($value) === 'NULL') {
                    $profile_missing_fields[] = [
                        'col' => $col,
                        'label' => profile_field_label($col)
                    ];
                }
            }
            $profile_complete = empty($profile_missing_fields);
        }
    }
}

// ========================= HANDLE PROFILE UPDATE =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $is_student) {
    $student_id = $_POST['student_id'] ?? '';
    $table_source = $_POST['table_source'] ?? '';
    if ($table_source === 'fresher') {
        $table_name = 'students_batch_25_26';
        $id_col = 'id_no';
    } else {
        $table_name = 'students_login_master';
        $id_col = 'IDNo';
    }

    $blocked_cols = $table_source === 'fresher'
        ? ['serial_no', 'password', 'profile_photo', 'is_default_password', 'current_status']
        : ['id', 'IDNo', 'Password', 'profile_photo', 'is_default_password', 'current_status', 'FirstLogin'];

    $stmt = $mysqli->prepare("SELECT * FROM {$table_name} WHERE {$id_col} = ? LIMIT 1");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $available_cols = $row ? array_keys($row) : [];

    foreach ($_POST as $key => $val) {
        if (strpos($key, 'upd_') !== 0) {
            continue;
        }
        $col = str_replace('upd_', '', $key);
        if (!in_array($col, $available_cols, true) || in_array($col, $blocked_cols, true)) {
            continue;
        }
        $val = trim($val);
        if ($val === '') {
            continue;
        }
        $stmt = $mysqli->prepare("UPDATE {$table_name} SET {$col} = ? WHERE {$id_col} = ?");
        $stmt->bind_param("ss", $val, $student_id);
        $stmt->execute();
    }

    header("Location: bonafide.php?profile_updated=1");
    exit();
}

// ========================= HANDLE REQUEST SUBMISSION =========================
$message = '';
$message_type = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request']) && $is_student) {
    if (!$profile_complete) {
        $message = "Please complete your profile before submitting requests.";
        $message_type = "warning";
    } else {
        $cert_type = trim($_POST['cert_type'] ?? '');
        $allowed_student_certs = ['General', 'Fee Structure', 'Fee Paid', 'Internship', 'Project'];

        $has_scholarship = $fee_details && isset($fee_details['scholarship_type']) &&
            in_array(trim($fee_details['scholarship_type']), ['7.5', '7.5%'], true);

        if ($has_scholarship && in_array($cert_type, ['Fee Structure', 'Fee Paid'], true)) {
            $message = "Scholarship holders (7.5%) are not allowed to request Fee Structure or Fee Paid certificates.";
            $message_type = "danger";
        } elseif (!in_array($cert_type, $allowed_student_certs, true)) {
            $message = "Restricted Access: LOR and Alumni certificates can only be issued by the Administrator.";
            $message_type = "danger";
        } else {
            $purpose = trim($_POST['purpose'] ?? '');
            $purpose_other = trim($_POST['purpose_other'] ?? '');
            if ($purpose === 'Other' && $purpose_other !== '') {
                $purpose = $purpose_other;
            }

            $parent_choice = $_POST['parent_choice'] ?? 'father';
            $parent_name = $student_details['father_name'] ?? '';
            $parent_prefix = 'Mr.';
            if ($parent_choice === 'mother' && !empty($student_details['mother_name'])) {
                $parent_name = $student_details['mother_name'];
                $parent_prefix = 'Mrs.';
            }

            $student_prefix = (stripos($student_details['gender'], 'F') === 0) ? 'Ms.' : 'Mr.';
            $year_pursuing = calculate_year_pursuing($student_details['batch']);
            $facility_option = trim($_POST['facility_option'] ?? 'None');
            $facility_option = in_array($facility_option, ['None', 'Hostel', 'Transport'], true) ? $facility_option : 'None';
            $bus_zone = strtoupper(trim($_POST['bus_zone'] ?? ''));
            if ($facility_option === 'Transport') {
                if ($bus_zone === '' || $bus_zone === 'NONE') {
                    $message = "Please select your bus route.";
                    $message_type = "warning";
                }
                if ($bus_zone !== '' && $bus_zone !== 'NONE' && !in_array($bus_zone, ['R1', 'R2', 'R3', 'AC'], true)) {
                    $message = "Invalid bus route selected.";
                    $message_type = "warning";
                }
                if ($bus_zone === '' || $bus_zone === 'NONE') {
                    $bus_zone = 'None';
                }
            } else {
                $bus_zone = 'None';
            }
            $bus_type = ($facility_option === 'Transport' && $bus_zone !== 'None')
                ? (($bus_zone === 'AC') ? 'AC' : 'Regular')
                : 'None';
            $class_advisor_id = fetch_class_advisor_id($mysqli, $student_id);
            if (empty($class_advisor_id)) {
                $advisor_pick = trim($_POST['class_advisor_pick'] ?? '');
                if ($advisor_pick !== '') {
                    $faculty_list = fetch_department_faculty($mysqli, $student_details['department'] ?? $user_department);
                    foreach ($faculty_list as $faculty) {
                        if (strcasecmp($advisor_pick, $faculty['id']) === 0 || strcasecmp($advisor_pick, $faculty['name']) === 0) {
                            $class_advisor_id = $faculty['id'];
                            break;
                        }
                        if (stripos($advisor_pick, $faculty['id']) === 0) {
                            $class_advisor_id = $faculty['id'];
                            break;
                        }
                    }
                }
            }

            if ($message_type === 'warning') {
                // validation failure
            } elseif (empty($class_advisor_id)) {
                $message = "Please select your Class Advisor before submitting the request.";
                $message_type = "warning";
            } else {
                $columns = [
                    'student_id', 'student_name', 'register_number', 'department', 'batch', 'student_prefix', 'parent_prefix',
                    'father_name', 'gender', 'year_pursuing', 'program', 'degreetype', 'duration_course', 'content_academic_year',
                    'academic_year', 'purpose', 'bonafide_type', 'fee_structure', 'note_details', 'facility_option'
                ];
                $values = [
                    $student_id,
                    $student_details['name'],
                    $student_details['register_no'],
                    $student_details['department'],
                    $student_details['batch'],
                    $student_prefix,
                    $parent_prefix,
                    $parent_name,
                    $student_details['gender'],
                    $year_pursuing,
                    $student_details['department'],
                    $student_details['degree_type'],
                    '4 Years',
                    current_academic_year(),
                    null,
                    $purpose,
                    $cert_type,
                    in_array($cert_type, ['Fee Structure', 'Fee Paid'], true) ? 'Yes' : 'No',
                    'No',
                    $facility_option
                ];

                if (table_has_column($mysqli, 'bonafide_requests', 'bus_zone')) {
                    $columns[] = 'bus_zone';
                    $values[] = $bus_zone;
                }
                if (table_has_column($mysqli, 'bonafide_requests', 'bus_type')) {
                    $columns[] = 'bus_type';
                    $values[] = $bus_type;
                }

                $columns[] = 'status';
                $columns[] = 'advisor_status';
                $columns[] = 'hod_status';
                $columns[] = 'dean_status';
                $columns[] = 'admin_status';
                $columns[] = 'request_date';
                $columns[] = 'advisor_id';
                $values[] = 'Pending';
                $values[] = 'pending';
                $values[] = 'pending';
                $values[] = 'pending';
                $values[] = 'pending';
                $values[] = date('Y-m-d H:i:s');
                $values[] = $class_advisor_id;

                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $sql = "INSERT INTO bonafide_requests (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
                $stmt = $mysqli->prepare($sql);

                $types = str_repeat('s', count($values));
                $stmt->bind_param($types, ...$values);

                if ($stmt->execute()) {
                    $request_id = $stmt->insert_id;
                    $request_number = generate_request_number($request_id);
                    $stmt2 = $mysqli->prepare("UPDATE bonafide_requests SET request_number = ? WHERE id = ?");
                    $stmt2->bind_param("si", $request_number, $request_id);
                    $stmt2->execute();

                    $message = "Request submitted successfully! Request ID: {$request_number}";
                    $message_type = "success";
                } else {
                    $message = "Error submitting request: " . $mysqli->error;
                    $message_type = "danger";
                }
            }
        }
    }
}

// ========================= HANDLE REQUEST ACTIONS =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'], $_POST['request_id'])) {
    $request_id = (int) $_POST['request_id'];
    $action = $_POST['request_action'];
    $remarks = trim($_POST['remarks'] ?? '');
    $now = date('Y-m-d H:i:s');

    $req_stmt = $mysqli->prepare("SELECT * FROM bonafide_requests WHERE id = ? LIMIT 1");
    $req_stmt->bind_param("i", $request_id);
    $req_stmt->execute();
    $req_res = $req_stmt->get_result();
    $request = $req_res && $req_res->num_rows > 0 ? $req_res->fetch_assoc() : null;

    if ($request && $action === 'save_fees' && $is_admin) {
        $tuition_fee = (float) ($_POST['tuition_fee'] ?? 0);
        $other_fee = (float) ($_POST['other_fee'] ?? 0);
        $hostel_fee = (float) ($_POST['hostel_fee'] ?? 0);
        $bus_fee = (float) ($_POST['bus_fee'] ?? 0);
        $bus_type = trim($_POST['bus_type'] ?? 'None');
        $bus_zone = trim($_POST['bus_zone'] ?? 'None');
        $facility_option = trim($_POST['facility_option'] ?? 'None');
        $note_details = isset($_POST['note_details']) ? 'Yes' : 'No';
        $paid_tuition = (float) ($_POST['paid_tuition_fee'] ?? 0);
        $paid_other = (float) ($_POST['paid_other_fee'] ?? 0);
        $paid_hostel = (float) ($_POST['paid_hostel_fee'] ?? 0);

        if ($facility_option === 'None') {
            $hostel_fee = 0;
            $bus_fee = 0;
            $paid_hostel = 0;
            $bus_type = 'None';
            $bus_zone = 'None';
        } elseif ($facility_option === 'Hostel') {
            $bus_fee = 0;
            $bus_type = 'None';
            $bus_zone = 'None';
        } elseif ($facility_option === 'Transport') {
            $hostel_fee = 0;
            $bus_type = (stripos($bus_zone, 'AC') !== false) ? 'AC' : 'Regular';
        }

        $academic_input = $_POST['academic_years'] ?? [];
        $academic_years = normalize_academic_years($academic_input);
        if (empty($academic_years['list'])) {
            header("Location: bonafide.php?year_missing=1");
            exit();
        }
        $academic_csv = $academic_years['csv'];

        if ($request['bonafide_type'] !== 'Fee Paid') {
            $paid_tuition = 0;
            $paid_other = 0;
            $paid_hostel = 0;
        } elseif (!in_array($facility_option, ['Hostel', 'Transport'], true)) {
            $paid_hostel = 0;
        }

        $update_fields = [
            "tuition_fee = ?",
            "other_fee = ?",
            "hostel_fee = ?",
            "paid_tuition_fee = ?",
            "paid_other_fee = ?",
            "paid_hostel_fee = ?",
            "facility_option = ?",
            "bus_fee = ?"
        ];
        $types = "ddddddsd";
        $values = [
            $tuition_fee,
            $other_fee,
            $hostel_fee,
            $paid_tuition,
            $paid_other,
            $paid_hostel,
            $facility_option,
            $bus_fee
        ];

        if (table_has_column($mysqli, 'bonafide_requests', 'bus_type')) {
            $update_fields[] = "bus_type = ?";
            $types .= "s";
            $values[] = $bus_type;
        }
        if (table_has_column($mysqli, 'bonafide_requests', 'bus_zone')) {
            $update_fields[] = "bus_zone = ?";
            $types .= "s";
            $values[] = $bus_zone;
        }

        $update_fields[] = "academic_year = ?";
        $update_fields[] = "note_details = ?";
        $types .= "ss";
        $values[] = $academic_csv;
        $values[] = $note_details;

        $types .= "i";
        $values[] = $request_id;

        $stmt = $mysqli->prepare(
            "UPDATE bonafide_requests
             SET " . implode(', ', $update_fields) . "
             WHERE id = ?"
        );
        $stmt->bind_param($types, ...$values);
        $stmt->execute();

        header("Location: bonafide.php?fee_saved=1");
        exit();
    }

    if ($request && in_array($action, ['approve', 'reject'], true)) {
        $status = $action === 'approve' ? 'approved' : 'rejected';

        // Role checks and approval order
        $can_act = false;
        if ($is_admin) {
            if ($action === 'approve' && ($request['admin_status'] ?? '') !== 'approved') {
                $can_act = true;
            } elseif ($action === 'reject' && ($request['admin_status'] ?? 'pending') === 'pending') {
                $can_act = true;
            }
        } elseif ($is_staff && $request['advisor_status'] === 'pending') {
            $can_act = true;
        } elseif ($is_hod && $request['advisor_status'] === 'approved' && $request['hod_status'] === 'pending') {
            $can_act = true;
        } elseif ($is_dean && $request['hod_status'] === 'approved' && $request['dean_status'] === 'pending') {
            $can_act = true;
        }

        if ($can_act) {
            if ($is_staff) {
                $stmt = $mysqli->prepare(
                    "UPDATE bonafide_requests
                     SET advisor_status = ?, advisor_action_date = ?, advisor_remarks = ?, advisor_id = ?
                     WHERE id = ?"
                );
                $stmt->bind_param("ssssi", $status, $now, $remarks, $employee_id, $request_id);
                $stmt->execute();
            } elseif ($is_hod) {
                $stmt = $mysqli->prepare(
                    "UPDATE bonafide_requests
                     SET hod_status = ?, hod_approved_by = ?, hod_approval = ?, rejection_reason = ?
                     WHERE id = ?"
                );
                $hod_by = $user_name;
                $stmt->bind_param("ssssi", $status, $hod_by, $now, $remarks, $request_id);
                $stmt->execute();
            } elseif ($is_dean) {
                $stmt = $mysqli->prepare(
                    "UPDATE bonafide_requests
                     SET dean_status = ?, dean_approved_by = ?, dean_approval = ?, rejection_reason = ?
                     WHERE id = ?"
                );
                $dean_by = $user_name;
                $stmt->bind_param("ssssi", $status, $dean_by, $now, $remarks, $request_id);
                $stmt->execute();
            } elseif ($is_admin) {
                if ($status === 'rejected') {
                    $stmt = $mysqli->prepare(
                        "UPDATE bonafide_requests
                         SET admin_status = ?, admin_action_by = ?, admin_action_date = ?, admin_reject_reason = ?, status = 'Rejected'
                         WHERE id = ?"
                    );
                    $stmt->bind_param("ssssi", $status, $user_name, $now, $remarks, $request_id);
                    $stmt->execute();
                } else {
                    // Admin approval with fee and academic year details
                    $tuition_fee = (float) ($_POST['tuition_fee'] ?? 0);
                    $other_fee = (float) ($_POST['other_fee'] ?? 0);
                    $hostel_fee = (float) ($_POST['hostel_fee'] ?? 0);
                    $bus_fee = (float) ($_POST['bus_fee'] ?? 0);
                    $bus_type = trim($_POST['bus_type'] ?? 'None');
                    $bus_zone = trim($_POST['bus_zone'] ?? 'None');
                    $facility_option = trim($_POST['facility_option'] ?? 'None');
                    $note_details = isset($_POST['note_details']) ? 'Yes' : 'No';
                    $paid_tuition = (float) ($_POST['paid_tuition_fee'] ?? 0);
                    $paid_other = (float) ($_POST['paid_other_fee'] ?? 0);
                    $paid_hostel = (float) ($_POST['paid_hostel_fee'] ?? 0);

                    if ($facility_option === 'None') {
                        $hostel_fee = 0;
                        $bus_fee = 0;
                        $paid_hostel = 0;
                        $bus_type = 'None';
                        $bus_zone = 'None';
                    } elseif ($facility_option === 'Hostel') {
                        $bus_fee = 0;
                        $paid_hostel = $paid_hostel;
                        $bus_type = 'None';
                        $bus_zone = 'None';
                    } elseif ($facility_option === 'Transport') {
                        $hostel_fee = 0;
                        $bus_type = (stripos($bus_zone, 'AC') !== false) ? 'AC' : 'Regular';
                    }

                    $academic_input = $_POST['academic_years'] ?? [];
                    $academic_years = normalize_academic_years($academic_input);
                    if (empty($academic_years['list'])) {
                        header("Location: bonafide.php?year_missing=1");
                        exit();
                    }

                    $ref_number = generate_reference_number($mysqli);
                    $student_prefix = (stripos($request['gender'], 'F') === 0) ? 'Ms.' : 'Mr.';
                    $parent_prefix = $request['parent_prefix'] ?: 'Mr.';
                    $year_pursuing = $request['year_pursuing'] ?: calculate_year_pursuing($request['batch']);
                    $fee_structure = in_array($request['bonafide_type'], ['Fee Structure', 'Fee Paid'], true) ? 'Yes' : 'No';
                    $certificate_date = date('Y-m-d');

                    if ($request['bonafide_type'] !== 'Fee Paid') {
                        $paid_tuition = 0;
                        $paid_other = 0;
                        $paid_hostel = 0;
                    } elseif (!in_array($facility_option, ['Hostel', 'Transport'], true)) {
                        $paid_hostel = 0;
                    }

                    $duration_course = $request['duration_course'] ?: '4 Years';
                    $degree_name = $request['degreetype'] ?: 'B.E/B.Tech';
                    $content_acad = $request['content_academic_year'] ?: current_academic_year();
                    $academic_csv = $academic_years['csv'];

                    $cert_columns = [
                        'request_id', 'requestid', 'request_number', 'ref_number', 'bonafide_date',
                        'student_prefix', 'student_name', 'parent_prefix', 'father_name', 'student_id',
                        'register_number', 'department', 'year_pursuing', 'duration_course', 'degree_name',
                        'content_academic_year', 'academic_year', 'purpose', 'fee_structure', 'note_details',
                        'tuition_fee', 'other_fee', 'hostel_fee', 'paid_tuition_fee', 'paid_other_fee', 'paid_hostel_fee',
                        'facility_option', 'bus_fee', 'certificate_type', 'status', 'created_at', 'admin_status', 'approval_stage'
                    ];

                    $cert_values = [
                        $request_id,
                        $request_id,
                        $request['request_number'],
                        $ref_number,
                        $certificate_date,
                        $student_prefix,
                        $request['student_name'],
                        $parent_prefix,
                        $request['father_name'],
                        $request['student_id'],
                        $request['register_number'],
                        $request['department'],
                        $year_pursuing,
                        $duration_course,
                        $degree_name,
                        $content_acad,
                        $academic_csv,
                        $request['purpose'],
                        $fee_structure,
                        $note_details,
                        $tuition_fee,
                        $other_fee,
                        $hostel_fee,
                        $paid_tuition,
                        $paid_other,
                        $paid_hostel,
                        $facility_option,
                        $bus_fee,
                        $request['bonafide_type'],
                        'approved',
                        date('Y-m-d H:i:s'),
                        'approved',
                        'approved'
                    ];

                    if (table_has_column($mysqli, 'bonafide_certificates', 'bus_type')) {
                        $insert_pos = array_search('bus_fee', $cert_columns, true) + 1;
                        array_splice($cert_columns, $insert_pos, 0, 'bus_type');
                        array_splice($cert_values, $insert_pos, 0, $bus_type);
                    }
                    if (table_has_column($mysqli, 'bonafide_certificates', 'bus_zone')) {
                        $insert_pos = array_search('bus_fee', $cert_columns, true) + 1;
                        if (in_array('bus_type', $cert_columns, true)) {
                            $insert_pos++;
                        }
                        array_splice($cert_columns, $insert_pos, 0, 'bus_zone');
                        array_splice($cert_values, $insert_pos, 0, $bus_zone);
                    }

                    $placeholders = implode(',', array_fill(0, count($cert_columns), '?'));
                    $sql = "INSERT INTO bonafide_certificates (" . implode(', ', $cert_columns) . ") VALUES ({$placeholders})";
                    $cert_stmt = $mysqli->prepare($sql);
                    $types = str_repeat('s', count($cert_values));
                    $cert_stmt->bind_param($types, ...$cert_values);

                    if ($cert_stmt->execute()) {
                        $certificate_id = $cert_stmt->insert_id;

                        // Generate QR code
                        $qr_lib = find_include_path($include_paths, 'includes/phpqrcode/qrlib.php');
                        if (!$qr_lib) {
                            http_response_code(500);
                            echo 'Missing include: phpqrcode/qrlib.php';
                            exit();
                        }
                        require_once $qr_lib;
                        $qrData = "https://www.velhightech.com/verify_bonafide.php?ref=" . urlencode($ref_number);
                        $qrDir = __DIR__ . '/temp';
                        if (!is_dir($qrDir)) {
                            mkdir($qrDir, 0755, true);
                        }
                        $qrFileName = 'qr_' . md5($ref_number) . '.png';
                        $qrFilePath = $qrDir . '/' . $qrFileName;
                        QRcode::png($qrData, $qrFilePath, QR_ECLEVEL_L, 3);
                        if (file_exists($qrFilePath)) {
                            $qrDbPath = 'temp/' . $qrFileName;
                            $cert_upd = $mysqli->prepare(
                                "UPDATE bonafide_certificates
                                 SET qr_code_path = ?, verification_url = ?, qr_generated_at = NOW()
                                 WHERE id = ?"
                            );
                            $cert_upd->bind_param("ssi", $qrDbPath, $qrData, $certificate_id);
                            $cert_upd->execute();
                        } else {
                            error_log('QR generation failed for ref ' . $ref_number . ' at ' . $qrFilePath);
                        }

                        // Update request row
                        $req_updates = [
                            "admin_status = 'approved'",
                            "admin_action_by = ?",
                            "admin_action_date = ?",
                            "status = 'Approved'",
                            "final_approved = 1",
                            "certificate_id = ?",
                            "ref_number = ?",
                            "bonafide_date = NOW()",
                            "tuition_fee = ?",
                            "other_fee = ?",
                            "hostel_fee = ?",
                            "paid_tuition_fee = ?",
                            "paid_other_fee = ?",
                            "paid_hostel_fee = ?",
                            "facility_option = ?",
                            "academic_year = ?",
                            "note_details = ?"
                        ];
                        $req_values = [
                            $user_name,
                            $now,
                            $certificate_id,
                            $ref_number,
                            $tuition_fee,
                            $other_fee,
                            $hostel_fee,
                            $paid_tuition,
                            $paid_other,
                            $paid_hostel,
                            $facility_option,
                            $academic_csv,
                            $note_details
                        ];
                        $req_types = str_repeat('s', count($req_values));

                        if (table_has_column($mysqli, 'bonafide_requests', 'bus_type')) {
                            $req_updates[] = "bus_type = ?";
                            $req_types .= "s";
                            $req_values[] = $bus_type;
                        }
                        if (table_has_column($mysqli, 'bonafide_requests', 'bus_zone')) {
                            $req_updates[] = "bus_zone = ?";
                            $req_types .= "s";
                            $req_values[] = $bus_zone;
                        }

                        $req_types .= "s";
                        $req_values[] = $request_id;

                        $req_upd = $mysqli->prepare(
                            "UPDATE bonafide_requests
                             SET " . implode(', ', $req_updates) . "
                             WHERE id = ?"
                        );
                        $req_upd->bind_param($req_types, ...$req_values);
                        $req_upd->execute();
                    } else {
                        error_log('Bonafide certificate insert failed: ' . $cert_stmt->error);
                    }
                }
            }
        }
    }

    header("Location: bonafide.php");
    exit();
}

// ========================= HANDLE REQUEST REVOKE =========================
if (isset($_GET['revoke_request']) && $is_student) {
    $req_id = (int) $_GET['revoke_request'];
    $stmt = $mysqli->prepare(
        "UPDATE bonafide_requests
         SET status = 'Revoked', advisor_status = advisor_status, admin_status = admin_status
         WHERE id = ? AND student_id = ? AND advisor_status = 'pending'"
    );
    $stmt->bind_param("is", $req_id, $student_id);
    $stmt->execute();
    header("Location: bonafide.php?msg=revoked");
    exit();
}

// ========================= HANDLE CERTIFICATE REVOKE =========================
if (isset($_GET['revoke_cert']) && $is_admin) {
    $cert_id = (int) $_GET['revoke_cert'];
    $mysqli->query("UPDATE bonafide_certificates SET status = 'revoked' WHERE id = {$cert_id}");
    header("Location: bonafide.php?msg=revoked");
    exit();
}

// ========================= DATA FETCH (ROLE BASED) =========================
$request_conditions = [];
$request_params = [];
$request_types = '';

if ($is_student) {
    $request_conditions[] = "r.student_id = ?";
    $request_params[] = $student_id;
    $request_types .= 's';
} elseif ($is_staff) {
    $request_conditions[] = "r.student_id IN (SELECT Student_ID_No FROM mentor_mentee WHERE Employee_ID_No = ? OR ClassAdvisorID = ?)";
    $request_params[] = $employee_id;
    $request_params[] = $employee_id;
    $request_types .= 'ss';
} elseif ($is_hod && $user_department !== '') {
    if (is_science_humanities_dept($user_department)) {
        $request_conditions[] = "(r.department = ? OR r.student_id IN (SELECT id_no FROM students_batch_25_26))";
        $request_params[] = $user_department;
        $request_types .= 's';
    } else {
        $request_conditions[] = "r.department = ? AND r.student_id NOT IN (SELECT id_no FROM students_batch_25_26)";
        $request_params[] = $user_department;
        $request_types .= 's';
    }
}

$request_conditions[] = "(r.admin_status IS NULL OR r.admin_status <> 'approved')";
$request_conditions[] = "(r.status IS NULL OR r.status <> 'Revoked')";

$request_sql = "SELECT r.* FROM bonafide_requests r WHERE " . implode(' AND ', $request_conditions) . " ORDER BY r.request_date DESC, r.id DESC";
$request_stmt = $mysqli->prepare($request_sql);
if (!empty($request_params)) {
    $request_stmt->bind_param($request_types, ...$request_params);
}
$request_stmt->execute();
$pending_requests = $request_stmt->get_result();

$cert_conditions = ["c.status = 'approved'"];
$cert_params = [];
$cert_types = '';

if ($is_student) {
    $student_tokens = session_student_identity_tokens();
    if (empty($student_tokens)) {
        $cert_conditions[] = "1 = 0";
    } else {
        $placeholders = implode(',', array_fill(0, count($student_tokens), '?'));
        $cert_conditions[] = "(c.student_id IN ({$placeholders}) OR c.register_number IN ({$placeholders}))";
        $cert_params = array_merge($cert_params, $student_tokens, $student_tokens);
        $cert_types .= str_repeat('s', count($student_tokens) * 2);
    }
} elseif ($is_staff) {
    $cert_conditions[] = "c.student_id IN (SELECT Student_ID_No FROM mentor_mentee WHERE Employee_ID_No = ? OR ClassAdvisorID = ?)";
    $cert_params[] = $employee_id;
    $cert_params[] = $employee_id;
    $cert_types .= 'ss';
} elseif ($is_hod && $user_department !== '') {
    if (is_science_humanities_dept($user_department)) {
        $cert_conditions[] = "(c.department = ? OR c.student_id IN (SELECT id_no FROM students_batch_25_26))";
        $cert_params[] = $user_department;
        $cert_types .= 's';
    } else {
        $cert_conditions[] = "c.department = ? AND c.student_id NOT IN (SELECT id_no FROM students_batch_25_26)";
        $cert_params[] = $user_department;
        $cert_types .= 's';
    }
}

$cert_sql = "SELECT c.* FROM bonafide_certificates c WHERE " . implode(' AND ', $cert_conditions) . " ORDER BY c.bonafide_date DESC, c.id DESC";
$cert_stmt = $mysqli->prepare($cert_sql);
if (!empty($cert_params)) {
    $cert_stmt->bind_param($cert_types, ...$cert_params);
}
$cert_stmt->execute();
$approved_certs = $cert_stmt->get_result();

// Statistics for admin/staff
if ($is_admin || $is_staff || $is_hod || $is_dean) {
    $total_requests = $mysqli->query("SELECT COUNT(*) as total FROM bonafide_requests")->fetch_assoc()['total'] ?? 0;
    $pending_count = $mysqli->query("SELECT COUNT(*) as total FROM bonafide_requests WHERE admin_status = 'pending'")->fetch_assoc()['total'] ?? 0;
    $approved_count = $mysqli->query("SELECT COUNT(*) as total FROM bonafide_certificates WHERE status = 'approved'")->fetch_assoc()['total'] ?? 0;
    $rejected_count = $mysqli->query("SELECT COUNT(*) as total FROM bonafide_requests WHERE admin_status = 'rejected'")->fetch_assoc()['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bonafide Certificate System | Vel Tech</title>

    <meta name="theme-color" content="#bc1888">
    <link rel="manifest" href="/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="VTHT Portal">
    <link rel="apple-touch-icon" href="../assets/images/icon-192.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        :root {
            --ig-gradient: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
            --primary: #bc1888;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --bg-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        body {
            background: var(--bg-gradient);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .ig-text {
            background: var(--ig-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .btn-ig {
            background: var(--ig-gradient);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-ig:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(188, 24, 136, 0.4);
            color: white;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
        }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
            color: white;
        }
        .stats-icon.total { background: var(--primary); }
        .stats-icon.pending { background: var(--warning); }
        .stats-icon.approved { background: var(--success); }
        .stats-icon.rejected { background: var(--danger); }

        .request-form-section, .preview-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        .student-info-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 2px solid #e9ecef;
        }
        .student-info-card h6 {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .student-info-card .value {
            font-weight: 600;
            color: #343a40;
            margin-bottom: 15px;
        }
        .certificate-preview {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            min-height: 500px;
            position: relative;
            overflow: hidden;
        }
        .certificate-preview .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.05;
            width: 300px;
            z-index: 0;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 10px 0;
            border-bottom: 1px solid #000;
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        .logo-container { flex: 0 0 75px; padding: 5px; }
        .college-logo { height: 70px; width: auto; object-fit: contain; }
        .college-info { flex: 1; text-align: center; padding: 0 10px; }
        .college-name { font-size: 32px; font-weight: 900; color: #1a237e; margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.6px; line-height: 1.1; }
        .college-subtitle { font-size: 16px; font-weight: 700; color: #0d47a1; margin-bottom: 3px; line-height: 1.1; }
        .college-details { font-size: 12px; color: #37474f; line-height: 1.3; }
        .college-details span { display: block; margin-bottom: 1px; }
        .college-accreditation { font-size: 10px; font-weight: 600; color: #00695c; margin-top: 2px; line-height: 1.2; }
        .contact-info { flex: 0 0 180px; text-align: right; font-size: 12px; line-height: 1.3; padding: 5px; border-left: 1px solid #e0e0e0; }
        .contact-phone { font-weight: 700; color: #0d47a1; margin-bottom: 3px; }
        .contact-email, .contact-website { color: #1565c0; margin: 2px 0; }
        .contact-address { color: #455a64; font-size: 12px; line-height: 1.2; margin-top: 4px; }
        .ref-container { display: flex; justify-content: space-between; align-items: center; margin: 8px 30px 12px; padding-bottom: 5px; border-bottom: 0.5px solid #ddd; }
        .ref-number { font-size: 12px; font-weight: 700; color: #b71c1c; background: #fff3cd; padding: 5px 10px; border-radius: 3px; border-left: 3px solid #b71c1c; display: inline-block; }
        .certificate-date { font-size: 12px; font-weight: 600; color: #1b5e20; background: #e8f5e9; padding: 5px 10px; border-radius: 3px; border-left: 3px solid #1b5e20; margin-left: 10px; display: inline-block; }
        .cert-title { text-align: center; font-size: 18pt; font-weight: bold; text-decoration: underline; margin: 20px 0 15px; text-transform: uppercase; }
        .cert-content { font-size: 12.5pt; line-height: 1.5; text-align: justify; position: relative; z-index: 1; }
        .cert-footer { margin-top: 40px; position: relative; height: 80px; }
        .cert-qr { position: absolute; left: 0; bottom: 0; }
        .cert-sign { position: absolute; right: 50px; bottom: 25px; font-weight: bold; font-size: 13pt; }

        .ig-table { background: white; border-radius: 10px; overflow: hidden; }
        .ig-table thead { background: var(--ig-gradient); color: white; }
        .ig-table th { border: none; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px; }
        .ig-table tbody tr { transition: all 0.3s ease; }
        .ig-table tbody tr:hover { background: #f8f9fa; transform: translateY(-2px); }

        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-revoked { background: #6c757d; color: white; }

        .action-btn {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-view { background: #17a2b8; color: white; }
        .btn-view:hover { background: #138496; transform: scale(1.1); }
        .btn-approve { background: #28a745; color: white; }
        .btn-approve:hover { background: #218838; transform: scale(1.1); }
        .btn-reject { background: #dc3545; color: white; }
        .btn-reject:hover { background: #c82333; transform: scale(1.1); }
        .btn-revoke { background: #6c757d; color: white; }
        .btn-revoke:hover { background: #5a6268; transform: scale(1.1); }
        .btn-print { background: #007bff; color: white; }
        .btn-print:hover { background: #0056b3; transform: scale(1.1); }

        .fee-table { border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 10pt; }
        .fee-table th { background: #f1f5f9; padding: 6px; text-align: center; border: 1px solid #000; font-weight: bold; }
        .fee-table td { padding: 6px; border: 1px solid #000; }
        .fee-table .amount { text-align: right; font-family: 'Courier New', monospace; font-weight: bold; }
        .fee-table .total-row { background: #e7f5ff; font-weight: bold; }

        .amount-words-box { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 1px solid #dee2e6; border-radius: 5px; padding: 10px; margin: 12px 0; font-family: 'Georgia', serif; min-height: auto; }
        .amount-words-box .numeric { font-size: 0.95rem; color: #dc3545; font-weight: bold; margin-bottom: 3px; font-family: 'Courier New', monospace; }
        .amount-words-box .words { font-size: 0.85rem; color: #495057; line-height: 1.3; text-transform: capitalize; }

        @media print {
            .no-print, .glass-card, .stats-card, .request-form-section, .preview-section, .btn-ig { display: none !important; }
            .certificate-preview { box-shadow: none; border: none; padding: 0; }
            .header-container { border-bottom: 1px solid #000; }
        }
    </style>
</head>
<body>
    <div class="loading-overlay">
        <div class="spinner"></div>
    </div>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="fw-bold display-6 mb-1">
                            <span class="ig-text">Bonafide Certificate</span> System
                        </h1>
                        <p class="text-muted mb-0">
                            <?php if($is_student): ?>
                                Welcome, <?= htmlspecialchars($student_details['name'] ?? 'Student') ?> | ID: <?= htmlspecialchars($student_id) ?>
                                <?php if(!$profile_complete): ?>
                                    <span class="badge bg-warning ms-2">Profile Incomplete</span>
                                <?php endif; ?>
                            <?php elseif($is_admin): ?>
                                Administrator Panel
                            <?php elseif($is_hod): ?>
                                HOD Panel - <?= htmlspecialchars($user_department) ?>
                            <?php elseif($is_dean): ?>
                                Dean Panel
                            <?php elseif($is_staff): ?>
                                Class Advisor Panel - <?= htmlspecialchars($user_name) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <?php if($is_admin): ?>
                            <a href="bonafide_issue.php" class="btn btn-ig">
                                <i class="fas fa-certificate me-2"></i> Direct Issue
                            </a>
                        <?php endif; ?>
                        <?php if(!$is_student): ?>
                            <a href="bonafide_dashboard.php" class="btn btn-ig">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if(!$is_student): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon total">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h3 class="fw-bold"><?= $total_requests ?? 0 ?></h3>
                    <p class="text-muted mb-0">Total Requests</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon pending">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="fw-bold"><?= $pending_count ?? 0 ?></h3>
                    <p class="text-muted mb-0">Pending</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon approved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="fw-bold"><?= $approved_count ?? 0 ?></h3>
                    <p class="text-muted mb-0">Approved</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon rejected">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <h3 class="fw-bold"><?= $rejected_count ?? 0 ?></h3>
                    <p class="text-muted mb-0">Rejected</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($is_student && !$profile_complete && !empty($profile_missing_fields)): ?>
        <div class="row mb-5">
            <div class="col-12">
                <div class="glass-card p-4">
                    <div class="d-flex align-items-center mb-3">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning me-3"></i>
                        <div>
                            <h5 class="fw-bold mb-1">Complete Your Profile First</h5>
                            <p class="mb-0">Please update mandatory information before submitting requests.</p>
                        </div>
                    </div>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($student_id) ?>">
                        <input type="hidden" name="table_source" value="<?= htmlspecialchars($table_source) ?>">

                        <?php foreach($profile_missing_fields as $field): ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= htmlspecialchars($field['label']) ?> *</label>
                            <?php if(strtolower($field['label']) === 'gender'): ?>
                                <select name="upd_<?= htmlspecialchars($field['col']) ?>" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            <?php elseif(strtolower($field['label']) === 'section'): ?>
                                <select name="upd_<?= htmlspecialchars($field['col']) ?>" class="form-select" required>
                                    <option value="">Select Section</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                </select>
                            <?php else: ?>
                                <input type="text" name="upd_<?= htmlspecialchars($field['col']) ?>" class="form-control"
                                       placeholder="Enter <?= htmlspecialchars($field['label']) ?>" required>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <div class="col-12">
                            <button type="submit" name="update_profile" class="btn btn-ig">
                                <i class="fas fa-save me-2"></i> Update Profile & Continue
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($is_student && $profile_complete): ?>
        <div class="row mb-5">
            <div class="col-lg-5 mb-4">
                <div class="request-form-section">
                    <h4 class="fw-bold mb-4">
                        <i class="fas fa-file-alt me-2"></i> Request New Bonafide
                    </h4>

                    <?php if(isset($message) && $message): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="student-info-card">
                        <div class="row">
                            <div class="col-6">
                                <h6>Name</h6>
                                <div class="value"><?= htmlspecialchars($student_details['name']) ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Register No</h6>
                                <div class="value"><?= htmlspecialchars($student_details['register_no']) ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Department</h6>
                                <div class="value"><?= htmlspecialchars($student_details['department']) ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Batch</h6>
                                <div class="value"><?= htmlspecialchars($student_details['batch']) ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Section</h6>
                                <div class="value"><?= htmlspecialchars($student_details['section'] ?: 'N/A') ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Father's Name</h6>
                                <div class="value"><?= htmlspecialchars($student_details['father_name'] ?: 'N/A') ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Mother's Name</h6>
                                <div class="value"><?= htmlspecialchars($student_details['mother_name'] ?: 'N/A') ?></div>
                            </div>
                            <div class="col-6">
                                <h6>Community</h6>
                                <div class="value"><?= htmlspecialchars($student_details['community'] ?: 'N/A') ?></div>
                            </div>
                            <div class="col-12">
                                <h6>Scholarship</h6>
                                <div class="value">
                                    <?= htmlspecialchars($fee_details['scholarship_type'] ?? 'None') ?>
                                    <?php if($fee_details && in_array(trim($fee_details['scholarship_type'] ?? ''), ['7.5', '7.5%'], true)): ?>
                                        <span class="badge bg-warning ms-2">Fee certificates restricted</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if($transport_details): ?>
                            <div class="col-12">
                                <h6>Bus Transport</h6>
                                <div class="value">
                                    Route: <?= htmlspecialchars($transport_details['route_no'] ?? '') ?>
                                    <div class="mt-1 small">
                                        Fee: ₹<?= number_format(calculate_transport_fee($transport_details['route_no'] ?? '', $transport_details['fee_amount'] ?? ''), 2) ?>
                                    </div>
                                    <?php if(!empty($transport_details['pickup_point'])): ?>
                                        <div class="mt-1 small">Pickup: <?= htmlspecialchars($transport_details['pickup_point']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if($hostel_details): ?>
                            <div class="col-12">
                                <h6>Hostel</h6>
                                <div class="value">
                                    <?= htmlspecialchars($hostel_details['hostel_name']) ?> (<?= htmlspecialchars($hostel_details['room_type']) ?>)
                                    <div class="mt-1 small">
                                        Fee: ₹<?= number_format(calculate_hostel_fee($hostel_details['room_type'] ?? 'Non-AC'), 2) ?>
                                    </div>
                                    <?php if(!empty($hostel_details['room_no']) && $hostel_details['room_no'] !== 'Allocated'): ?>
                                        <div class="mt-1 small">Room: <?= htmlspecialchars($hostel_details['room_no']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if(!$transport_details && !$hostel_details): ?>
                            <div class="col-12">
                                <h6>Facility</h6>
                                <div class="value">Day Scholar (Own Transport)</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" id="requestForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Type of Certificate *</label>
                            <select name="cert_type" id="cert_type" class="form-select" required onchange="updatePreview();">
                                <option value="">Select Certificate Type</option>
                                <option value="General">General Bonafide</option>
                                <?php if(!($fee_details && in_array(trim($fee_details['scholarship_type'] ?? ''), ['7.5', '7.5%'], true))): ?>
                                    <option value="Fee Structure">Fee Structure</option>
                                    <option value="Fee Paid">Fee Paid</option>
                                <?php endif; ?>
                                <option value="Internship">Internship</option>
                                <option value="Project">Project</option>
                            </select>
                            <?php if($fee_details && in_array(trim($fee_details['scholarship_type'] ?? ''), ['7.5', '7.5%'], true)): ?>
                                <small class="text-warning">7.5% Scholarship holders cannot request Fee Structure/Fee Paid</small>
                            <?php else: ?>
                                <small class="text-muted">Academic years and fee details will be filled by Admin during approval.</small>
                            <?php endif; ?>
                        </div>
                        <?php
                            $route_value = strtoupper(trim((string) ($transport_details['route_no'] ?? '')));
                            if ($route_value !== '' && stripos($route_value, 'AC') !== false) {
                                $route_value = 'AC';
                            }
                            $facility_default = $facility_option ?? 'None';
                        ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Facility Option</label>
                            <select name="facility_option" id="facility_option" class="form-select" onchange="toggleFacilityFields(); updatePreview();">
                                <option value="None" <?= $facility_default === 'None' ? 'selected' : '' ?>>None (Day Scholar)</option>
                                <option value="Transport" <?= $facility_default === 'Transport' ? 'selected' : '' ?>>Bus</option>
                                <option value="Hostel" <?= $facility_default === 'Hostel' ? 'selected' : '' ?>>Hostel</option>
                            </select>
                            <small class="text-muted">Choose Bus/Hostel if you are availing facility.</small>
                        </div>

                        <div class="mb-3" id="bus_route_container" style="display: none;">
                            <label class="form-label fw-bold">Bus Route</label>
                            <select name="bus_zone" id="bus_zone" class="form-select" onchange="updatePreview();">
                                <option value="">Select Route</option>
                                <option value="R1" <?= $route_value === 'R1' ? 'selected' : '' ?>>R1 - Rs. 20,000</option>
                                <option value="R2" <?= $route_value === 'R2' ? 'selected' : '' ?>>R2 - Rs. 37,000</option>
                                <option value="R3" <?= $route_value === 'R3' ? 'selected' : '' ?>>R3 - Rs. 40,000</option>
                                <option value="AC" <?= $route_value === 'AC' ? 'selected' : '' ?>>AC Bus - Rs. 50,000</option>
                            </select>
                            <small class="text-muted">Select your route if you are using college transport.</small>
                        </div>

                        <?php if(!empty($student_details['mother_name'])): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Parent Name for Certificate</label>
                            <select name="parent_choice" id="parent_choice" class="form-select" onchange="updatePreview()">
                                <option value="father" selected>Father Name (Default)</option>
                                <option value="mother">Mother Name</option>
                            </select>
                            <small class="text-muted">If the mother name is incorrect, update your profile first.</small>
                        </div>
                        <?php endif; ?>

                        <?php if(empty($class_advisor_id)): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Class Advisor *</label>
                            <input type="text" name="class_advisor_pick" class="form-control" list="advisorList" required
                                   placeholder="Type or select your Class Advisor ID">
                            <?php if(!empty($department_faculty)): ?>
                                <datalist id="advisorList">
                                    <?php foreach($department_faculty as $faculty): ?>
                                        <option value="<?= htmlspecialchars($faculty['id']) ?>"><?= htmlspecialchars($faculty['name']) ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">Start typing to pick from your department's faculty list.</small>
                            <?php else: ?>
                                <small class="text-muted">Enter the advisor ID (faculty list not available).</small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Purpose *</label>
                            <select name="purpose" id="purpose" class="form-select" required onchange="updatePreview()">
                                <option value="">Select Purpose</option>
                                <option value="Passport">Passport</option>
                                <option value="Scholarship">Scholarship</option>
                                <option value="Higher Education">Higher Education</option>
                                <option value="Employment">Employment</option>
                                <option value="Nalavariyam Scholarship">Nalavariyam Scholarship</option>
                                <option value="Nalavariyam Scholarship for Staying in College Hostel">Nalavariyam Scholarship for Staying in College Hostel</option>
                                <option value="Education Loan">Education Loan</option>
                                <option value="Visa Process">Visa Process</option>
                                <option value="Internship Application">Internship Application</option>
                                <option value="Bank Account">Bank Account</option>
                                <option value="Other">Other</option>
                            </select>
                            <div id="other_purpose_container" style="display:none;" class="mt-2">
                                <input type="text" name="purpose_other" id="purpose_other" class="form-control" placeholder="Specify other purpose" oninput="updatePreview()">
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" name="submit_request" class="btn btn-ig">
                                <i class="fas fa-paper-plane me-2"></i> Submit Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-7 mb-4">
                <div class="preview-section">
                    <div class="certificate-preview">
                        <img src="https://www.velhightech.com/LP/logo.png" class="watermark" alt="Watermark">
                        <div class="header-container">
                            <div class="logo-container">
                                <img src="https://www.velhightech.com/LP/logo.png" alt="Vel Tech Logo" class="college-logo">
                            </div>
                            <div class="college-info">
                                <div class="college-name">Vel Tech High Tech</div>
                                <div class="college-subtitle">Dr. Rangarajan Dr. Sakunthala Engineering College</div>
                                <div class="college-details">
                                    <span>An Autonomous Institution</span>
                                    <span>(Approved by AICTE, New Delhi, Affiliated to Anna University, Chennai)</span>
                                </div>
                                <div class="college-accreditation">
                                    <span>Accredited by NAAC with 'A' Grade & CGPA 3.27 | </span>
                                    <span>NBA Accredited Programs (ECE, IT, Biotech & Chemical Engineering)</span>
                                </div>
                            </div>
                            <div class="contact-info">
                                <div class="contact-phone">Mobile: 9789037651<br>Tel: 044-26840181</div>
                                <div class="contact-email">principal@velhightech.com</div>
                                <div class="contact-website">www.velhightech.com</div>
                                <div class="contact-address">
                                    <strong>Address:</strong><br>#60, Avadi-Alamathi Road,<br>Morai Village, Vellanur Post,<br>Avadi Taluk, Thiruvallur District,<br>600062, Tamil Nadu, India.
                                </div>
                            </div>
                        </div>

                        <div class="ref-container">
                            <div class="ref-number" id="preview_ref">Ref/VH/BONA/<?= current_academic_year() ?>/001</div>
                            <div class="certificate-date">Date: Not Approved</div>
                        </div>

                        <div class="cert-content">
                            <div id="preview_title" class="cert-title">BONAFIDE CERTIFICATE</div>
                            <div id="preview_content">
                                <p>Please select certificate type and purpose to preview.</p>
                            </div>

                            <div class="cert-footer">
                                <div class="cert-qr">
                                    <div style="width: 75px; height: 75px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 8px; text-align: center;">
                                        QR CODE<br>AFTER APPROVAL
                                    </div>
                                </div>
                                <div class="cert-sign">PRINCIPAL</div>
                                <div style="position: absolute; right: 10px; bottom: 5px; font-size: 10px; color: #666;">
                                    <i>Space for College Seal</i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section B: Requests -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="glass-card p-4">
                    <?php if($is_admin && isset($_GET['year_missing'])): ?>
                        <div class="alert alert-warning mb-3">
                            Please select at least one academic year before saving or approving.
                        </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0">
                            <i class="fas fa-inbox me-2"></i>
                            <?php if($is_student): ?>My Requests<?php else: ?>Bonafide Requests<?php endif; ?>
                        </h4>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-success btn-sm" onclick="refreshRequests()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <?php if($pending_requests->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table ig-table" id="requestsTable">
                            <thead>
                                <tr>
                                    <th>S.No.</th>
                                    <th>Request ID</th>
                                    <th>Request Date</th>
                                    <?php if(!$is_student): ?><th>Student Details</th><?php endif; ?>
                                    <th>Profile</th>
                                    <th>Certificate Type</th>
                                    <th>Purpose</th>
                                    <th>Facility</th>
                                    <th>Advisor</th>
                                    <th>HOD</th>
                                    <th>Dean</th>
                                    <th>Admin</th>
                                    <th>Demo View</th>
                                    <?php if($is_student): ?><th>Revoke</th><?php endif; ?>
                                    <?php if(!$is_student): ?><th>Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $serial = 1;
                                while ($request = $pending_requests->fetch_assoc()):
                                    $advisor_status = $request['advisor_status'] ?? 'pending';
                                    $hod_status = $request['hod_status'] ?? 'pending';
                                    $dean_status = $request['dean_status'] ?? 'pending';
                                    $admin_status = $request['admin_status'] ?? 'pending';
                                    $facility_display = facility_display_for_request($mysqli, $request);
                                ?>
                                <tr>
                                    <td><?= $serial++ ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($request['request_number'] ?: generate_request_number($request['id'])) ?></td>
                                    <td><?= format_datetime_display($request['request_date'] ?? $request['RequestDate']) ?></td>

                                    <?php if(!$is_student): ?>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($request['student_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($request['register_number']) ?> | <?= htmlspecialchars($request['department']) ?></small>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <button class="action-btn btn-view" onclick="openProfileView(<?= (int) $request['id'] ?>)">
                                            <i class="fas fa-user"></i>
                                        </button>
                                    </td>

                                    <td>
                                        <span class="status-badge badge-pending"><?= htmlspecialchars($request['bonafide_type']) ?></span>
                                    </td>
                                    <td class="text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($request['purpose']) ?>">
                                        <?= htmlspecialchars($request['purpose']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($facility_display) ?></td>
                                    <td>
                                        <span class="status-badge <?= status_badge_class($advisor_status) ?>">
                                            <?= ucfirst($advisor_status) ?>
                                        </span>
                                        <div class="small text-muted"><?= format_datetime_display($request['advisor_action_date']) ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= status_badge_class($hod_status) ?>">
                                            <?= ucfirst($hod_status) ?>
                                        </span>
                                        <div class="small text-muted"><?= format_datetime_display($request['hod_approval']) ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= status_badge_class($dean_status) ?>">
                                            <?= ucfirst($dean_status) ?>
                                        </span>
                                        <div class="small text-muted"><?= format_datetime_display($request['dean_approval']) ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= status_badge_class($admin_status) ?>">
                                            <?= ucfirst($admin_status) ?>
                                        </span>
                                        <div class="small text-muted"><?= format_datetime_display($request['admin_action_date']) ?></div>
                                    </td>
                                    <td>
                                        <button class="action-btn btn-view" onclick="openDemoView(<?= (int) $request['id'] ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>

                                    <?php if($is_student): ?>
                                        <td>
                                            <?php if($advisor_status === 'pending'): ?>
                                                <button class="action-btn btn-revoke" onclick="revokeRequest(<?= (int) $request['id'] ?>)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">Locked</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>

                                    <?php if(!$is_student): ?>
                                        <td>
                                            <?php if($is_staff && $advisor_status === 'pending'): ?>
                                                <div class="d-flex gap-1">
                                                    <button class="action-btn btn-approve" onclick="submitAction(<?= (int) $request['id'] ?>, 'approve')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn btn-reject" onclick="submitAction(<?= (int) $request['id'] ?>, 'reject')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            <?php elseif($is_hod && $advisor_status === 'approved' && $hod_status === 'pending'): ?>
                                                <div class="d-flex gap-1">
                                                    <button class="action-btn btn-approve" onclick="submitAction(<?= (int) $request['id'] ?>, 'approve')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn btn-reject" onclick="submitAction(<?= (int) $request['id'] ?>, 'reject')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            <?php elseif($is_dean && $hod_status === 'approved' && $dean_status === 'pending'): ?>
                                                <div class="d-flex gap-1">
                                                    <button class="action-btn btn-approve" onclick="submitAction(<?= (int) $request['id'] ?>, 'approve')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn btn-reject" onclick="submitAction(<?= (int) $request['id'] ?>, 'reject')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            <?php elseif($is_admin && $admin_status !== 'approved'): ?>
                                                <?php if(in_array($request['bonafide_type'], ['Fee Structure', 'Fee Paid'], true)): ?>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="openApproveFeeModal(<?= (int) $request['id'] ?>)">
                                                        <i class="fas fa-pen me-1"></i> Approve + Set Fees
                                                    </button>
                                                    <button class="action-btn btn-reject ms-1" onclick="submitAction(<?= (int) $request['id'] ?>, 'reject')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <div class="d-flex gap-1">
                                                        <button class="action-btn btn-approve" onclick="submitAction(<?= (int) $request['id'] ?>, 'approve')">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button class="action-btn btn-reject" onclick="submitAction(<?= (int) $request['id'] ?>, 'reject')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">No action</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                        </div>
                        <h5 class="fw-bold">No Pending Requests</h5>
                        <p class="text-muted">
                            <?php if($is_student): ?>
                                You have no open requests.
                            <?php else: ?>
                                There are no pending requests at the moment.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section C: Certificates -->
        <div class="row">
            <div class="col-12">
                <div class="glass-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0">
                            <i class="fas fa-certificate me-2"></i>
                            <?php if($is_student): ?>My Certificates<?php else: ?>Approved Certificates<?php endif; ?>
                        </h4>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-success btn-sm" onclick="refreshCertificates()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <?php if($approved_certs->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table ig-table" id="certificatesTable">
                            <thead>
                                <tr>
                                    <th>S.No.</th>
                                    <th>Reference</th>
                                    <th>Certificate Date</th>
                                    <?php if(!$is_student): ?><th>Student Details</th><?php endif; ?>
                                    <th>Type</th>
                                    <th>Purpose</th>
                                    <?php if(!$is_student): ?><th>Fees</th><?php endif; ?>
                                    <th>View</th>
                                    <th>Print</th>
                                    <?php if($is_admin): ?>
                                        <th>Revoke</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial = 1; while ($cert = $approved_certs->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $serial++ ?></td>
                                    <td class="fw-bold">
                                        <span class="d-block"><?= htmlspecialchars($cert['ref_number']) ?></span>
                                        <small class="text-muted">ID: <?= htmlspecialchars($cert['id']) ?></small>
                                    </td>
                                    <td><?= date('d M Y', strtotime($cert['bonafide_date'])) ?></td>

                                    <?php if(!$is_student): ?>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($cert['student_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($cert['register_number']) ?> | <?= htmlspecialchars($cert['department']) ?></small>
                                        </td>
                                    <?php endif; ?>

                                    <td><span class="status-badge badge-approved"><?= htmlspecialchars($cert['certificate_type']) ?></span></td>
                                    <td class="text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($cert['purpose']) ?>">
                                        <?= htmlspecialchars($cert['purpose']) ?>
                                    </td>
                                    <?php if(!$is_student): ?>
                                        <td>
                                            <?php if(in_array($cert['certificate_type'], ['Fee Structure', 'Fee Paid'], true)): ?>
                                                <div class="small">
                                                    Tuition: ₹<?= number_format((float) $cert['tuition_fee'], 2) ?><br>
                                                    Other: ₹<?= number_format((float) $cert['other_fee'], 2) ?><br>
                                                    Facility: ₹<?= number_format((float) (($cert['facility_option'] === 'Transport') ? $cert['bus_fee'] : $cert['hostel_fee']), 2) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <button class="action-btn btn-view" onclick="openCertificateView(<?= (int) $cert['id'] ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <button class="action-btn btn-print" onclick="printCertificate(<?= (int) $cert['id'] ?>)">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </td>
                                    <?php if($is_admin): ?>
                                        <td>
                                            <?php if($cert['status'] === 'approved'): ?>
                                                <button class="action-btn btn-revoke" onclick="revokeCertificate(<?= (int) $cert['id'] ?>)">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="status-badge badge-revoked">Revoked</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="fas fa-file-certificate text-muted" style="font-size: 3rem;"></i>
                        </div>
                        <h5 class="fw-bold">No Certificates Found</h5>
                        <p class="text-muted">
                            <?php if($is_student): ?>
                                You have no issued certificates yet.
                            <?php else: ?>
                                No certificates have been issued yet.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Demo View Modal -->
    <div class="modal fade demo-modal" id="demoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--ig-gradient); color: white;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-eye me-2"></i> Demo Preview</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" id="demoModalBody">
                    <div class="text-center text-muted">Loading preview...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Certificate View Modal -->
    <div class="modal fade" id="certificateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--ig-gradient); color: white;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-certificate me-2"></i> Certificate View</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" id="certificateModalBody">
                    <div class="text-center text-muted">Loading certificate...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile View Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--ig-gradient); color: white;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-user me-2"></i> Student Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" id="profileModalBody">
                    <div class="text-center text-muted">Loading profile...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Approval Modal (Fees + Academic Years) -->
    <div class="modal fade" id="approveFeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Request - Fee Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="approveFeeForm">
                    <div class="modal-body">
                        <input type="hidden" name="request_action" id="approve_action_type" value="approve">
                        <input type="hidden" name="request_id" id="approve_request_id">

                        <div class="alert alert-info mb-3" id="approve_student_info">
                            Loading student info...
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Tuition Fee (Rs.)</label>
                                <input type="number" name="tuition_fee" id="approve_tuition_fee" class="form-control" required min="0" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Other Fee (Rs.)</label>
                                <input type="number" name="other_fee" id="approve_other_fee" class="form-control" required min="0" step="0.01">
                            </div>
                            <div class="col-md-6" id="hostel_fee_group">
                                <label class="form-label">Hostel Fee (Rs.)</label>
                                <input type="number" name="hostel_fee" id="approve_hostel_fee" class="form-control" min="0" step="0.01">
                            </div>
                            <div class="col-md-6" id="bus_fee_group">
                                <label class="form-label">Bus Fee (Rs.)</label>
                                <input type="number" name="bus_fee" id="approve_bus_fee" class="form-control" min="0" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Facility Option</label>
                                <select name="facility_option" id="approve_facility_option" class="form-select">
                                    <option value="None">None</option>
                                    <option value="Hostel">Hostel</option>
                                    <option value="Transport">Transport</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-none">
                                <label class="form-label">Bus Type</label>
                                <select name="bus_type" id="approve_bus_type" class="form-select">
                                    <option value="None">None</option>
                                    <option value="AC">AC</option>
                                    <option value="Regular">Regular</option>
                                </select>
                            </div>
                            <div class="col-md-3" id="bus_zone_group">
                                <label class="form-label">Bus Route</label>
                                <select name="bus_zone" id="approve_bus_zone" class="form-select">
                                    <option value="None">None</option>
                                    <option value="R1">R1</option>
                                    <option value="R2">R2</option>
                                    <option value="R3">R3</option>
                                    <option value="AC">AC Bus</option>
                                </select>
                            </div>

                            <div class="col-12" id="paid_fee_section" style="display: none;">
                                <div class="border rounded p-3 bg-light">
                                    <div class="fw-bold mb-2">Fee Paid Details (Admin Entry)</div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Tuition Fee Paid (Rs.)</label>
                                            <input type="number" name="paid_tuition_fee" id="approve_paid_tuition_fee" class="form-control" min="0" step="0.01">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Other Fee Paid (Rs.)</label>
                                            <input type="number" name="paid_other_fee" id="approve_paid_other_fee" class="form-control" min="0" step="0.01">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Facility Fee Paid (Rs.)</label>
                                            <input type="number" name="paid_hostel_fee" id="approve_paid_hostel_fee" class="form-control" min="0" step="0.01">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold">Academic Years (Admin Only)</label>
                                <div class="d-flex flex-wrap gap-2" id="approve_academic_years">
                                    <label><input type="checkbox" name="academic_years[]" value="2025-2026"> 2025-2026</label>
                                    <label><input type="checkbox" name="academic_years[]" value="2024-2025"> 2024-2025</label>
                                    <label><input type="checkbox" name="academic_years[]" value="2023-2024"> 2023-2024</label>
                                    <label><input type="checkbox" name="academic_years[]" value="2022-2023"> 2022-2023</label>
                                    <label><input type="checkbox" name="academic_years[]" value="2021-2022"> 2021-2022</label>
                                </div>
                                <small class="text-muted d-block mt-2">Select the academic years to print on the certificate.</small>
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="note_details" id="approve_note_details" value="1" checked>
                                    <label class="form-check-label" for="approve_note_details">Include bank details note</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-outline-primary" onclick="submitFeeAction('save_fees')">Save Fees</button>
                        <button type="button" class="btn btn-primary" onclick="submitFeeAction('approve')">Approve & Generate Certificate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="actionForm" class="d-none">
        <input type="hidden" name="request_action" id="action_type">
        <input type="hidden" name="request_id" id="action_request_id">
        <input type="hidden" name="remarks" id="action_remarks">
    </form>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#requestsTable').DataTable({ pageLength: 10, order: [[2, 'desc']], responsive: true });
            $('#certificatesTable').DataTable({ pageLength: 10, order: [[2, 'desc']], responsive: true });

            $('#purpose').change(function() {
                const otherContainer = $('#other_purpose_container');
                if (this.value === 'Other') {
                    otherContainer.show();
                } else {
                    otherContainer.hide();
                }
                updatePreview();
            });
        });

        function showLoading() { document.querySelector('.loading-overlay').style.display = 'flex'; }
        function hideLoading() { document.querySelector('.loading-overlay').style.display = 'none'; }

        function submitAction(requestId, action) {
            let remarks = '';
            if (action === 'reject') {
                remarks = prompt('Enter rejection remarks (required):', '');
                if (!remarks) { return; }
            }
            document.getElementById('action_type').value = action;
            document.getElementById('action_request_id').value = requestId;
            document.getElementById('action_remarks').value = remarks;
            document.getElementById('actionForm').submit();
        }

        function revokeRequest(requestId) {
            if (confirm('Are you sure you want to revoke this request?')) {
                showLoading();
                window.location.href = `?revoke_request=${requestId}`;
            }
        }

        function revokeCertificate(certId) {
            if (confirm('Are you sure you want to revoke this certificate?')) {
                showLoading();
                window.location.href = `?revoke_cert=${certId}`;
            }
        }

        function refreshRequests() { showLoading(); location.reload(); }
        function refreshCertificates() { showLoading(); location.reload(); }

        function openDemoView(requestId) {
            fetch(`ajax/bonafide_demo_view.php?id=${requestId}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('demoModalBody').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('demoModal')).show();
                });
        }

        function openCertificateView(certId) {
            fetch(`ajax/bonafide_certificate_view.php?id=${certId}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('certificateModalBody').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('certificateModal')).show();
                });
        }

        function printCertificate(certId) {
            window.open(`ajax/bonafide_certificate_view.php?id=${certId}&print=1`, '_blank');
        }

        function openApproveFeeModal(requestId) {
            fetch(`ajax/get_request_data.php?id=${requestId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        alert(data.error || 'Unable to load request data');
                        return;
                    }
                    document.getElementById('approve_request_id').value = requestId;
                    document.getElementById('approve_action_type').value = 'approve';
                    document.getElementById('approve_tuition_fee').value = data.defaults.tuition_fee || 0;
                    document.getElementById('approve_other_fee').value = data.defaults.other_fee || 0;
                    document.getElementById('approve_hostel_fee').value = data.defaults.hostel_fee || 0;
                    document.getElementById('approve_bus_fee').value = data.defaults.bus_fee || 0;
                    document.getElementById('approve_facility_option').value = data.defaults.facility_option || 'None';
                    document.getElementById('approve_bus_type').value = data.defaults.bus_type || 'None';
                    document.getElementById('approve_bus_zone').value = data.defaults.bus_zone || 'None';
                    document.getElementById('approve_bus_type').value = (data.defaults.bus_zone === 'AC') ? 'AC' : (data.defaults.bus_zone && data.defaults.bus_zone !== 'None' ? 'Regular' : 'None');
                    document.getElementById('approve_note_details').checked = (data.defaults.note_details === 'Yes');

                    const isFeePaid = (data.request.bonafide_type === 'Fee Paid');
                    const paidSection = document.getElementById('paid_fee_section');
                    paidSection.style.display = isFeePaid ? 'block' : 'none';
                    document.getElementById('approve_paid_tuition_fee').value = data.defaults.paid_tuition_fee || 0;
                    document.getElementById('approve_paid_other_fee').value = data.defaults.paid_other_fee || 0;
                    document.getElementById('approve_paid_hostel_fee').value = data.defaults.paid_hostel_fee || 0;
                    document.getElementById('approve_paid_tuition_fee').required = isFeePaid;
                    document.getElementById('approve_paid_other_fee').required = isFeePaid;
                    document.getElementById('approve_paid_hostel_fee').required = isFeePaid;
                    toggleAdminFacilityFields();

                    const yearsContainer = document.getElementById('approve_academic_years');
                    const yearChecks = yearsContainer.querySelectorAll('input[type="checkbox"]');
                    yearChecks.forEach(cb => cb.checked = false);
                    if (data.defaults.academic_year) {
                        data.defaults.academic_year.split(',').forEach(yr => {
                            const val = yr.trim();
                            if (!val) return;
                            yearChecks.forEach(cb => {
                                if (cb.value === val) {
                                    cb.checked = true;
                                }
                            });
                        });
                    } else {
                        yearChecks.forEach(cb => {
                            if (cb.value === '2025-2026') {
                                cb.checked = true;
                            }
                        });
                    }

                    const facilityDisplay = (data.defaults.facility_option === 'None')
                        ? 'Day Scholar (Own Transport)'
                        : `${data.defaults.facility_option} ${data.defaults.facility_detail ? '(' + data.defaults.facility_detail + ')' : ''}`;
                    const info = `
                        <strong>${data.request.student_name}</strong> (${data.request.register_number})<br>
                        Dept: ${data.request.department} | Batch: ${data.request.batch}<br>
                        Community: ${data.student.community || 'N/A'} | Scholarship: ${data.fee.scholarship_type || 'None'}<br>
                        Facility: ${facilityDisplay}
                    `;
                    document.getElementById('approve_student_info').innerHTML = info;
                    new bootstrap.Modal(document.getElementById('approveFeeModal')).show();
                });
        }

        function submitFeeAction(action) {
            const yearChecks = document.querySelectorAll('#approve_academic_years input[type="checkbox"]');
            const hasYear = Array.from(yearChecks).some(cb => cb.checked);
            if (!hasYear) {
                alert('Please select at least one academic year.');
                return;
            }
            document.getElementById('approve_action_type').value = action;
            document.getElementById('approveFeeForm').submit();
        }

        function openProfileView(requestId) {
            fetch(`ajax/bonafide_profile_view.php?id=${requestId}`)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('profileModalBody').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('profileModal')).show();
                });
        }

        <?php if($is_student && $profile_complete): ?>
        const studentData = {
            studentName: "<?= htmlspecialchars($student_details['name']) ?>",
            registerNo: "<?= htmlspecialchars($student_details['register_no']) ?>",
            department: "<?= htmlspecialchars($student_details['department']) ?>",
            fatherName: "<?= htmlspecialchars($student_details['father_name'] ?? 'Parent') ?>",
            motherName: "<?= htmlspecialchars($student_details['mother_name'] ?? '') ?>",
            gender: "<?= htmlspecialchars($student_details['gender'] ?? 'Male') ?>",
            batch: "<?= htmlspecialchars($student_details['batch'] ?? '2021-2025') ?>",
            section: "<?= htmlspecialchars($student_details['section'] ?? 'A') ?>"
        };

        function toggleFacilityFields() {
            const facilityOption = document.getElementById('facility_option')?.value || 'None';
            const busContainer = document.getElementById('bus_route_container');
            const busSelect = document.getElementById('bus_zone');
            if (facilityOption === 'Transport') {
                busContainer.style.display = 'block';
                if (busSelect) busSelect.required = true;
            } else {
                busContainer.style.display = 'none';
                if (busSelect) {
                    busSelect.required = false;
                    busSelect.value = '';
                }
            }
        }

        function updatePreview() {
            const type = document.getElementById('cert_type').value;
            const purposeSelect = document.getElementById('purpose');
            const purposeVal = purposeSelect.value;
            const otherPurpose = document.getElementById('purpose_other')?.value || '';
            const purpose = (purposeVal === 'Other' && otherPurpose) ? otherPurpose : purposeVal;
            const facilityOption = document.getElementById('facility_option')?.value || 'None';
            const busZone = document.getElementById('bus_zone')?.value || '';

            if (!type || !purpose) {
                document.getElementById('preview_title').innerText = "BONAFIDE CERTIFICATE";
                document.getElementById('preview_content').innerHTML = '<p>Please select certificate type and purpose to preview.</p>';
                return;
            }

            const yearPursuing = "<?= calculate_year_pursuing($student_details['batch'] ?? '') ?>";
            const isFemale = studentData.gender.toLowerCase().includes('female') || studentData.gender.toLowerCase().startsWith('f');
            const studentPrefix = isFemale ? 'Ms.' : 'Mr.';
            const pronounSubject = isFemale ? 'She' : 'He';
            const relationship = isFemale ? 'Daughter of' : 'Son of';
            const parentChoice = document.getElementById('parent_choice')?.value || 'father';
            const parentName = parentChoice === 'mother' && studentData.motherName ? studentData.motherName : studentData.fatherName;
            const parentPrefix = parentChoice === 'mother' ? 'Mrs.' : 'Mr.';

            let title = "BONAFIDE CERTIFICATE";
            let content = "";
            const feePlaceholder = '<span class="text-muted">To be entered by admin</span>';
            const busLabelMap = {
                'R1': 'R1 - Rs. 20,000',
                'R2': 'R2 - Rs. 37,000',
                'R3': 'R3 - Rs. 40,000',
                'AC': 'AC Bus - Rs. 50,000'
            };
            let facilityLabel = '';
            if (facilityOption === 'Transport') {
                const routeLabel = busLabelMap[busZone] || busZone;
                facilityLabel = 'Transport Fee' + (routeLabel ? ` (${routeLabel})` : '');
            } else if (facilityOption === 'Hostel') {
                facilityLabel = 'Hostel Fee';
            }
            const facilityRow = facilityLabel
                ? `<tr><td>${facilityLabel}</td><td class="amount">${feePlaceholder}</td></tr>`
                : '';

            switch (type) {
                case 'General':
                    title = "BONAFIDE CERTIFICATE";
                    content = `
                        <p>This is to certify that <strong>${studentPrefix} ${studentData.studentName}</strong> (Register No: <strong>${studentData.registerNo}</strong>),
                        ${relationship} <strong>${parentPrefix} ${parentName}</strong>, is a bonafide student of our Institution, pursuing
                        <strong>${yearPursuing}</strong> Year B.E / B.Tech in <strong>${studentData.department}</strong> (Section ${studentData.section}).</p>
                        <p>${pronounSubject} bears a good moral character and conduct.</p>
                        <p>This certificate is issued on the request of the student for the purpose of <strong>${purpose}</strong>.</p>
                    `;
                    break;
                case 'Fee Structure':
                    title = "BONAFIDE CERTIFICATE FOR FEE STRUCTURE";
                    content = `
                        <p>This is to certify that <strong>${studentPrefix} ${studentData.studentName}</strong> (Register No: <strong>${studentData.registerNo}</strong>),
                        ${relationship} <strong>${parentPrefix} ${parentName}</strong>, is a bonafide student of our Institution, pursuing
                        <strong>${yearPursuing}</strong> Year ${studentData.department} (Section ${studentData.section}).</p>
                        <p><strong>FEE STRUCTURE (Admin will fill final values)</strong></p>
                        <table class="fee-table">
                            <tr><td>Tuition Fee</td><td class="amount">${feePlaceholder}</td></tr>
                            <tr><td>Other Fee</td><td class="amount">${feePlaceholder}</td></tr>
                            ${facilityRow}
                            <tr class="total-row"><td><strong>Total</strong></td><td class="amount"><strong>${feePlaceholder}</strong></td></tr>
                        </table>
                        <p>This certificate is issued for the purpose of <strong>${purpose}</strong>.</p>
                    `;
                    break;
                case 'Fee Paid':
                    title = "BONAFIDE CERTIFICATE FOR FEE PAID";
                    content = `
                        <p>This is to certify that <strong>${studentPrefix} ${studentData.studentName}</strong> (Register No: <strong>${studentData.registerNo}</strong>),
                        ${relationship} <strong>${parentPrefix} ${parentName}</strong>, is a bonafide student of our Institution, pursuing
                        <strong>${yearPursuing}</strong> Year ${studentData.department} (Section ${studentData.section}).</p>
                        <p><strong>FEE STRUCTURE</strong></p>
                        <table class="fee-table">
                            <tr><td>Tuition Fee</td><td class="amount">${feePlaceholder}</td></tr>
                            <tr><td>Other Fee</td><td class="amount">${feePlaceholder}</td></tr>
                            ${facilityRow}
                            <tr class="total-row"><td><strong>Total</strong></td><td class="amount"><strong>${feePlaceholder}</strong></td></tr>
                        </table>
                        <p><strong>FEE PAID DETAILS (Admin Entry)</strong></p>
                        <table class="fee-table">
                            <tr><td>Tuition Fee Paid</td><td class="amount">${feePlaceholder}</td></tr>
                            <tr><td>Other Fee Paid</td><td class="amount">${feePlaceholder}</td></tr>
                            ${facilityRow}
                            <tr class="total-row"><td><strong>Total Paid</strong></td><td class="amount"><strong>${feePlaceholder}</strong></td></tr>
                        </table>
                        <p>This certificate is issued for the purpose of <strong>${purpose}</strong>.</p>
                    `;
                    break;
                case 'Internship':
                    title = "BONAFIDE CERTIFICATE FOR INTERNSHIP";
                    content = `
                        <p>This is to certify that <strong>${studentPrefix} ${studentData.studentName}</strong> (Register No: <strong>${studentData.registerNo}</strong>),
                        ${relationship} <strong>${parentPrefix} ${parentName}</strong>, is a bonafide student of our Institution, pursuing
                        <strong>${yearPursuing}</strong> Year ${studentData.department} (Section ${studentData.section}).</p>
                        <p>${pronounSubject} has been permitted to undertake an internship/industrial training at your esteemed organization.</p>
                        <p>This certificate is issued for the purpose of <strong>${purpose}</strong>.</p>
                    `;
                    break;
                case 'Project':
                    title = "PERMISSION FOR PROJECT WORK";
                    content = `
                        <p>This is to certify that <strong>${studentPrefix} ${studentData.studentName}</strong> (Register No: <strong>${studentData.registerNo}</strong>),
                        ${relationship} <strong>${parentPrefix} ${parentName}</strong>, is a bonafide student of our Institution, pursuing
                        <strong>${yearPursuing}</strong> Year ${studentData.department} (Section ${studentData.section}).</p>
                        <p>We hereby grant permission to undertake project work at your esteemed organization.</p>
                        <p>This certificate is issued for the purpose of <strong>${purpose}</strong>.</p>
                    `;
                    break;
            }

            document.getElementById('preview_title').innerText = title;
            document.getElementById('preview_content').innerHTML = content;
        }
        document.addEventListener('DOMContentLoaded', () => {
            toggleFacilityFields();
            updatePreview();
        });
        <?php endif; ?>

        function toggleAdminFacilityFields() {
            const facilityOption = document.getElementById('approve_facility_option')?.value || 'None';
            const hostelGroup = document.getElementById('hostel_fee_group');
            const busGroup = document.getElementById('bus_fee_group');
            const busZoneGroup = document.getElementById('bus_zone_group');
            const hostelInput = document.getElementById('approve_hostel_fee');
            const busInput = document.getElementById('approve_bus_fee');
            const busZone = document.getElementById('approve_bus_zone');

            if (facilityOption === 'Hostel') {
                hostelGroup.style.display = 'block';
                busGroup.style.display = 'none';
                busZoneGroup.style.display = 'none';
                if (busInput) busInput.value = 0;
                if (busZone) busZone.value = 'None';
                if (busZone) busZone.required = false;
            } else if (facilityOption === 'Transport') {
                hostelGroup.style.display = 'none';
                busGroup.style.display = 'block';
                busZoneGroup.style.display = 'block';
                if (hostelInput) hostelInput.value = 0;
                if (busZone) busZone.required = true;
            } else {
                hostelGroup.style.display = 'none';
                busGroup.style.display = 'none';
                busZoneGroup.style.display = 'none';
                if (hostelInput) hostelInput.value = 0;
                if (busInput) busInput.value = 0;
                if (busZone) busZone.value = 'None';
                if (busZone) busZone.required = false;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const facilitySelect = document.getElementById('approve_facility_option');
            if (facilitySelect) {
                facilitySelect.addEventListener('change', toggleAdminFacilityFields);
                toggleAdminFacilityFields();
            }
        });

        // Auto-refresh every 5 minutes (if tab is active)
        setInterval(function() {
            if (!document.hidden) {
                location.reload();
            }
        }, 300000);
    </script>
</body>
</html>
<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if (!$footer_path) {
    http_response_code(500);
    echo 'Missing include: footer.php';
    exit();
}
require_once $footer_path;
?>