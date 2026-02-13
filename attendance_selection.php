<?php
// attendance_selection.php - ENTERPRISE v40.6 (Admin Fixes + History Robust)
session_start();

// 1. CONFIG
if (ob_get_level() == 0) ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

// 2. DB CONNECT
$include_paths = [__DIR__, dirname(__DIR__)];
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
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Missing include: db.php']));
}
require_once $db_path;
if (!isset($mysqli)) {
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'DB Connection Failed']));
}

// HOD department fix
if (strtoupper($_SESSION['role'] ?? '') === 'HOD' && empty($_SESSION['DEPARTMENT'])) {
    $fid = $_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? '';
    if ($fid !== '') {
        $stmt = $mysqli->prepare("SELECT DEPARTMENT FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $fid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $_SESSION['DEPARTMENT'] = $row['DEPARTMENT'] ?? '';
            }
        }
    }
}

// --- HELPER FUNCTIONS ---
if (!function_exists('parse_unique_code')) {
    function parse_unique_code($code) {
        $code = strtoupper(trim($code ?? ''));
        if (empty($code)) return ['dept' => '', 'batch' => '', 'sem' => '', 'sec' => 'A'];
        $code = str_replace('-', '/', $code);
        $p = explode('/', $code);
        if (count($p) >= 4) {
            if (count($p) >= 5 && is_numeric($p[1]) && is_numeric($p[2])) {
                return ['dept' => $p[0], 'batch' => $p[1] . '-' . $p[2], 'sem' => str_replace('S', '', $p[3]), 'sec' => $p[4]];
            }
            return ['dept' => $p[0], 'batch' => $p[1], 'sem' => str_replace('S', '', $p[2]), 'sec' => $p[3]];
        }
        return ['dept' => $p[0] ?? '', 'batch' => $p[1] ?? '', 'sem' => isset($p[2]) ? str_replace('S', '', $p[2]) : '', 'sec' => isset($p[3]) ? $p[3] : 'A'];
    }
}

if (!function_exists('is_admin_user')) {
    function is_admin_user() {
        $r = strtoupper($_SESSION['role'] ?? 'USER');
        return in_array($r, ['ADMIN', 'PRINCIPAL', 'DEAN', 'DEAN_ACADEMICS'], true);
    }
}
if (!function_exists('is_dayorder_admin')) {
    function is_dayorder_admin() {
        $r = strtoupper($_SESSION['role'] ?? 'USER');
        return in_array($r, ['ADMIN', 'PRINCIPAL'], true);
    }
}
if (!function_exists('is_hod_user')) {
    function is_hod_user() { return strtoupper($_SESSION['role'] ?? '') === 'HOD'; }
}

if (!function_exists('table_exists')) {
    function table_exists($mysqli, $table) {
        $res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('table_has_column')) {
    function table_has_column($mysqli, $table, $column) {
        $res = $mysqli->query("SHOW COLUMNS FROM {$table} LIKE '" . $mysqli->real_escape_string($column) . "'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('get_faculty_info')) {
    function get_faculty_info($mysqli, $fid) {
        $stmt = $mysqli->prepare("SELECT NAME, DEPARTMENT FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
        if (!$stmt) return ['name' => '', 'dept' => ''];
        $stmt->bind_param("s", $fid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            return ['name' => $row['NAME'] ?? '', 'dept' => $row['DEPARTMENT'] ?? ''];
        }
        return ['name' => '', 'dept' => ''];
    }
}

if (!function_exists('faculty_in_dept')) {
    function faculty_in_dept($mysqli, $fid, $dept) {
        if ($dept === '') return false;
        $stmt = $mysqli->prepare("SELECT 1 FROM employee_details1 WHERE ID_NO = ? AND DEPARTMENT = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param("ss", $fid, $dept);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res && $res->num_rows > 0;
    }
}

// --- API AJAX HANDLER ---
if (isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        // 1. ADMIN/HOD SET DAY ORDER
        if ($action === 'set_day_order') {
            if (!is_dayorder_admin() && !is_hod_user()) throw new Exception("Access Denied");
            if (!table_exists($mysqli, 'academic_calendar')) {
                throw new Exception("academic_calendar table not found. Please create it.");
            }
            $date = $_POST['date'];
            $day = intval($_POST['day_order']);
            $uid = $_SESSION['ID_NO'] ?? 'ADMIN';

            $has_program = table_has_column($mysqli, 'academic_calendar', 'program_type');
            $has_batch = table_has_column($mysqli, 'academic_calendar', 'target_batch');

            $check = $mysqli->prepare("SELECT id FROM academic_calendar WHERE calendar_date = ? LIMIT 1");
            $check->bind_param("s", $date);
            $check->execute();
            $check_res = $check->get_result();
            $exists = $check_res && $check_res->num_rows > 0;

            if (!$exists && is_hod_user() && !is_dayorder_admin()) {
                throw new Exception("HOD can only update existing day order, not create new.");
            }

            if ($exists) {
                $stmt = $mysqli->prepare("UPDATE academic_calendar SET day_order = ?, updated_by = ? WHERE calendar_date = ?");
                $stmt->bind_param("iss", $day, $uid, $date);
            } else {
                $cols = ['calendar_date', 'day_order', 'updated_by'];
                $vals = [$date, $day, $uid];
                $types_ins = "sis";
                if ($has_program) { $cols[] = 'program_type'; $vals[] = 'ALL'; $types_ins .= "s"; }
                if ($has_batch) { $cols[] = 'target_batch'; $vals[] = 'ALL'; $types_ins .= "s"; }
                $place = implode(',', array_fill(0, count($cols), '?'));
                $stmt = $mysqli->prepare("INSERT INTO academic_calendar (" . implode(',', $cols) . ") VALUES ({$place})");
                $stmt->bind_param($types_ins, ...$vals);
            }

            if(!$stmt->execute()) throw new Exception("Execute Error: " . $stmt->error);
            echo json_encode(['status' => 'success', 'message' => 'Day Order Updated']);
            exit;
        }

        if ($action === 'delete_day_order') {
            if (!is_dayorder_admin()) throw new Exception("Access Denied");
            if (!table_exists($mysqli, 'academic_calendar')) {
                throw new Exception("academic_calendar table not found.");
            }
            $date = $_POST['date'];
            $stmt = $mysqli->prepare("DELETE FROM academic_calendar WHERE calendar_date = ?");
            $stmt->bind_param("s", $date);
            $stmt->execute();
            echo json_encode(['status' => 'success', 'message' => 'Day Order Deleted']);
            exit;
        }

        // 2. CHECK DAY ORDER
        if ($action === 'check_day_order') {
            $date = $_POST['date'];
            if (!table_exists($mysqli, 'academic_calendar')) {
                echo json_encode(['status' => 'success', 'locked' => false, 'message' => 'academic_calendar not found']);
                exit;
            }
            $order_col = table_has_column($mysqli, 'academic_calendar', 'updated_at') ? 'updated_at' : 'id';
            $stmt = $mysqli->prepare("SELECT day_order FROM academic_calendar WHERE calendar_date = ? ORDER BY {$order_col} DESC LIMIT 1");
            if($stmt) {
                $stmt->bind_param("s", $date);
                $stmt->execute();
                $res = $stmt->get_result();
                if($row = $res->fetch_assoc()) {
                    echo json_encode(['status' => 'success', 'day_order' => $row['day_order'], 'locked' => true]);
                    exit;
                }
            }
            echo json_encode(['status' => 'success', 'locked' => false]);
            exit;
        }

        // 3. GET SCHEDULE (Load Class for Faculty)
        if ($action === 'get_schedule') {
            $fid = $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
            if (isset($_POST['target_fid']) && !empty($_POST['target_fid']) && (is_admin_user() || is_hod_user())) {
                $target_fid = $_POST['target_fid'];
                if (is_hod_user() && !faculty_in_dept($mysqli, $target_fid, $_SESSION['DEPARTMENT'] ?? '')) {
                    throw new Exception("HOD can load only own department faculty schedule.");
                }
                $fid = $target_fid;
            }
            $date = $_POST['date'];
            $day = intval($_POST['day_order']);
            $is_official = false;

            // Calendar Check
            if (table_exists($mysqli, 'academic_calendar')) {
                $order_col = table_has_column($mysqli, 'academic_calendar', 'updated_at') ? 'updated_at' : 'id';
                $stmt = $mysqli->prepare("SELECT day_order FROM academic_calendar WHERE `calendar_date` = ? ORDER BY {$order_col} DESC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("s", $date);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $day = $row['day_order'];
                        $is_official = true;
                    }
                }
            }

            // Get Matrix Data
            $stmt = $mysqli->prepare("SELECT * FROM timetable_matrix WHERE faculty_id = ? AND day_order = ? ORDER BY hour_slot ASC");
            $stmt->bind_param("si", $fid, $day);
            $stmt->execute();
            $res = $stmt->get_result();

            // Fallback for empty matrix
            if ($res->num_rows === 0) {
                $stmt->close();
                $sql = "SELECT tm.unique_code, td.day as day_order, td.hour as hour_slot, td.subject_code, td.subject_name
                        FROM timetable_details td JOIN timetable_master tm ON td.master_id = tm.id
                        WHERE td.faculty_id = ? AND td.day = ? ORDER BY td.hour ASC";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("si", $fid, $day);
                $stmt->execute();
                $res = $stmt->get_result();
            }

            $classes = [];
            while ($row = $res->fetch_assoc()) {
                $meta = parse_unique_code($row['unique_code'] ?? '');
                $cnt = 0;
                $chk = $mysqli->prepare("SELECT count(*) FROM attendance_logs WHERE `date`=? AND `hour`=? AND `subject_code`=?");
                if ($chk) {
                    $chk->bind_param("sis", $date, $row['hour_slot'], $row['subject_code']);
                    $chk->execute();
                    $chk->bind_result($cnt);
                    $chk->fetch();
                    $chk->close();
                }
                $row['meta'] = $meta;
                $row['is_done'] = $cnt > 0;
                $row['is_locked'] = ($cnt > 0 && !is_admin_user());
                $classes[] = $row;
            }
            echo json_encode(['status' => 'success', 'data' => $classes, 'day_used' => $day, 'is_official' => $is_official]);
            exit;
        }

        // 4. GET STUDENTS
        if ($action === 'get_students') {
            $unique_code = $_POST['unique_code'] ?? '';
            $meta = parse_unique_code($unique_code);
            $dept = $meta['dept'];
            $batch = $meta['batch'];
            $sec = $meta['sec'];

            if (empty($dept)) throw new Exception("Invalid Unique Code Format");

            $students = [];

            // Try Batch Table
            $sql1 = "SELECT id_no AS ID_NO, student_name AS NAME, register_no AS REGISTER_NO FROM students_batch_25_26 WHERE (department LIKE ? OR department = 'Science and Humanities') AND batch LIKE ? AND (section = ? OR ? = 'A' OR section IS NULL OR section = '') ORDER BY id_no ASC";
            $stmt = $mysqli->prepare($sql1);
            if ($stmt) {
                $d = "%$dept%"; $b = "%$batch%";
                $stmt->bind_param("ssss", $d, $b, $sec, $sec);
                $stmt->execute();
                $res = $stmt->get_result();
                while($row = $res->fetch_assoc()) $students[] = $row;
                $stmt->close();
            }

            // Fallback
            if(empty($students)) {
                $sql2 = "SELECT IDNo AS ID_NO, Name AS NAME, RegisterNo AS REGISTER_NO FROM students_login_master WHERE Dept LIKE ? AND Batch LIKE ? AND (Section = ? OR ? = 'A' OR Section IS NULL OR Section = '') ORDER BY IDNo ASC";
                $stmt = $mysqli->prepare($sql2);
                if($stmt) {
                    $d = "%$dept%"; $b = "%$batch%";
                    $stmt->bind_param("ssss", $d, $b, $sec, $sec);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while($row = $res->fetch_assoc()) $students[] = $row;
                    $stmt->close();
                }
            }
            echo json_encode(['status' => 'success', 'data' => $students, 'meta' => $meta]);
            exit;
        }

        // 5. SAVE ATTENDANCE (UPDATED TO SAVE FACULTY INFO)
        if ($action === 'save_attendance') {
            $mysqli->begin_transaction();
            $sub_code = $_POST['subject_code'];
            $unique_code = $_POST['unique_code'];
            $date = $_POST['date'];
            $hour = $_POST['hour'];
            $day_order = $_POST['day_order'];
            $fid = $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
            if ((is_admin_user() || is_hod_user()) && !empty($_POST['target_fid'])) {
                $target_fid = $_POST['target_fid'];
                if (is_hod_user() && !faculty_in_dept($mysqli, $target_fid, $_SESSION['DEPARTMENT'] ?? '')) {
                    throw new Exception("HOD can submit only own department faculty attendance.");
                }
                $fid = $target_fid;
            }

            $faculty_info = get_faculty_info($mysqli, $fid);
            $fname = $faculty_info['name'] ?: ($_SESSION['user_name'] ?? 'Faculty');

            // Extract Dept from Unique Code
            $meta = parse_unique_code($unique_code);
            $dept = $meta['dept'] ?: ($faculty_info['dept'] ?? '');

            $students = json_decode($_POST['students'], true);
            if (!is_array($students)) throw new Exception("Invalid Data Format");

            // Ensure required columns exist
            $required_cols = ['date', 'day_order', 'hour', 'student_id', 'subject_code', 'faculty_id', 'status'];
            foreach ($required_cols as $col) {
                if (!table_has_column($mysqli, 'attendance_logs', $col)) {
                    throw new Exception("attendance_logs missing column: {$col}");
                }
            }

            $has_unique = table_has_column($mysqli, 'attendance_logs', 'unique_code');
            $has_marked = table_has_column($mysqli, 'attendance_logs', 'marked_by');
            $has_fac_name = table_has_column($mysqli, 'attendance_logs', 'faculty_name');
            $has_dept = table_has_column($mysqli, 'attendance_logs', 'department');

            // Locking Check
            $lock_sql = "SELECT faculty_id FROM attendance_logs WHERE date=? AND hour=? AND subject_code=?";
            if ($has_unique) {
                $lock_sql .= " AND unique_code=?";
            }
            $lock_sql .= " LIMIT 1";
            $chk = $mysqli->prepare($lock_sql);
            if($chk) {
                if ($has_unique) {
                    $chk->bind_param("siss", $date, $hour, $sub_code, $unique_code);
                } else {
                    $chk->bind_param("sis", $date, $hour, $sub_code);
                }
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0 && !is_admin_user()) throw new Exception("Attendance already submitted. Contact Admin to edit.");
                $chk->close();
            }

            // Clear old entries
            $del_sql = "DELETE FROM attendance_logs WHERE date=? AND hour=? AND subject_code=?";
            if ($has_unique) {
                $del_sql .= " AND unique_code=?";
            }
            $del = $mysqli->prepare($del_sql);
            if ($has_unique) {
                $del->bind_param("siss", $date, $hour, $sub_code, $unique_code);
            } else {
                $del->bind_param("sis", $date, $hour, $sub_code);
            }
            $del->execute();

            // Insert new entries (dynamic columns)
            $cols = ['date', 'day_order', 'hour', 'student_id', 'faculty_id', 'status', 'subject_code'];
            $types = "siissss";
            if ($has_unique) { $cols[] = 'unique_code'; $types .= "s"; }
            if ($has_marked) { $cols[] = 'marked_by'; $types .= "s"; }
            if ($has_fac_name) { $cols[] = 'faculty_name'; $types .= "s"; }
            if ($has_dept) { $cols[] = 'department'; $types .= "s"; }
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $ins_sql = "INSERT INTO attendance_logs (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";
            $ins = $mysqli->prepare($ins_sql);

            $p = 0;
            foreach($students as $s) {
                $vals = [$date, $day_order, $hour, $s['id'], $fid, $s['status'], $sub_code];
                if ($has_unique) { $vals[] = $unique_code; }
                if ($has_marked) { $vals[] = $_SESSION['ID_NO'] ?? $fid; }
                if ($has_fac_name) { $vals[] = $fname; }
                if ($has_dept) { $vals[] = $dept; }
                $ins->bind_param($types, ...$vals);
                $ins->execute();
                if($s['status'] === 'P') $p++;
            }
            $mysqli->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Attendance Saved Successfully ($date - Hour $hour)",
                'stats' => ['present' => $p]
            ]);
            exit;
        }

        // 6. GET HISTORY (Updated Display)
        if ($action === 'get_history') {
            if (!table_exists($mysqli, 'attendance_logs')) {
                throw new Exception("attendance_logs table not found.");
            }

            $fid = $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
            if ((is_admin_user() || is_hod_user()) && !empty($_POST['target_fid'])) {
                $fid = $_POST['target_fid'];
            }

            $use_matrix = table_exists($mysqli, 'timetable_matrix');
            $has_unique = table_has_column($mysqli, 'attendance_logs', 'unique_code');
            if ($use_matrix) {
                $sql = "SELECT al.date, al.hour, al.subject_code,
                               COALESCE(MAX(tm.unique_code), " . ($has_unique ? "MAX(al.unique_code)" : "'Unknown Class'") . ") as unique_code,
                               COALESCE(MAX(tm.subject_name), al.subject_code) as subject_name,
                               COUNT(al.student_id) as total,
                               SUM(CASE WHEN al.status='P' THEN 1 ELSE 0 END) as present
                        FROM attendance_logs al
                        LEFT JOIN timetable_matrix tm ON al.faculty_id = tm.faculty_id
                             AND al.day_order = tm.day_order
                             AND al.hour = tm.hour_slot
                             AND al.subject_code = tm.subject_code
                        WHERE al.faculty_id = ?
                        GROUP BY al.date, al.hour, al.subject_code
                        ORDER BY al.date DESC, al.hour DESC LIMIT 50";
            } else {
                $sql = "SELECT al.date, al.hour, al.subject_code,
                               " . ($has_unique ? "MAX(al.unique_code)" : "'Unknown Class'") . " as unique_code,
                               al.subject_code as subject_name,
                               COUNT(al.student_id) as total,
                               SUM(CASE WHEN al.status='P' THEN 1 ELSE 0 END) as present
                        FROM attendance_logs al
                        WHERE al.faculty_id = ?
                        GROUP BY al.date, al.hour, al.subject_code
                        ORDER BY al.date DESC, al.hour DESC LIMIT 50";
            }

            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("s", $fid);
            if(!$stmt) throw new Exception($mysqli->error);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];
            while($row = $res->fetch_assoc()) {
                $row['date_formatted'] = date('d M Y', strtotime($row['date']));
                $row['percent'] = ($row['total'] > 0) ? round(($row['present']/$row['total'])*100) : 0;
                $row['unique_code'] = $row['unique_code'] ?? 'Unknown Class';
                $data[] = $row;
            }
            echo json_encode(['status' => 'success', 'data' => $data]);
            exit;
        }

        // 7. GET HISTORY DETAILS
        if ($action === 'get_history_details') {
            $sub_code = $_POST['subject_code'];
            $date = $_POST['date'];
            $hour = $_POST['hour'];

            $sql = "SELECT al.student_id, al.status,
                    COALESCE(sb.student_name, sl.Name, al.student_id) as student_name
                    FROM attendance_logs al
                    LEFT JOIN students_batch_25_26 sb ON al.student_id = sb.id_no
                    LEFT JOIN students_login_master sl ON al.student_id = sl.IDNo
                    WHERE al.date = ? AND al.hour = ? AND al.subject_code = ?
                    ORDER BY al.student_id ASC";

            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sis", $date, $hour, $sub_code);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];
            while($row = $res->fetch_assoc()) $data[] = $row;
            echo json_encode(['status' => 'success', 'data' => $data]);
            exit;
        }

    } catch (Exception $e) {
        if(isset($mysqli)) $mysqli->rollback();
        echo json_encode(['status' => 'error', 'message' => 'API Error: ' . $e->getMessage()]);
        exit;
    }
}

// --- VIEW INITIALIZATION ---
$user_role = strtoupper($_SESSION['role'] ?? 'USER');
$is_student = ($user_role === 'STUDENT');
$is_admin = is_admin_user();
$is_dayorder_admin = is_dayorder_admin();
$is_hod = is_hod_user();
$user_id = $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
$stud_summary = [];
$faculty_list = [];

if ($is_admin || $is_hod) {
    if ($is_hod) {
        $dept = $_SESSION['DEPARTMENT'] ?? '';
        if ($dept === '') {
            $dres = $mysqli->query("SELECT DEPARTMENT FROM employee_details1 WHERE ID_NO = '" . $mysqli->real_escape_string($user_id) . "'");
            if ($dres && $row = $dres->fetch_assoc()) {
                $dept = $row['DEPARTMENT'];
            }
        }
        $fres = $mysqli->query("SELECT ID_NO, NAME, DEPARTMENT FROM employee_details1 WHERE DEPARTMENT = '" . $mysqli->real_escape_string($dept) . "' ORDER BY NAME ASC");
    } else {
        $fres = $mysqli->query("SELECT ID_NO, NAME, DEPARTMENT FROM employee_details1 ORDER BY NAME ASC");
    }
    if ($fres) {
        while ($row = $fres->fetch_assoc()) $faculty_list[] = $row;
    }
}

if ($is_student) {
    try {
        $s1 = $mysqli->prepare("SELECT subject_code, COUNT(*) as total, SUM(CASE WHEN status='P' OR status='OD' THEN 1 ELSE 0 END) as present FROM attendance_logs WHERE student_id = ? GROUP BY subject_code");
        $s1->bind_param("s", $user_id);
        $s1->execute();
        $res1 = $s1->get_result();
        while($r = $res1->fetch_assoc()) $stud_summary[] = $r;
    } catch(Exception $e){}
}

include '../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --insta: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: 1px solid rgba(255, 255, 255, 0.5);
        --card-shadow: 0 10px 40px -10px rgba(0,0,0,0.08);
        --success: #00b894; --danger: #ff7675; --warning: #fdcb6e;
    }
    body { background: #fdf2f8; min-height: 100vh; font-family: 'Outfit', sans-serif; padding-bottom: 100px; }
    .form-input { width: 100%; padding: 12px 16px; border-radius: 12px; border: 2px solid #f1f5f9; background: #fff; font-size: 1rem; outline: none; transition:0.2s; }
    .form-input:focus { border-color: #fd79a8; box-shadow: 0 0 0 4px rgba(253, 121, 168, 0.1); }
    .glass-header {
        background: var(--glass-bg); backdrop-filter: blur(16px);
        padding: 15px 25px; position: sticky; top: 0; z-index: 100;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 4px 30px rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid rgba(255, 255, 255, 0.3);
    }
    .app-title { font-weight: 800; font-size: 1.4rem; background: var(--insta); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .user-pill { background: rgba(255,255,255,0.8); padding: 8px 16px; border-radius: 50px; font-size: 0.85rem; font-weight: 700; color: #636e72; border: 1px solid rgba(0,0,0,0.05); }
    .container { max-width: 1000px; margin: 25px auto; padding: 0 20px; }
    .card {
        background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px);
        border-radius: 24px; padding: 25px; margin-bottom: 25px;
        box-shadow: var(--card-shadow); border: var(--glass-border);
        position: relative; overflow: hidden;
    }
    .btn { padding: 12px 24px; border-radius: 50px; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition:0.2s; }
    .btn-primary { background: var(--insta); color: white; box-shadow: 0 8px 20px -5px rgba(233, 30, 99, 0.4); }
    .btn-outline { background: white; color: #833ab4; border: 2px solid #f1f5f9; }
    .tab-nav { display: flex; gap: 10px; margin-bottom: 20px; background: rgba(255,255,255,0.6); padding: 5px; border-radius: 50px; }
    .tab-btn { flex: 1; padding: 12px; border-radius: 50px; border: none; cursor: pointer; font-weight: 700; background: transparent; color: #636e72; transition:0.3s; }
    .tab-btn.active { background: white; color: #d63031; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .att-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    .class-card { background: white; padding: 20px; border-radius: 20px; cursor: pointer; border: 1px solid #f0f0f0; transition:0.2s; }
    .class-card:hover { transform:translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
    .class-card.done { border-left: 6px solid var(--success); }
    .class-card.pending { border-left: 6px solid var(--warning); }
    #takingAttendance { background: #f8fafc; z-index: 2000; display:none; position:fixed; top:0; left:0; width:100%; height:100%; overflow-y:auto; }
    .sticky-toolbar { position: sticky; top: 0; z-index: 90; background: rgba(255,255,255,0.95); padding: 15px 25px; border-bottom: 1px solid #eee; display: flex; gap: 15px; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    .student-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 15px; padding: 20px; padding-bottom: 120px; }
    .student-item { background: white; padding: 15px; border-radius: 16px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.02); border:1px solid #f5f6fa; }
    .student-avatar { width: 45px; height: 45px; border-radius: 50%; background: var(--insta); color: white; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1rem; margin-right: 15px; }
    .status-btn { width: 40px; height: 40px; border-radius: 50%; border: none; font-weight: 800; cursor: pointer; font-size: 0.85rem; color: #a4b0be; background: #f1f2f6; transition:0.2s; }
    .status-btn:hover { transform:scale(1.1); }
    .status-btn.P.active { background: var(--success); color: white; box-shadow: 0 4px 10px rgba(0,184,148,0.4); }
    .status-btn.A.active { background: var(--danger); color: white; box-shadow: 0 4px 10px rgba(255,118,117,0.4); }
    .status-btn.OD.active { background: var(--warning); color: white; box-shadow: 0 4px 10px rgba(253,203,110,0.4); }
    .footer-bar { position: fixed; bottom: 0; left: 0; width: 100%; background: white; padding: 15px 25px; display: flex; justify-content: space-between; align-items: center; z-index: 2100; box-shadow: 0 -10px 40px rgba(0,0,0,0.08); border-top:1px solid #eee; }
    .history-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size:0.9rem; }
    .history-table th { text-align: left; padding: 15px; background: #f8fafc; color: #64748b; font-weight: 700; border-bottom:2px solid #e2e8f0; }
    .history-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; background: white; }
    #loader { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.95); z-index:9999; justify-content:center; align-items:center; flex-direction: column; backdrop-filter: blur(5px); }
    .spinner { width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #e1306c; border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<div id="loader"><div class="spinner"></div><div style="margin-top:15px; font-weight:700; color:#636e72;">Syncing...</div></div>

<div class="glass-header">
    <div class="app-title"><i class="fab fa-instagram"></i> Attendance</div>
    <div class="user-pill"><?= $user_role ?></div>
</div>

<div class="container">
    <?php if($is_student): ?>
    <div class="card">
        <h3>My Attendance Stats</h3>
        <?php if(empty($stud_summary)): ?><p class="text-center text-muted">No attendance data yet.</p><?php else: ?>
        <div style="display:grid; gap:10px; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
            <?php foreach($stud_summary as $s): $pct = round(($s['present']/$s['total'])*100); ?>
            <div style="padding:15px; border:1px solid #f0f0f0; border-radius:15px; text-align:center; background:white;">
                <h5 style="margin:0 0 5px; color:#636e72;"><?= $s['subject_code'] ?></h5>
                <h2 style="margin:0; font-size:2rem; color:<?= $pct>=75?'var(--success)':'var(--danger)' ?>"><?= $pct ?>%</h2>
                <small style="color:#b2bec3;"><?= $s['present'] ?> / <?= $s['total'] ?> Hrs</small>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <?php if($is_dayorder_admin || $is_hod): ?>
    <div class="card admin-box" style="border-left:5px solid #e17055;">
        <h4 style="margin-top:0; color:#d63031;"><i class="fas fa-shield-alt"></i> Day Order Control</h4>
        <div style="display:grid; gap:10px; align-items:center; grid-template-columns: 1fr 1fr 1fr;">
            <div>
                <label class="muted">DATE</label>
                <input type="date" id="adminDate" class="form-input" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label class="muted">DAY ORDER</label>
                <select id="adminDay" class="form-input">
                    <option value="1">Day Order 1</option><option value="2">Day Order 2</option>
                    <option value="3">Day Order 3</option><option value="4">Day Order 4</option>
                    <option value="5">Day Order 5</option>
                </select>
            </div>
            <div style="display:flex; align-items:end;">
                <button class="btn btn-primary w-100" onclick="setDayOrder()"><?= $is_dayorder_admin ? 'Freeze Day' : 'Update Day' ?></button>
            </div>
        </div>
        <?php if($is_dayorder_admin): ?>
        <div class="mt-2">
            <button class="btn btn-outline" onclick="deleteDayOrder()">Delete Day Order</button>
        </div>
        <?php endif; ?>
        <div class="mt-3">
            <label class="muted">TAKE ATTENDANCE FOR FACULTY</label>
            <input type="text" id="targetFaculty" class="form-input" list="facultyList" placeholder="Type Faculty ID or Name">
            <datalist id="facultyList">
                <?php foreach($faculty_list as $f): ?>
                    <option value="<?= htmlspecialchars($f['ID_NO']) ?>"><?= htmlspecialchars($f['NAME']) ?> (<?= htmlspecialchars($f['DEPARTMENT']) ?>)</option>
                <?php endforeach; ?>
            </datalist>
            <div class="muted mt-1">Hint: Type ID (e.g., ID_NO) or select from list.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="tab-nav">
        <button class="tab-btn active" onclick="showTab('take')" id="btn-take">Take Attendance</button>
        <button class="tab-btn" onclick="showTab('history')" id="btn-history">History Logs</button>
    </div>

    <div id="tab-take">
        <div class="card">
            <div style="display:grid; grid-template-columns: 1fr 1fr auto; gap:15px; align-items:end;">
                <div><label style="font-size:0.8rem; font-weight:700; color:#b2bec3;">DATE</label><input type="date" id="attDate" class="form-input" value="<?= date('Y-m-d') ?>" onchange="checkAdminDayOrder()"></div>
                <div><label style="font-size:0.8rem; font-weight:700; color:#b2bec3;">DAY ORDER</label>
                    <select id="dayOrder" class="form-input">
                        <?php for($i=1;$i<=5;$i++) echo "<option value='$i'>Order $i</option>"; ?>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="loadSchedule()" style="height:50px;">Load</button>
            </div>
            <div id="adminDayMsg" style="margin-top:10px; color:var(--success); font-weight:bold; display:none; background:#e0f7fa; padding:10px; border-radius:10px;"></div>
        </div>
        <div id="scheduleGrid" class="att-grid"></div>
    </div>

    <div id="tab-history" style="display:none;">
        <div class="card" id="historyList" style="padding:0;"></div>
    </div>

    <div id="takingAttendance">
        <div class="sticky-toolbar">
            <div>
                <h3 id="attTitle" style="margin:0; font-size:1.2rem; background:var(--insta); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">Subject</h3>
                <div id="attClassDetails" style="font-size:0.85rem; color:#636e72;"></div>
            </div>
            <div style="display:flex; gap:10px;">
                <input type="text" id="searchBox" class="form-input" placeholder="Filter..." onkeyup="filterSt()" style="padding:8px 15px;">
                <button class="btn btn-outline" onclick="closeAtt()">Close</button>
            </div>
        </div>
        <div id="studentGrid" class="student-list"></div>
        <div class="footer-bar">
            <div style="font-size:1.1rem;">
                <span style="color:var(--success); font-weight:800;">P: <span id="cP">0</span></span> &nbsp;
                <span style="color:var(--danger); font-weight:800;">A: <span id="cA">0</span></span>
            </div>
            <button class="btn btn-primary" onclick="saveAtt()">Submit <i class="fas fa-check"></i></button>
        </div>
    </div>

    <div id="histModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:2200; justify-content:center; align-items:center;">
        <div style="background:white; width:90%; max-width:500px; height:80vh; border-radius:24px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
                <h3 style="margin:0;">Class Details</h3>
                <button onclick="$('#histModal').fadeOut()" style="border:none; background:transparent; font-size:1.5rem; cursor:pointer;">&times;</button>
            </div>
            <div id="histContent" style="overflow-y:auto; flex:1; padding:15px;"></div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const API = window.location.pathname.split('?')[0];
$('#loader').hide();

function showToast(msg, type='success') {
    let col = type==='error'?'var(--danger)':'var(--success)';
    let t = $(`<div style="position:fixed; bottom:90px; left:50%; transform:translateX(-50%); background:${col}; color:white; padding:12px 25px; border-radius:50px; z-index:9999; box-shadow:0 10px 30px rgba(0,0,0,0.2); font-weight:600; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-${type==='error'?'exclamation-circle':'check-circle'}"></i> ${msg}</div>`);
    $('body').append(t); setTimeout(()=>t.fadeOut(()=>t.remove()), 3500);
}

function showTab(t) {
    $('#tab-take, #tab-history').hide();
    $('#tab-'+t).fadeIn();
    $('.tab-btn').removeClass('active'); $(`#btn-${t}`).addClass('active');
    if(t==='history') loadHistory();
}

function getTargetFaculty() {
    const el = document.getElementById('targetFaculty');
    return el ? el.value.trim() : '';
}

function setDayOrder() {
    $.post(API, { action:'set_day_order', date:$('#adminDate').val(), day_order:$('#adminDay').val() }, function(res) {
        if (res.status === 'success') {
            showToast(res.message);
            checkAdminDayOrder();
        } else {
            showToast(res.message || 'Unable to freeze day order', 'error');
        }
    }, 'json').fail(function() {
        showToast('Network error while updating day order', 'error');
    });
}
function deleteDayOrder() {
    if(!confirm('Delete day order for selected date?')) return;
    $.post(API, { action:'delete_day_order', date:$('#adminDate').val() }, function(res) {
        if (res.status === 'success') {
            showToast(res.message);
            checkAdminDayOrder();
        } else {
            showToast(res.message || 'Unable to delete day order', 'error');
        }
    }, 'json').fail(function() {
        showToast('Network error while deleting day order', 'error');
    });
}
function checkAdminDayOrder() {
    $.post(API, { action:'check_day_order', date:$('#attDate').val() }, function(res) {
        if(res.status==='success' && res.locked) {
            $('#dayOrder').val(res.day_order).prop('disabled',true);
            $('#adminDayMsg').html(`<i class="fas fa-lock"></i> Official Day Order: ${res.day_order}`).slideDown();
        } else {
            $('#dayOrder').prop('disabled',false);
            if (res.message) {
                $('#adminDayMsg').html(res.message).slideDown();
            } else {
                $('#adminDayMsg').slideUp();
            }
        }
    }, 'json').fail(function() {
        $('#adminDayMsg').html('Unable to check day order').slideDown();
    });
}
$(document).ready(function(){ checkAdminDayOrder(); });

function loadSchedule() {
    let d = $('#attDate').val(); let o = $('#dayOrder').val();
    if(!o) return showToast("Select Day Order", "error");
    $('#loader').show();
    $.post(API, { action:'get_schedule', date:d, day_order:o, target_fid: getTargetFaculty() }, function(res) {
        $('#loader').hide();
        if(res.status !== 'success') {
            showToast(res.message || 'Unable to load schedule', 'error');
            return;
        }
        let h = '';
        if(!res.data || res.data.length===0) h = '<div style="text-align:center; padding:50px; color:#b2bec3;"><i class="fas fa-calendar-times" style="font-size:3rem; margin-bottom:10px;"></i><br>No classes scheduled.</div>';
        else {
            res.data.forEach(c => {
                let badge = c.is_done ? '<span style="color:var(--success); font-weight:bold;"><i class="fas fa-check"></i> DONE</span>' : '<span style="color:var(--warning); font-weight:bold;">PENDING</span>';
                h += `<div class="class-card ${c.is_done?'done':'pending'}" onclick="openAtt('${c.unique_code}','${c.subject_code}','${c.subject_name}','${c.hour_slot}',${c.is_locked})">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <span style="background:#f1f2f6; padding:4px 10px; border-radius:20px; font-size:0.8rem; font-weight:700;">HOUR ${c.hour_slot}</span> ${badge}
                    </div>
                    <h3 style="margin:5px 0;">${c.subject_name}</h3>
                    <small style="color:#636e72;">${c.subject_code} • ${c.unique_code}</small>
                </div>`;
            });
        }
        $('#scheduleGrid').html(h);
    }, 'json').fail(function() {
        $('#loader').hide();
        showToast('Network error while loading schedule', 'error');
    });
}

let currClass = {};
function openAtt(uc, sc, sn, hr, locked) {
    if(locked) return showToast("Attendance Locked", "error");
    currClass = { unique_code:uc, subject_code:sc, hour:hr };
    $('#attTitle').text(sn); $('#attClassDetails').text(`${sc} | Hour ${hr}`);
    $('#loader').show();
    $.post(API, { action:'get_students', unique_code:uc }, function(res) {
        $('#loader').hide();
        let h = '';
        res.data.forEach(s => {
            h += `<div class="student-item" data-name="${s.NAME.toLowerCase()}">
                <div style="display:flex; align-items:center;">
                    <div class="student-avatar">${s.NAME[0]}</div>
                    <div><div style="font-weight:700;">${s.NAME}</div><small style="color:#b2bec3;">${s.ID_NO}</small></div>
                </div>
                <div class="status-actions">
                    <input type="hidden" id="val_${s.ID_NO}" value="P">
                    <button class="status-btn P active" onclick="setSt(this,'P','${s.ID_NO}')">P</button>
                    <button class="status-btn A" onclick="setSt(this,'A','${s.ID_NO}')">A</button>
                    <button class="status-btn OD" onclick="setSt(this,'OD','${s.ID_NO}')">O</button>
                </div>
            </div>`;
        });
        $('#studentGrid').html(h);
        $('#takingAttendance').fadeIn();
        updCounts();
    }, 'json');
}

function setSt(btn, st, id) {
    $(btn).parent().find('.status-btn').removeClass('active');
    $(btn).addClass('active');
    $(`#val_${id}`).val(st);
    updCounts();
}

function updCounts() {
    $('#cP').text($('.status-btn.P.active').length);
    $('#cA').text($('.status-btn.A.active').length);
}

function saveAtt() {
    if(!confirm("Submit Attendance?")) return;
    let students = [];
    $('.student-item').each(function() {
        let id = $(this).find('input').attr('id').replace('val_','');
        let st = $(this).find('input').val();
        students.push({id:id, status:st});
    });
    $('#loader').show();

    $.post(API, { action:'save_attendance', ...currClass, date:$('#attDate').val(), day_order:$('#dayOrder').val(), students:JSON.stringify(students), target_fid: getTargetFaculty() }, function(res) {
        $('#loader').hide();
        if(res.status==='success') {
            showToast(res.message);
            closeAtt();
            showTab('history');
            loadHistory();
        } else {
            showToast(res.message || 'Save failed', 'error');
        }
    }, 'json');
}

function closeAtt() { $('#takingAttendance').fadeOut(); }
function filterSt() { let v = $('#searchBox').val().toLowerCase(); $('.student-item').toggle(function(){ return $(this).data('name').indexOf(v)>-1; }); }

function loadHistory() {
    $('#loader').show();
    $.post(API, { action:'get_history', target_fid: getTargetFaculty() }, function(res) {
        $('#loader').hide();
        if (res.status !== 'success') {
            showToast(res.message || 'Unable to load history', 'error');
            return;
        }
        let h = '<table class="history-table"><thead><tr><th>Date</th><th>Subject</th><th>Stats</th><th>View</th></tr></thead><tbody>';
        if (!res.data || res.data.length === 0) {
            $('#historyList').html('<div style="padding:30px; text-align:center; color:#b2bec3;">No history found.</div>');
            return;
        }
        res.data.forEach(r => {
            let col = r.percent >= 75 ? '#00b894' : '#ff7675';
            h += `<tr>
                <td><b>${r.date_formatted}</b><br><small style="color:#b2bec3;">Hr ${r.hour}</small></td>
                <td>${r.subject_code}</td>
                <td><span style="color:${col}; font-weight:800;">${r.percent}%</span></td>
                <td><button class="btn btn-outline" style="padding:6px 12px; font-size:0.8rem;" onclick="viewHist('${r.unique_code}','${r.date}','${r.hour}','${r.subject_code}')">View</button></td>
            </tr>`;
        });
        $('#historyList').html(h+'</tbody></table>');
    }, 'json').fail(function() {
        $('#loader').hide();
        showToast('Network error while loading history', 'error');
    });
}

function viewHist(uc, d, h, sc) {
    $.post(API, { action:'get_history_details', unique_code:uc, date:d, hour:h, subject_code:sc }, function(res) {
        let htm = '';
        res.data.forEach(s => {
             let c = s.status==='P'?'var(--success)':'var(--danger)';
             htm += `<div style="padding:12px; border-bottom:1px solid #f1f2f6; display:flex; justify-content:space-between; align-items:center;">
                <div><b>${s.student_name}</b><br><small style="color:#b2bec3;">${s.student_id}</small></div>
                <div style="font-weight:900; color:${c}; background:#f8fafc; padding:5px 10px; border-radius:10px;">${s.status}</div>
             </div>`;
        });
        $('#histContent').html(htm); $('#histModal').fadeIn();
    }, 'json');
}
</script>
<?php include '../includes/footer.php'; ?>
