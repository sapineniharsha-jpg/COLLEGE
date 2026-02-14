<?php
// topic_coverage.php
// Hour-wise topic coverage tracker integrated with attendance completion.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

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
    echo 'Missing include: db.php';
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
if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    http_response_code(500);
    echo 'Database connection not initialized.';
    exit();
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
if (!function_exists('vh_bind_params')) {
    function vh_bind_params($stmt, $types, array &$values)
    {
        $refs = [];
        $refs[] = &$types;
        foreach ($values as $k => $v) {
            $refs[] = &$values[$k];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('table_exists')) {
    function table_exists(mysqli $mysqli, string $table): bool
    {
        $safe = $mysqli->real_escape_string($table);
        $res = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('table_has_column')) {
    function table_has_column(mysqli $mysqli, string $table, string $column): bool
    {
        $safeTable = $mysqli->real_escape_string($table);
        $safeCol = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('parse_unique_code')) {
    function parse_unique_code($code): array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return ['dept' => '', 'batch' => '', 'sem' => '', 'sec' => 'A'];
        }
        $code = str_replace('-', '/', $code);
        $p = explode('/', $code);
        if (count($p) >= 4) {
            if (count($p) >= 5 && is_numeric($p[1]) && is_numeric($p[2])) {
                return ['dept' => $p[0], 'batch' => $p[1] . '-' . $p[2], 'sem' => str_replace('S', '', $p[3]), 'sec' => $p[4]];
            }
            return ['dept' => $p[0], 'batch' => $p[1], 'sem' => str_replace('S', '', $p[2]), 'sec' => $p[3]];
        }
        return ['dept' => $p[0] ?? '', 'batch' => $p[1] ?? '', 'sem' => isset($p[2]) ? str_replace('S', '', $p[2]) : '', 'sec' => $p[3] ?? 'A'];
    }
}
if (!function_exists('normalize_date')) {
    function normalize_date($value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        return $value;
    }
}
if (!function_exists('get_faculty_info')) {
    function get_faculty_info(mysqli $mysqli, string $fid): array
    {
        $stmt = $mysqli->prepare("SELECT NAME, DEPARTMENT FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
        if (!$stmt) {
            return ['name' => '', 'dept' => ''];
        }
        $stmt->bind_param("s", $fid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            return ['name' => (string) ($row['NAME'] ?? ''), 'dept' => (string) ($row['DEPARTMENT'] ?? '')];
        }
        return ['name' => '', 'dept' => ''];
    }
}
if (!function_exists('faculty_in_dept')) {
    function faculty_in_dept(mysqli $mysqli, string $fid, string $dept): bool
    {
        if ($fid === '' || $dept === '') {
            return false;
        }
        $stmt = $mysqli->prepare("SELECT 1 FROM employee_details1 WHERE ID_NO = ? AND DEPARTMENT = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ss", $fid, $dept);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('get_day_order_for_date')) {
    function get_day_order_for_date(mysqli $mysqli, string $date): int
    {
        if (!table_exists($mysqli, 'academic_calendar')) {
            return 0;
        }
        $order_col = table_has_column($mysqli, 'academic_calendar', 'updated_at') ? 'updated_at' : 'id';
        $stmt = $mysqli->prepare("SELECT day_order FROM academic_calendar WHERE calendar_date = ? ORDER BY {$order_col} DESC LIMIT 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            return (int) ($row['day_order'] ?? 0);
        }
        return 0;
    }
}
if (!function_exists('get_student_profile')) {
    function get_student_profile(mysqli $mysqli, string $studentId): ?array
    {
        $stmt1 = $mysqli->prepare("SELECT id_no AS id, student_name AS name, department AS dept, batch, section FROM students_batch_25_26 WHERE id_no = ? LIMIT 1");
        if ($stmt1) {
            $stmt1->bind_param("s", $studentId);
            $stmt1->execute();
            $r1 = $stmt1->get_result();
            if ($r1 && $row = $r1->fetch_assoc()) {
                return [
                    'id' => (string) ($row['id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'dept' => (string) ($row['dept'] ?? ''),
                    'batch' => (string) ($row['batch'] ?? ''),
                    'section' => (string) ($row['section'] ?? ''),
                ];
            }
        }

        $stmt2 = $mysqli->prepare("SELECT IDNo AS id, Name AS name, Dept AS dept, Batch AS batch, Section AS section FROM students_login_master WHERE IDNo = ? LIMIT 1");
        if ($stmt2) {
            $stmt2->bind_param("s", $studentId);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            if ($r2 && $row = $r2->fetch_assoc()) {
                return [
                    'id' => (string) ($row['id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'dept' => (string) ($row['dept'] ?? ''),
                    'batch' => (string) ($row['batch'] ?? ''),
                    'section' => (string) ($row['section'] ?? ''),
                ];
            }
        }
        return null;
    }
}
if (!function_exists('get_student_unique_codes')) {
    function get_student_unique_codes(mysqli $mysqli, string $dept, string $batch, string $section): array
    {
        if (!table_exists($mysqli, 'timetable_master')) {
            return [];
        }
        $dept = strtoupper(trim($dept));
        $batch = trim($batch);
        $section = strtoupper(trim($section));
        $codes = [];

        $batchLike = '%' . $batch . '%';
        $deptLike = '%' . $dept . '%';

        $sql = "SELECT DISTINCT unique_code FROM timetable_master
                WHERE status='PUBLISHED'
                  AND batch LIKE ?
                  AND (department = ? OR department LIKE ?)
                  AND (section = ? OR ? = '' OR ? = 'A')
                ORDER BY updated_at DESC";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssssss", $batchLike, $dept, $deptLike, $section, $section, $section);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && $row = $res->fetch_assoc()) {
                $code = (string) ($row['unique_code'] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }
        return array_values(array_unique($codes));
    }
}
if (!function_exists('attendance_done_for_slot')) {
    function attendance_done_for_slot(mysqli $mysqli, string $date, int $hour, string $subjectCode, string $facultyId, string $uniqueCode): bool
    {
        if (!table_exists($mysqli, 'attendance_logs')) {
            return false;
        }
        $hasUnique = table_has_column($mysqli, 'attendance_logs', 'unique_code');
        if ($hasUnique && $uniqueCode !== '') {
            $stmt = $mysqli->prepare("SELECT COUNT(*) FROM attendance_logs WHERE date = ? AND hour = ? AND subject_code = ? AND faculty_id = ? AND unique_code = ?");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("sisss", $date, $hour, $subjectCode, $facultyId, $uniqueCode);
        } else {
            $stmt = $mysqli->prepare("SELECT COUNT(*) FROM attendance_logs WHERE date = ? AND hour = ? AND subject_code = ? AND faculty_id = ?");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("siss", $date, $hour, $subjectCode, $facultyId);
        }
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        return ((int) $cnt) > 0;
    }
}
if (!function_exists('get_topic_row_for_slot')) {
    function get_topic_row_for_slot(mysqli $mysqli, string $date, int $hour, string $subjectCode, string $facultyId, string $uniqueCode): ?array
    {
        if (!table_exists($mysqli, 'topic_coverage')) {
            return null;
        }
        $hasUnique = table_has_column($mysqli, 'topic_coverage', 'unique_code');
        if ($hasUnique && $uniqueCode !== '') {
            $stmt = $mysqli->prepare("SELECT * FROM topic_coverage WHERE date = ? AND hour = ? AND subject_code = ? AND faculty_id = ? AND unique_code = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("sisss", $date, $hour, $subjectCode, $facultyId, $uniqueCode);
        } else {
            $stmt = $mysqli->prepare("SELECT * FROM topic_coverage WHERE date = ? AND hour = ? AND subject_code = ? AND faculty_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("siss", $date, $hour, $subjectCode, $facultyId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}

$user_id = trim((string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? ''));
$user_role = strtoupper(trim((string) ($_SESSION['role'] ?? 'USER')));
$user_dept = strtoupper(trim((string) ($_SESSION['DEPARTMENT'] ?? '')));

if ($user_role === 'HOD' && $user_dept === '' && $user_id !== '') {
    $u = get_faculty_info($mysqli, $user_id);
    if (!empty($u['dept'])) {
        $user_dept = strtoupper(trim((string) $u['dept']));
        $_SESSION['DEPARTMENT'] = $user_dept;
    }
}

$allowed_roles = ['FACULTY', 'STUDENT', 'HOD', 'DEAN', 'DEAN_ACADEMICS', 'ADMIN', 'PRINCIPAL', 'VC'];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(404);
    echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>404 Not Found</div>";
    exit();
}

$is_student = ($user_role === 'STUDENT');
$is_hod = ($user_role === 'HOD');
$is_faculty = ($user_role === 'FACULTY');
$is_super_view = in_array($user_role, ['ADMIN', 'PRINCIPAL', 'DEAN', 'DEAN_ACADEMICS', 'VC'], true);
$can_select_faculty = ($is_hod || $is_super_view);
$can_submit_topic = in_array($user_role, ['FACULTY', 'ADMIN', 'PRINCIPAL'], true);
$topic_table_ready = table_exists($mysqli, 'topic_coverage');
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

// ---------------------------
// AJAX API
// ---------------------------
if (isset($_POST['action'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(true);
    }

    $action = (string) $_POST['action'];
    try {
        if ($action === 'get_day_order') {
            $date = normalize_date($_POST['date'] ?? '');
            if ($date === '') {
                throw new Exception('Invalid date.');
            }
            $officialDay = get_day_order_for_date($mysqli, $date);
            echo json_encode([
                'status' => 'success',
                'day_order' => $officialDay,
                'locked' => $officialDay > 0,
            ]);
            exit;
        }

        if ($action === 'get_schedule') {
            $date = normalize_date($_POST['date'] ?? '');
            if ($date === '') {
                throw new Exception('Invalid date.');
            }
            $dayOrder = (int) ($_POST['day_order'] ?? 0);
            $officialDay = get_day_order_for_date($mysqli, $date);
            if ($officialDay > 0) {
                $dayOrder = $officialDay;
            }
            if ($dayOrder <= 0) {
                $dow = (int) date('N', strtotime($date));
                $dayOrder = min(6, max(1, $dow));
            }

            $rows = [];
            if ($is_student) {
                $student = get_student_profile($mysqli, $user_id);
                if (!$student) {
                    echo json_encode(['status' => 'success', 'data' => [], 'day_used' => $dayOrder, 'locked' => $officialDay > 0]);
                    exit;
                }
                $codes = get_student_unique_codes($mysqli, $student['dept'], $student['batch'], $student['section']);
                if (empty($codes)) {
                    echo json_encode(['status' => 'success', 'data' => [], 'day_used' => $dayOrder, 'locked' => $officialDay > 0]);
                    exit;
                }

                if (table_exists($mysqli, 'timetable_matrix')) {
                    $placeholders = implode(',', array_fill(0, count($codes), '?'));
                    $sql = "SELECT unique_code, day_order, hour_slot, subject_code, subject_name, faculty_id, faculty_name
                            FROM timetable_matrix
                            WHERE day_order = ? AND unique_code IN ({$placeholders})
                            ORDER BY hour_slot ASC";
                    $stmt = $mysqli->prepare($sql);
                    if (!$stmt) {
                        throw new Exception($mysqli->error);
                    }
                    $types = 'i' . str_repeat('s', count($codes));
                    $bindVals = array_merge([$dayOrder], $codes);
                    vh_bind_params($stmt, $types, $bindVals);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && $r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                } else {
                    $placeholders = implode(',', array_fill(0, count($codes), '?'));
                    $sql = "SELECT tm.unique_code, td.day as day_order, td.hour as hour_slot, td.subject_code, td.subject_name, td.faculty_id, td.faculty_name
                            FROM timetable_details td
                            JOIN timetable_master tm ON tm.id = td.master_id
                            WHERE td.day = ? AND tm.unique_code IN ({$placeholders})
                            ORDER BY td.hour ASC";
                    $stmt = $mysqli->prepare($sql);
                    if (!$stmt) {
                        throw new Exception($mysqli->error);
                    }
                    $types = 'i' . str_repeat('s', count($codes));
                    $bindVals = array_merge([$dayOrder], $codes);
                    vh_bind_params($stmt, $types, $bindVals);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && $r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                }
            } else {
                $targetFid = trim((string) ($_POST['target_fid'] ?? ''));
                $fid = $user_id;
                if ($can_select_faculty && $targetFid !== '') {
                    if ($is_hod && !faculty_in_dept($mysqli, $targetFid, $user_dept)) {
                        throw new Exception('HOD can only view own department faculty.');
                    }
                    $fid = $targetFid;
                }

                if (table_exists($mysqli, 'timetable_matrix')) {
                    $stmt = $mysqli->prepare("SELECT unique_code, day_order, hour_slot, subject_code, subject_name, faculty_id, faculty_name FROM timetable_matrix WHERE faculty_id = ? AND day_order = ? ORDER BY hour_slot ASC");
                    $stmt->bind_param("si", $fid, $dayOrder);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && $r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                } else {
                    $sql = "SELECT tm.unique_code, td.day as day_order, td.hour as hour_slot, td.subject_code, td.subject_name, td.faculty_id, td.faculty_name
                            FROM timetable_details td
                            JOIN timetable_master tm ON tm.id = td.master_id
                            WHERE td.faculty_id = ? AND td.day = ?
                            ORDER BY td.hour ASC";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("si", $fid, $dayOrder);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && $r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                }
            }

            $data = [];
            foreach ($rows as $r) {
                $hour = (int) ($r['hour_slot'] ?? 0);
                $subjectCode = (string) ($r['subject_code'] ?? '');
                $subjectName = (string) ($r['subject_name'] ?? $subjectCode);
                $facultyId = (string) ($r['faculty_id'] ?? $user_id);
                $facultyName = (string) ($r['faculty_name'] ?? '');
                $uniqueCode = (string) ($r['unique_code'] ?? '');

                $attendanceDone = attendance_done_for_slot($mysqli, $date, $hour, $subjectCode, $facultyId, $uniqueCode);
                $topicRow = get_topic_row_for_slot($mysqli, $date, $hour, $subjectCode, $facultyId, $uniqueCode);
                $topicDone = is_array($topicRow);

                $rowCanEdit = false;
                if ($can_submit_topic && !$is_student) {
                    if ($is_faculty) {
                        $rowCanEdit = strtoupper($facultyId) === strtoupper($user_id);
                    } else {
                        $rowCanEdit = true;
                    }
                }

                $data[] = [
                    'hour_slot' => $hour,
                    'day_order' => (int) ($r['day_order'] ?? $dayOrder),
                    'subject_code' => $subjectCode,
                    'subject_name' => $subjectName,
                    'faculty_id' => $facultyId,
                    'faculty_name' => $facultyName,
                    'unique_code' => $uniqueCode,
                    'is_attendance_done' => $attendanceDone,
                    'is_topic_done' => $topicDone,
                    'is_completed' => ($attendanceDone && $topicDone),
                    'topic_title' => $topicDone ? (string) ($topicRow['topic_title'] ?? '') : '',
                    'file_name' => $topicDone ? (string) ($topicRow['file_name'] ?? '') : '',
                    'can_edit' => $rowCanEdit,
                ];
            }

            echo json_encode([
                'status' => 'success',
                'data' => $data,
                'day_used' => $dayOrder,
                'locked' => $officialDay > 0,
            ]);
            exit;
        }

        if ($action === 'get_topic_detail') {
            if (!$topic_table_ready) {
                throw new Exception('topic_coverage table not found. Run fix_topic_coverage_db.php first.');
            }
            $date = normalize_date($_POST['date'] ?? '');
            $hour = (int) ($_POST['hour'] ?? 0);
            $subjectCode = trim((string) ($_POST['subject_code'] ?? ''));
            $uniqueCode = trim((string) ($_POST['unique_code'] ?? ''));
            $facultyId = trim((string) ($_POST['faculty_id'] ?? ''));
            if ($date === '' || $hour <= 0 || $subjectCode === '') {
                throw new Exception('Invalid request payload.');
            }

            if ($is_student) {
                $student = get_student_profile($mysqli, $user_id);
                if (!$student) {
                    throw new Exception('Student profile not found.');
                }
                $codes = get_student_unique_codes($mysqli, $student['dept'], $student['batch'], $student['section']);
                if (!in_array($uniqueCode, $codes, true)) {
                    throw new Exception('Access denied.');
                }
            }
            if ($is_faculty) {
                $facultyId = $user_id;
            }
            if ($is_hod && $facultyId !== '' && !faculty_in_dept($mysqli, $facultyId, $user_dept)) {
                throw new Exception('Access denied.');
            }
            if ($facultyId === '') {
                $facultyId = $user_id;
            }

            $topicRow = get_topic_row_for_slot($mysqli, $date, $hour, $subjectCode, $facultyId, $uniqueCode);
            $attendanceDone = attendance_done_for_slot($mysqli, $date, $hour, $subjectCode, $facultyId, $uniqueCode);

            echo json_encode([
                'status' => 'success',
                'data' => $topicRow ?: null,
                'attendance_done' => $attendanceDone,
            ]);
            exit;
        }

        if ($action === 'save_topic') {
            if (!$can_submit_topic) {
                throw new Exception('You are not allowed to submit topic coverage.');
            }
            if (!$topic_table_ready) {
                throw new Exception('topic_coverage table not found. Run fix_topic_coverage_db.php first.');
            }

            $date = normalize_date($_POST['date'] ?? '');
            $hour = (int) ($_POST['hour'] ?? 0);
            $dayOrder = (int) ($_POST['day_order'] ?? 0);
            $subjectCode = strtoupper(trim((string) ($_POST['subject_code'] ?? '')));
            $subjectName = trim((string) ($_POST['subject_name'] ?? $subjectCode));
            $uniqueCode = strtoupper(trim((string) ($_POST['unique_code'] ?? '')));
            $topicTitle = trim((string) ($_POST['topic_title'] ?? ''));
            $topicDetails = (string) ($_POST['topic_details'] ?? '');
            $targetFid = trim((string) ($_POST['target_fid'] ?? ''));

            if ($date === '' || $hour <= 0 || $subjectCode === '') {
                throw new Exception('Invalid class slot details.');
            }
            if ($topicTitle === '') {
                throw new Exception('Topic title is required.');
            }

            $fid = $user_id;
            if (in_array($user_role, ['ADMIN', 'PRINCIPAL'], true) && $targetFid !== '') {
                $fid = $targetFid;
            }
            if ($fid === '') {
                throw new Exception('Faculty context missing.');
            }

            $fInfo = get_faculty_info($mysqli, $fid);
            $facultyName = trim((string) ($fInfo['name'] ?? ''));
            if ($facultyName === '') {
                $facultyName = trim((string) ($_SESSION['user_name'] ?? 'Faculty'));
            }
            $meta = parse_unique_code($uniqueCode);
            $dept = trim((string) ($meta['dept'] ?? ''));
            if ($dept === '') {
                $dept = strtoupper(trim((string) ($fInfo['dept'] ?? '')));
            }

            $attendanceDone = attendance_done_for_slot($mysqli, $date, $hour, $subjectCode, $fid, $uniqueCode);
            if (!$attendanceDone) {
                throw new Exception('Please complete attendance first for this hour, then add topic coverage.');
            }

            $existing = get_topic_row_for_slot($mysqli, $date, $hour, $subjectCode, $fid, $uniqueCode);
            $existingFilePath = $existing ? (string) ($existing['file_path'] ?? '') : '';
            $existingFileName = $existing ? (string) ($existing['file_name'] ?? '') : '';

            $newFilePath = $existingFilePath;
            $newFileName = $existingFileName;

            if (isset($_FILES['material_file']) && (int) $_FILES['material_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ((int) $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File upload failed.');
                }

                $allowed = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png', 'zip'];
                $original = (string) $_FILES['material_file']['name'];
                $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    throw new Exception('Invalid file type. Allowed: PDF, PPT, PPTX, DOC, DOCX, XLS, XLSX, TXT, JPG, PNG, ZIP.');
                }
                $size = (int) ($_FILES['material_file']['size'] ?? 0);
                if ($size > (30 * 1024 * 1024)) {
                    throw new Exception('File too large. Max size is 30 MB.');
                }

                $uploadDir = '../uploads/topic_materials/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                try {
                    $token = bin2hex(random_bytes(8));
                } catch (Throwable $e) {
                    $token = uniqid('', true);
                }
                $stored = 'topic_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $token) . '.' . $ext;
                $dest = $uploadDir . $stored;
                if (!move_uploaded_file($_FILES['material_file']['tmp_name'], $dest)) {
                    throw new Exception('Unable to save uploaded file.');
                }
                $newFilePath = $dest;
                $newFileName = basename($original);
            }

            $actor = trim((string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? $fid));

            if ($existing) {
                $id = (int) $existing['id'];
                $stmt = $mysqli->prepare("UPDATE topic_coverage
                                          SET day_order = ?, subject_name = ?, faculty_name = ?, department = ?, topic_title = ?, topic_details = ?, file_path = ?, file_name = ?, updated_by = ?, updated_at = NOW()
                                          WHERE id = ?");
                $stmt->bind_param("issssssssi", $dayOrder, $subjectName, $facultyName, $dept, $topicTitle, $topicDetails, $newFilePath, $newFileName, $actor, $id);
                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }
                if ($existingFilePath !== '' && $newFilePath !== $existingFilePath && file_exists($existingFilePath)) {
                    @unlink($existingFilePath);
                }
                echo json_encode(['status' => 'success', 'message' => 'Topic coverage updated successfully.']);
            } else {
                $stmt = $mysqli->prepare("INSERT INTO topic_coverage
                    (unique_code, date, day_order, hour, subject_code, subject_name, faculty_id, faculty_name, department, topic_title, topic_details, file_path, file_name, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    "ssiisssssssssss",
                    $uniqueCode,
                    $date,
                    $dayOrder,
                    $hour,
                    $subjectCode,
                    $subjectName,
                    $fid,
                    $facultyName,
                    $dept,
                    $topicTitle,
                    $topicDetails,
                    $newFilePath,
                    $newFileName,
                    $actor,
                    $actor
                );
                if (!$stmt->execute()) {
                    throw new Exception($stmt->error);
                }
                echo json_encode(['status' => 'success', 'message' => 'Topic coverage saved successfully.']);
            }
            exit;
        }

        if ($action === 'get_recent_topics') {
            if (!$topic_table_ready) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }

            $dateFrom = normalize_date($_POST['date_from'] ?? '');
            $dateTo = normalize_date($_POST['date_to'] ?? '');
            $targetFid = trim((string) ($_POST['target_fid'] ?? ''));
            $limit = (int) ($_POST['limit'] ?? 100);
            if ($limit < 20) {
                $limit = 20;
            }
            if ($limit > 300) {
                $limit = 300;
            }

            $sql = "SELECT tc.id, tc.date, tc.day_order, tc.hour, tc.unique_code, tc.subject_code, tc.subject_name, tc.faculty_id, tc.faculty_name, tc.topic_title, tc.file_name, tc.file_path, tc.created_at, tc.updated_at
                    FROM topic_coverage tc
                    WHERE 1=1";
            $types = '';
            $vals = [];

            if ($is_student) {
                $student = get_student_profile($mysqli, $user_id);
                if (!$student) {
                    echo json_encode(['status' => 'success', 'data' => []]);
                    exit;
                }
                $codes = get_student_unique_codes($mysqli, $student['dept'], $student['batch'], $student['section']);
                if (empty($codes)) {
                    echo json_encode(['status' => 'success', 'data' => []]);
                    exit;
                }
                $in = implode(',', array_fill(0, count($codes), '?'));
                $sql .= " AND tc.unique_code IN ({$in})";
                $types .= str_repeat('s', count($codes));
                foreach ($codes as $c) {
                    $vals[] = $c;
                }
            } elseif ($is_faculty) {
                $sql .= " AND tc.faculty_id = ?";
                $types .= 's';
                $vals[] = $user_id;
            } elseif ($is_hod) {
                $sql .= " AND (tc.department = ? OR tc.faculty_id IN (SELECT ID_NO FROM employee_details1 WHERE DEPARTMENT = ?))";
                $types .= 'ss';
                $vals[] = $user_dept;
                $vals[] = $user_dept;
            }

            if ($targetFid !== '' && ($can_select_faculty && !$is_student)) {
                if ($is_hod && !faculty_in_dept($mysqli, $targetFid, $user_dept)) {
                    throw new Exception('HOD can only filter own department faculty.');
                }
                $sql .= " AND tc.faculty_id = ?";
                $types .= 's';
                $vals[] = $targetFid;
            }

            if ($dateFrom !== '') {
                $sql .= " AND tc.date >= ?";
                $types .= 's';
                $vals[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $sql .= " AND tc.date <= ?";
                $types .= 's';
                $vals[] = $dateTo;
            }

            $sql .= " ORDER BY tc.date DESC, tc.hour DESC LIMIT {$limit}";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception($mysqli->error);
            }
            if ($types !== '') {
                vh_bind_params($stmt, $types, $vals);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];
            while ($res && $row = $res->fetch_assoc()) {
                $data[] = $row;
            }
            echo json_encode(['status' => 'success', 'data' => $data]);
            exit;
        }

        throw new Exception('Invalid action.');
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ---------------------------
// View bootstrap data
// ---------------------------
$faculty_list = [];
if ($can_select_faculty && !$is_student) {
    if ($is_hod) {
        $stmt = $mysqli->prepare("SELECT ID_NO, NAME, DEPARTMENT FROM employee_details1 WHERE DEPARTMENT = ? ORDER BY NAME ASC");
        if ($stmt) {
            $stmt->bind_param("s", $user_dept);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && $row = $res->fetch_assoc()) {
                $faculty_list[] = $row;
            }
        }
    } else {
        $res = $mysqli->query("SELECT ID_NO, NAME, DEPARTMENT FROM employee_details1 ORDER BY NAME ASC");
        while ($res && $row = $res->fetch_assoc()) {
            $faculty_list[] = $row;
        }
    }
}

$self_name = '';
$self_info = get_faculty_info($mysqli, $user_id);
if (!empty($self_info['name'])) {
    $self_name = $self_info['name'];
}

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
    :root {
        --insta-grad: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
    }
    body { background: #f8fafc; font-family: 'Outfit', sans-serif; }
    .page-wrap { max-width: 1300px; margin: 24px auto; padding: 0 16px 50px; }
    .top-head {
        background: rgba(255,255,255,0.96); border: 1px solid #eef2f7; border-radius: 20px;
        padding: 20px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
    }
    .title-grad { font-weight: 800; font-size: 1.6rem; margin: 0; background: var(--insta-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .subtxt { color: #64748b; margin: 4px 0 0; font-size: 0.92rem; }
    .pill-role { font-weight: 700; background: #f1f5f9; color: #334155; padding: 8px 14px; border-radius: 999px; font-size: 0.8rem; }
    .control-card, .list-card {
        background: #fff; border: 1px solid #eef2f7; border-radius: 18px; padding: 18px; margin-bottom: 18px;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
    }
    .grid-controls { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items: end; }
    .lbl { font-size: .75rem; color: #64748b; text-transform: uppercase; letter-spacing: .4px; font-weight: 700; margin-bottom: 6px; display:block; }
    .schedule-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
    .class-card {
        border: 1px solid #e5e7eb; border-radius: 16px; padding: 14px; background: #fff; transition: .2s;
        box-shadow: 0 6px 16px rgba(15,23,42,.04);
    }
    .class-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(15,23,42,.08); }
    .s-badge { font-size: .72rem; font-weight: 800; border-radius: 999px; padding: 5px 10px; display:inline-block; }
    .ok { background:#dcfce7; color:#166534; }
    .warn { background:#fef3c7; color:#92400e; }
    .muted { color:#64748b; font-size:.85rem; }
    .topic-title { font-weight:700; margin-top:6px; color:#0f172a; font-size:.92rem; }
    .tbl-small td, .tbl-small th { font-size: .86rem; vertical-align: middle; }
    .file-link { font-weight: 600; text-decoration: none; }
    .file-link:hover { text-decoration: underline; }
</style>

<div class="page-wrap">
    <div class="top-head">
        <div>
            <h1 class="title-grad">Hour-wise Topic Coverage</h1>
            <p class="subtxt">Faculty can submit topic only after attendance is completed for the class hour.</p>
        </div>
        <div class="pill-role"><?= vh_e($user_role) ?> ACCOUNT</div>
    </div>

    <?php if (!$topic_table_ready): ?>
        <div class="alert alert-warning" style="border-radius:12px;">
            <strong>Topic table not found.</strong>
            Please run <code>fix_topic_coverage_db.php</code> once to initialize schema.
            <?php if (in_array($user_role, ['ADMIN', 'PRINCIPAL'], true)): ?>
                <a href="fix_topic_coverage_db.php" class="btn btn-sm btn-dark ms-2">Run DB Fix</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="control-card">
        <div class="grid-controls">
            <div>
                <label class="lbl">Date</label>
                <input type="date" id="fltDate" class="form-control" value="<?= vh_e(date('Y-m-d')) ?>">
            </div>
            <div>
                <label class="lbl">Day Order</label>
                <select id="fltDay" class="form-select">
                    <option value="1">1</option><option value="2">2</option><option value="3">3</option>
                    <option value="4">4</option><option value="5">5</option><option value="6">6</option>
                </select>
                <small id="dayOrderMsg" class="muted"></small>
            </div>
            <?php if ($can_select_faculty && !$is_student): ?>
            <div>
                <label class="lbl">Faculty</label>
                <select id="fltFaculty" class="form-select">
                    <option value="">Myself</option>
                    <?php foreach ($faculty_list as $f): ?>
                        <option value="<?= vh_e($f['ID_NO']) ?>"><?= vh_e($f['NAME']) ?> (<?= vh_e($f['ID_NO']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <button class="btn btn-primary w-100" id="btnLoadSchedule">Load Hour Status</button>
            </div>
            <div>
                <button class="btn btn-outline-secondary w-100" id="btnLoadRecent">Refresh Topic Log</button>
            </div>
        </div>
    </div>

    <div class="list-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0 fw-bold text-dark">Hour Completion Status</h5>
            <span class="muted" id="scheduleMeta"></span>
        </div>
        <div id="scheduleGrid" class="schedule-grid">
            <div class="muted">Load a date to view class-wise hour status.</div>
        </div>
    </div>

    <div class="list-card">
        <div class="row g-2 align-items-end mb-3">
            <div class="col-md-3">
                <label class="lbl">From Date</label>
                <input type="date" id="fromDate" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="lbl">To Date</label>
                <input type="date" id="toDate" class="form-control">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-dark w-100" id="btnApplyRange">Apply Range</button>
            </div>
        </div>
        <h5 class="mb-3 fw-bold text-dark">Topic Coverage Log</h5>
        <div class="table-responsive">
            <table class="table table-hover tbl-small">
                <thead><tr><th>Date</th><th>Hour</th><th>Class</th><th>Subject</th><th>Faculty</th><th>Topic</th><th>File</th><th>Action</th></tr></thead>
                <tbody id="recentBody"><tr><td colspan="8" class="text-center text-muted">No data loaded.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="topicModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius:16px;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="topicModalTitle">Topic Coverage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="topicForm" enctype="multipart/form-data">
                    <input type="hidden" id="mDate" name="date">
                    <input type="hidden" id="mDayOrder" name="day_order">
                    <input type="hidden" id="mHour" name="hour">
                    <input type="hidden" id="mSubCode" name="subject_code">
                    <input type="hidden" id="mSubName" name="subject_name">
                    <input type="hidden" id="mUnique" name="unique_code">
                    <input type="hidden" id="mFacId" name="faculty_id">

                    <div class="alert alert-info py-2 mb-3" id="slotInfo"></div>
                    <div class="alert alert-warning py-2 mb-3 d-none" id="attendanceBlockMsg">Attendance is not marked for this hour yet. Topic entry is locked.</div>

                    <div class="mb-3">
                        <label class="lbl">Topic Title</label>
                        <input type="text" id="mTopicTitle" name="topic_title" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="lbl">Topic Covered Details</label>
                        <textarea id="mTopicDetails" name="topic_details" class="form-control" rows="7" placeholder="Enter topics covered, outcomes, references, etc."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="lbl">Attach Study Material (PDF/PPT/etc.)</label>
                        <input type="file" id="mFile" name="material_file" class="form-control" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.txt,.jpg,.jpeg,.png,.zip">
                        <small class="muted">Max 30 MB. Allowed: PDF, PPT, PPTX, DOC, DOCX, XLS, XLSX, TXT, JPG, PNG, ZIP.</small>
                    </div>
                    <div id="existingFileBox" class="mb-2"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-primary" id="btnSaveTopic">Save Topic Coverage</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API = <?= json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode($csrf_token, JSON_UNESCAPED_SLASHES) ?>;
const USER_ROLE = <?= json_encode($user_role, JSON_UNESCAPED_SLASHES) ?>;
const IS_STUDENT = <?= $is_student ? 'true' : 'false' ?>;
const CAN_SUBMIT_TOPIC = <?= $can_submit_topic ? 'true' : 'false' ?>;
const CAN_SELECT_FACULTY = <?= ($can_select_faculty && !$is_student) ? 'true' : 'false' ?>;
const TOPIC_TABLE_READY = <?= $topic_table_ready ? 'true' : 'false' ?>;

let scheduleRows = [];
const topicModal = new bootstrap.Modal(document.getElementById('topicModal'));

function withCsrf(data) {
    data._csrf = CSRF_TOKEN;
    return data;
}
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, function (m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
}
function notify(msg, type='info') {
    const cls = (type === 'error') ? 'danger' : (type === 'success' ? 'success' : 'secondary');
    const html = `<div class="alert alert-${cls} py-2 px-3" style="position:fixed; right:16px; bottom:16px; z-index:9999;">${esc(msg)}</div>`;
    const $n = $(html);
    $('body').append($n);
    setTimeout(() => $n.fadeOut(200, () => $n.remove()), 2200);
}

function selectedFaculty() {
    if (!CAN_SELECT_FACULTY) return '';
    return $('#fltFaculty').val() || '';
}

function fetchDayOrderThenLoad() {
    const date = $('#fltDate').val();
    $.post(API, withCsrf({action: 'get_day_order', date: date}), function(res) {
        if (res && res.status === 'success') {
            if (Number(res.day_order) > 0) {
                $('#fltDay').val(String(res.day_order));
                $('#dayOrderMsg').text('Official day order is applied from academic calendar.');
            } else {
                $('#dayOrderMsg').text('No official day order found. Using selected value.');
            }
        }
        loadSchedule();
    }, 'json').fail(function() {
        loadSchedule();
    });
}

function loadSchedule() {
    const payload = {
        action: 'get_schedule',
        date: $('#fltDate').val(),
        day_order: $('#fltDay').val(),
        target_fid: selectedFaculty()
    };
    $.post(API, withCsrf(payload), function(res) {
        if (!res || res.status !== 'success') {
            notify(res && res.message ? res.message : 'Unable to load schedule', 'error');
            return;
        }
        scheduleRows = Array.isArray(res.data) ? res.data : [];
        $('#fltDay').val(String(res.day_used || $('#fltDay').val()));
        $('#scheduleMeta').text(`Day Order: ${res.day_used || '-'} | Date: ${$('#fltDate').val()}`);
        renderSchedule();
    }, 'json').fail(function() {
        notify('Failed to load hour status', 'error');
    });
}

function renderSchedule() {
    const $grid = $('#scheduleGrid');
    if (!scheduleRows.length) {
        $grid.html('<div class="muted">No classes found for selected date/day order.</div>');
        return;
    }
    let html = '';
    scheduleRows.forEach((r, idx) => {
        const att = r.is_attendance_done ? `<span class="s-badge ok">Attendance Done</span>` : `<span class="s-badge warn">Attendance Pending</span>`;
        const top = r.is_topic_done ? `<span class="s-badge ok">Topic Updated</span>` : `<span class="s-badge warn">Topic Pending</span>`;
        const btnLabel = (r.can_edit && r.is_attendance_done) ? (r.is_topic_done ? 'Edit Topic' : 'Add Topic') : 'View Topic';
        html += `
            <div class="class-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div><strong>Hour ${esc(r.hour_slot)}</strong></div>
                    <div>${att} ${top}</div>
                </div>
                <div class="mt-2 fw-bold">${esc(r.subject_code)} - ${esc(r.subject_name)}</div>
                <div class="muted mt-1">${esc(r.unique_code || '-')}</div>
                <div class="muted">${esc(r.faculty_name || r.faculty_id || '-')}</div>
                ${r.topic_title ? `<div class="topic-title">${esc(r.topic_title)}</div>` : ''}
                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-primary" onclick="openTopicModal(${idx})">${btnLabel}</button>
                </div>
            </div>
        `;
    });
    $grid.html(html);
}

function openTopicModal(index) {
    const r = scheduleRows[index];
    if (!r) return;
    $('#topicModalTitle').text(`Hour ${r.hour_slot} - ${r.subject_code}`);
    $('#slotInfo').html(
        `<strong>${esc(r.subject_code)} - ${esc(r.subject_name)}</strong><br>` +
        `Class: ${esc(r.unique_code || '-')} | Faculty: ${esc(r.faculty_name || r.faculty_id || '-')}`
    );
    $('#mDate').val($('#fltDate').val());
    $('#mDayOrder').val($('#fltDay').val());
    $('#mHour').val(r.hour_slot);
    $('#mSubCode').val(r.subject_code);
    $('#mSubName').val(r.subject_name);
    $('#mUnique').val(r.unique_code || '');
    $('#mFacId').val(r.faculty_id || '');
    $('#mTopicTitle').val('');
    $('#mTopicDetails').val('');
    $('#mFile').val('');
    $('#existingFileBox').html('');

    const canEdit = Boolean(r.can_edit);
    const attendanceDone = Boolean(r.is_attendance_done);
    const shouldLock = (!canEdit) || (!attendanceDone);

    $('#attendanceBlockMsg').toggleClass('d-none', attendanceDone);
    $('#btnSaveTopic').toggle(!IS_STUDENT);
    $('#btnSaveTopic').prop('disabled', shouldLock || !TOPIC_TABLE_READY || !CAN_SUBMIT_TOPIC);
    $('#mTopicTitle, #mTopicDetails, #mFile').prop('disabled', shouldLock || !TOPIC_TABLE_READY || !CAN_SUBMIT_TOPIC);

    $.post(API, withCsrf({
        action: 'get_topic_detail',
        date: $('#mDate').val(),
        hour: $('#mHour').val(),
        subject_code: $('#mSubCode').val(),
        unique_code: $('#mUnique').val(),
        faculty_id: $('#mFacId').val()
    }), function(res) {
        if (res && res.status === 'success' && res.data) {
            $('#mTopicTitle').val(res.data.topic_title || '');
            $('#mTopicDetails').val(res.data.topic_details || '');
            if (res.data.file_path && res.data.file_name) {
                $('#existingFileBox').html(
                    `Current File: <a class="file-link" target="_blank" href="${esc(res.data.file_path)}">${esc(res.data.file_name)}</a>`
                );
            }
        }
        topicModal.show();
    }, 'json').fail(function() {
        topicModal.show();
    });
}

function saveTopic() {
    if (!CAN_SUBMIT_TOPIC || !TOPIC_TABLE_READY) {
        notify('Topic submission is not enabled.', 'error');
        return;
    }
    const fd = new FormData(document.getElementById('topicForm'));
    fd.append('action', 'save_topic');
    fd.append('_csrf', CSRF_TOKEN);
    if (CAN_SELECT_FACULTY) {
        fd.append('target_fid', selectedFaculty());
    }

    fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                notify(res.message || 'Saved successfully', 'success');
                topicModal.hide();
                loadSchedule();
                loadRecent();
            } else {
                notify(res.message || 'Save failed', 'error');
            }
        })
        .catch(() => notify('Save failed due to network/server error', 'error'));
}

function loadRecent() {
    const payload = {
        action: 'get_recent_topics',
        target_fid: selectedFaculty(),
        date_from: $('#fromDate').val(),
        date_to: $('#toDate').val(),
        limit: 120
    };
    $.post(API, withCsrf(payload), function(res) {
        if (!res || res.status !== 'success') {
            $('#recentBody').html('<tr><td colspan="8" class="text-center text-danger">Unable to load topic log.</td></tr>');
            return;
        }
        const rows = Array.isArray(res.data) ? res.data : [];
        if (!rows.length) {
            $('#recentBody').html('<tr><td colspan="8" class="text-center text-muted">No topic log entries found.</td></tr>');
            return;
        }
        let html = '';
        rows.forEach(r => {
            const fileCell = (r.file_path && r.file_name) ? `<a class="file-link" target="_blank" href="${esc(r.file_path)}">${esc(r.file_name)}</a>` : '-';
            html += `
                <tr>
                    <td>${esc(r.date)}</td>
                    <td>H${esc(r.hour)}</td>
                    <td>${esc(r.unique_code || '-')}</td>
                    <td>${esc(r.subject_code)} - ${esc(r.subject_name || '')}</td>
                    <td>${esc(r.faculty_name || r.faculty_id || '-')}</td>
                    <td>${esc(r.topic_title || '')}</td>
                    <td>${fileCell}</td>
                    <td><button class="btn btn-sm btn-outline-primary" onclick="openFromHistory('${esc(r.date)}','${esc(r.hour)}','${esc(r.subject_code)}','${esc(r.unique_code || '')}','${esc(r.faculty_id || '')}')">View</button></td>
                </tr>
            `;
        });
        $('#recentBody').html(html);
    }, 'json').fail(function() {
        $('#recentBody').html('<tr><td colspan="8" class="text-center text-danger">Unable to load topic log.</td></tr>');
    });
}

function openFromHistory(date, hour, subCode, uniqueCode, facultyId) {
    const idx = scheduleRows.findIndex(r =>
        String(r.hour_slot) === String(hour) &&
        String(r.subject_code) === String(subCode) &&
        String(r.unique_code || '') === String(uniqueCode)
    );
    if (idx >= 0) {
        openTopicModal(idx);
        return;
    }
    const fake = {
        hour_slot: hour,
        day_order: $('#fltDay').val(),
        subject_code: subCode,
        subject_name: subCode,
        faculty_id: facultyId,
        faculty_name: facultyId,
        unique_code: uniqueCode,
        is_attendance_done: true,
        can_edit: false
    };
    scheduleRows.push(fake);
    openTopicModal(scheduleRows.length - 1);
}

$('#btnLoadSchedule').on('click', fetchDayOrderThenLoad);
$('#btnLoadRecent, #btnApplyRange').on('click', loadRecent);
$('#fltDate').on('change', fetchDayOrderThenLoad);
$('#fltDay').on('change', loadSchedule);
$('#fltFaculty').on('change', function() { loadSchedule(); loadRecent(); });
$('#btnSaveTopic').on('click', saveTopic);

$(document).ready(function() {
    fetchDayOrderThenLoad();
    loadRecent();
});
</script>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
