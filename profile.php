<?php
// profile.php - ENTERPRISE v32.1 (Admin Create Faculty + Server Locks)
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$security_path = __DIR__ . '/platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}

$db_path = find_include_path($include_paths, 'includes/db.php');
if (!$db_path) {
    http_response_code(500);
    echo 'Missing include: db.php';
    exit();
}
require_once $db_path;

// 1. AUTHENTICATION
$my_role = strtolower($_SESSION['role'] ?? '');
$my_id   = $_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? '';

if (empty($my_id)) {
    header("Location: ../login.php");
    exit();
}

$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

// 2. ADMIN CHECK
$is_admin = false;
$stmt = $mysqli->prepare("SELECT role FROM employee_details1 WHERE ID_NO = ? AND role = 'admin'");
if ($stmt) {
    $stmt->bind_param("s", $my_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $is_admin = true;
    }
}

// 3. TARGET RESOLUTION
$target_id = ($is_admin && isset($_GET['id']) && !empty($_GET['id'])) ? $_GET['id'] : $my_id;

// --- 4. AJAX API ---
if (isset($_GET['ajax_action'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    if (!$is_admin) {
        echo json_encode(['error' => 'Access Denied']);
        exit;
    }

    $action = $_GET['ajax_action'];

    if ($action === 'search') {
        $term = $mysqli->real_escape_string($_GET['term']);
        $results = [];
        $q1 = "SELECT ID_NO as id, NAME as name, 'Faculty' as type FROM employee_details1 WHERE ID_NO LIKE '%$term%' OR NAME LIKE '%$term%' LIMIT 5";
        $r1 = $mysqli->query($q1);
        if ($r1) {
            while ($r = $r1->fetch_assoc()) {
                $results[] = $r;
            }
        }

        $q2 = "SELECT id_no as id, student_name as name, 'Student (1st Yr)' as type FROM students_batch_25_26 WHERE id_no LIKE '%$term%' OR student_name LIKE '%$term%' LIMIT 3";
        $r2 = $mysqli->query($q2);
        if ($r2) {
            while ($r = $r2->fetch_assoc()) {
                $results[] = $r;
            }
        }

        $q3 = "SELECT IDNo as id, Name as name, 'Student (Senior)' as type FROM students_login_master WHERE IDNo LIKE '%$term%' OR Name LIKE '%$term%' LIMIT 3";
        $r3 = $mysqli->query($q3);
        if ($r3) {
            while ($r = $r3->fetch_assoc()) {
                $results[] = $r;
            }
        }

        echo json_encode($results);
        exit;
    }

    if ($action === 'relieve_user') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Invalid method']);
            exit;
        }
        if (function_exists('vh_require_csrf_or_exit')) {
            vh_require_csrf_or_exit(true);
        }
        $id = $_POST['id'];
        $type = $_POST['type'];
        $status = $_POST['status'];
        $reason = $_POST['reason'];
        $date = $_POST['date'];
        $admin_id = $_SESSION['user_id'] ?? 'Admin';

        $log_sql = "INSERT INTO relieved_users_log (id_no, user_type, status, reason, relieved_date, action_by) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_log = $mysqli->prepare($log_sql);
        $stmt_log->bind_param("ssssss", $id, $type, $status, $reason, $date, $admin_id);
        $stmt_log->execute();

        if ($type === 'faculty') {
            $stmt_relieve = $mysqli->prepare("UPDATE employee_details1 SET is_relieved='Yes', relieved_date=?, relieved_reason=? WHERE ID_NO=?");
            if ($stmt_relieve) {
                $stmt_relieve->bind_param("sss", $date, $reason, $id);
                $stmt_relieve->execute();
            }
        } else {
            if (!in_array($type, ['student_fresh', 'student_senior'], true)) {
                echo json_encode(['error' => 'Invalid user type']);
                exit;
            }
            $table = ($type === 'student_fresh') ? 'students_batch_25_26' : 'students_login_master';
            $id_col = ($type === 'student_fresh') ? 'id_no' : 'IDNo';
            $stmt_status = $mysqli->prepare("UPDATE {$table} SET current_status=? WHERE {$id_col}=?");
            if ($stmt_status) {
                $stmt_status->bind_param("ss", $status, $id);
                $stmt_status->execute();
            }

            foreach (['transport_allocation', 'hostel_boys_titans', 'hostel_girls_padmavathy'] as $tbl) {
                $stmt_del = $mysqli->prepare("DELETE FROM {$tbl} WHERE id_no=?");
                if ($stmt_del) {
                    $stmt_del->bind_param("s", $id);
                    $stmt_del->execute();
                }
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax_action']) && function_exists('vh_require_csrf_or_exit')) {
    vh_require_csrf_or_exit(false);
}

// --- 5. DATA FETCHING ---

function get_student_data($mysqli, $id) {
    global $is_admin;
    $profile = ['type' => 'student', 'data' => [], 'ui' => [], 'percent' => 0];

    // Check Freshers
    $stmt = $mysqli->prepare("SELECT * FROM students_batch_25_26 WHERE id_no = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $profile['subtype'] = 'student_fresh';
        $profile['table'] = 'students_batch_25_26';
        $profile['is_relieved'] = ($row['current_status'] !== 'Active' && !empty($row['current_status']));
        $profile['relieved_info'] = $row['current_status'];

        $profile['ui'] = [
            'name' => $row['student_name'], 'reg' => $row['register_no'], 'dept' => $row['department'],
            'batch' => $row['batch'], 'student_mobile' => $row['student_mobile'],
            'father_name' => $row['parent_name'], 'mother_name' => '',
            'parent_mobile' => $row['father_mobile'],
            'year_adm' => substr($row['batch'], 0, 4),
            'quota' => $row['quota'], 'scholarship' => $row['scholarship'],
            'dob' => $row['dob'], 'community' => $row['community'],
            'aadhaar' => $row['aadhaar_no'], 'addr' => $row['address'], 'pin' => $row['pincode'],
            'photo' => $row['profile_photo']
        ];
    } else {
        // Check Seniors
        $stmt = $mysqli->prepare("SELECT * FROM students_login_master WHERE IDNo = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $profile['subtype'] = 'student_senior';
            $profile['table'] = 'students_login_master';
            $profile['is_relieved'] = ($row['current_status'] !== 'Active' && !empty($row['current_status']));
            $profile['relieved_info'] = $row['current_status'];

            $profile['ui'] = [
                'name' => $row['Name'], 'reg' => $row['RegisterNo'], 'dept' => $row['Dept'],
                'batch' => $row['Batch'], 'student_mobile' => $row['Student_contact'],
                'father_name' => $row['fathername'], 'mother_name' => $row['mothername'],
                'parent_mobile' => $row['parent_contact'],
                'year_adm' => $row['yearofadmission'],
                'quota' => $row['typeofadmission'], 'scholarship' => '',
                'dob' => $row['DateofBirth'], 'community' => $row['community'],
                'aadhaar' => $row['aadhaar_no'], 'addr' => $row['address'], 'pin' => $row['pincode'],
                'photo' => $row['profile_photo']
            ];
        } else {
            return null;
        }
    }

    // Waterfall Status Logic
    $hb_room = '';
    $hg_room = '';
    $hb_stmt = $mysqli->prepare("SELECT room_no FROM hostel_boys_titans WHERE id_no = ? LIMIT 1");
    if ($hb_stmt) {
        $hb_stmt->bind_param("s", $id);
        $hb_stmt->execute();
        $hb_res = $hb_stmt->get_result();
        if ($hb_res && ($hb_row = $hb_res->fetch_assoc())) {
            $hb_room = (string) ($hb_row['room_no'] ?? '');
        }
    }
    $hg_stmt = $mysqli->prepare("SELECT room_no FROM hostel_girls_padmavathy WHERE id_no = ? LIMIT 1");
    if ($hg_stmt) {
        $hg_stmt->bind_param("s", $id);
        $hg_stmt->execute();
        $hg_res = $hg_stmt->get_result();
        if ($hg_res && ($hg_row = $hg_res->fetch_assoc())) {
            $hg_room = (string) ($hg_row['room_no'] ?? '');
        }
    }

    $res_status = "Day Scholar (Own Transport)";
    $route = "N/A";
    $point = "";
    $badge_color = "#6b7280";

    if ($hb_room !== '') {
        $res_status = "Hosteller (Titans - Room " . $hb_room . ")";
        $route = "Hostel";
        $badge_color = "#7c3aed";
    } elseif ($hg_room !== '') {
        $res_status = "Hosteller (Padmavathy - Room " . $hg_room . ")";
        $route = "Hostel";
        $badge_color = "#db2777";
    } else {
        $t_stmt = $mysqli->prepare("SELECT route_no, pickup_point FROM transport_allocation WHERE id_no = ?");
        $t_stmt->bind_param("s", $id);
        $t_stmt->execute();
        $tres = $t_stmt->get_result();
        if ($t_row = $tres->fetch_assoc()) {
            $res_status = "Day Scholar (College Bus)";
            $route = $t_row['route_no'];
            $point = $t_row['pickup_point'];
            $badge_color = "#d97706";
        }
    }

    if ($profile['is_relieved']) {
        if ($is_admin) {
            $res_status = "INACTIVE / " . strtoupper($profile['relieved_info']);
        } else {
            $res_status = "Inactive";
        }
        $badge_color = "#dc2626";
    }

    $profile['status'] = ['resid' => $res_status, 'route' => $route, 'point' => $point, 'color' => $badge_color];

    // Scholarship array
    $profile['ui']['schol_arr'] = array_map('trim', explode(',', $profile['ui']['scholarship'] ?? ''));

    // Completeness
    $req = ['name', 'reg', 'student_mobile', 'father_name', 'addr', 'photo', 'aadhaar'];
    $filled = 0;
    foreach ($req as $k) {
        if (!empty($profile['ui'][$k])) {
            $filled++;
        }
    }
    $profile['percent'] = round(($filled / count($req)) * 100);

    return $profile;
}

function get_faculty_data($mysqli, $id) {
    global $is_admin;
    $stmt = $mysqli->prepare("SELECT * FROM employee_details1 WHERE ID_NO = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $p = ['type' => 'faculty', 'table' => 'employee_details1', 'data' => $row];

        $p['is_relieved'] = (isset($row['is_relieved']) && $row['is_relieved'] === 'Yes');
        if ($is_admin) {
            $p['relieved_info'] = $p['is_relieved'] ? "Relieved (" . $row['relieved_date'] . ")" : "Active";
        } else {
            $p['relieved_info'] = $p['is_relieved'] ? "Inactive" : "Active";
        }

        $p['ui'] = [
            'name' => $row['NAME'], 'id' => $row['ID_NO'], 'dept' => $row['DEPARTMENT'],
            'desig' => $row['DESIGNATION'], 'qual' => $row['QUALIFICATION'],
            'email' => $row['EMAIL'], 'phone' => $row['PHONE_NO'],
            'dob' => $row['date_of_birth'], 'addr' => $row['STAFF_ADD1'] ?? '',
            'photo' => $row['photovarchar'] // Correct Column for Faculty
        ];
        $req = ['email', 'phone', 'dob', 'photo', 'qual'];
        $filled = 0;
        foreach ($req as $k) {
            if (!empty($p['ui'][$k])) {
                $filled++;
            }
        }
        $p['percent'] = round(($filled / count($req)) * 100);
        return $p;
    }
    return null;
}

function can_edit_value($val, $is_admin) {
    if ($is_admin) {
        return true;
    }
    $val = trim((string) $val);
    return ($val === '' || $val === '0' || strtoupper($val) === 'N/A');
}

// --- 6. INITIALIZE ---
$active_profile = null;
$student_profile = get_student_data($mysqli, $target_id);
if ($student_profile) {
    $active_profile = $student_profile;
} else {
    $faculty_profile = get_faculty_data($mysqli, $target_id);
    if ($faculty_profile) {
        $active_profile = $faculty_profile;
    }
}

// Admin: Faculty Column Metadata
$faculty_columns = [];
if ($is_admin) {
    $col_res = $mysqli->query("SHOW COLUMNS FROM employee_details1");
    if ($col_res) {
        while ($col = $col_res->fetch_assoc()) {
            $faculty_columns[] = $col;
        }
    }
}

// --- 6.5 ADMIN CREATE FACULTY ---
$create_msg = "";
$create_msg_type = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_faculty']) && $is_admin) {
    $fields = [];
    $values = [];
    $types = '';
    $id_required = '';

    foreach ($faculty_columns as $col) {
        $field = $col['Field'];
        $extra = strtolower($col['Extra'] ?? '');
        if (strpos($extra, 'auto_increment') !== false) {
            continue;
        }
        $key = 'new_' . $field;
        $val = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
        if ($field === 'ID_NO') {
            $id_required = $val;
        }
        if ($val === '') {
            $val = null;
        }
        $fields[] = $field;
        $values[] = $val;
        $types .= 's';
    }

    if ($id_required === '') {
        $create_msg = "ID_NO is required to create a new faculty profile.";
        $create_msg_type = "error";
    } elseif (!empty($fields)) {
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $sql = "INSERT INTO employee_details1 (" . implode(', ', $fields) . ") VALUES ($placeholders)";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) {
                $create_msg = "New faculty profile created successfully.";
                $create_msg_type = "success";
            } else {
                $create_msg = "Error creating faculty: " . $mysqli->error;
                $create_msg_type = "error";
            }
        } else {
            $create_msg = "Error preparing insert: " . $mysqli->error;
            $create_msg_type = "error";
        }
    } else {
        $create_msg = "No columns found to insert.";
        $create_msg_type = "error";
    }
}

// --- 7. SAVE LOGIC ---
$msg = "";
$msg_type = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile']) && $active_profile) {
    $p = $active_profile;
    $id = $target_id;
    $d = $p['ui'];

    // STRICT LOCK: If relieved, NO ONE can edit.
    if ($p['is_relieved'] ?? false) {
        die("Action Denied: This user is Relieved/Inactive. Data is read-only.");
    }

    $photo_db_val = $d['photo'];
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            // Determine Folder based on Type
            $folder = ($p['type'] == 'student') ? "../uploads/profiles/" : "../uploads/faculty_photos/";
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            // Rename to ID_Timestamp
            $new_name = $id . "_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $folder . $new_name)) {
                $photo_db_val = $new_name;
            }
        }
    }

    if ($p['type'] === 'student') {
        $reg = $_POST['reg'];
        $dob = $_POST['dob'];
        $smob = $_POST['student_mobile'];
        $dad = $_POST['father_name'];
        $mom = $_POST['mother_name'];
        $pmob = $_POST['parent_mobile'];
        $addr = $_POST['addr'];
        $pin = $_POST['pin'];
        $aadh = $_POST['aadhaar'];
        $comm = $_POST['comm'];
        $quota = $_POST['quota'];

        if (!can_edit_value($d['reg'], $is_admin)) $reg = $d['reg'];
        if (!can_edit_value($d['dob'], $is_admin)) $dob = $d['dob'];
        if (!can_edit_value($d['student_mobile'], $is_admin)) $smob = $d['student_mobile'];
        if (!can_edit_value($d['father_name'], $is_admin)) $dad = $d['father_name'];
        if (!can_edit_value($d['mother_name'], $is_admin)) $mom = $d['mother_name'];
        if (!can_edit_value($d['parent_mobile'], $is_admin)) $pmob = $d['parent_mobile'];
        if (!can_edit_value($d['addr'], $is_admin)) $addr = $d['addr'];
        if (!can_edit_value($d['pin'], $is_admin)) $pin = $d['pin'];
        if (!can_edit_value($d['aadhaar'], $is_admin)) $aadh = $d['aadhaar'];
        if (!can_edit_value($d['community'], $is_admin)) $comm = $d['community'];
        if (!can_edit_value($d['quota'], $is_admin)) $quota = $d['quota'];

        $schol_arr = $_POST['scholarship'] ?? [];
        $schol = is_array($schol_arr) ? implode(', ', $schol_arr) : '';
        if (!$is_admin && !empty($d['scholarship'])) {
            $schol = $d['scholarship'];
        }

        $board = $_POST['boarding_point'];
        $route_in = $_POST['route_no'] ?? '';
        if (!$is_admin) {
            $current_route = $active_profile['status']['route'] ?? '';
            $current_point = $active_profile['status']['point'] ?? '';
            if (!can_edit_value($current_route, $is_admin)) $route_in = $current_route;
            if (!can_edit_value($current_point, $is_admin)) $board = $current_point;
        }

        if (!empty($board)) {
            $stmt_transport = $mysqli->prepare(
                "INSERT INTO transport_allocation (id_no, pickup_point, route_no)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE pickup_point = VALUES(pickup_point), route_no = VALUES(route_no)"
            );
            if ($stmt_transport) {
                $stmt_transport->bind_param("sss", $id, $board, $route_in);
                $stmt_transport->execute();
            }
        }

        if ($p['table'] == 'students_batch_25_26') {
            $stmt = $mysqli->prepare("UPDATE students_batch_25_26 SET register_no=?, dob=?, student_mobile=?, parent_name=?, father_mobile=?, address=?, pincode=?, aadhaar_no=?, community=?, scholarship=?, quota=?, profile_photo=? WHERE id_no=?");
            $stmt->bind_param("sssssssssssss", $reg, $dob, $smob, $dad, $pmob, $addr, $pin, $aadh, $comm, $schol, $quota, $photo_db_val, $id);
        } else {
            $stmt = $mysqli->prepare("UPDATE students_login_master SET RegisterNo=?, DateofBirth=?, Student_contact=?, fathername=?, mothername=?, parent_contact=?, address=?, pincode=?, aadhaar_no=?, community=?, typeofadmission=?, profile_photo=? WHERE IDNo=?");
            $stmt->bind_param("sssssssssssss", $reg, $dob, $smob, $dad, $mom, $pmob, $addr, $pin, $aadh, $comm, $quota, $photo_db_val, $id);
        }
    } elseif ($p['type'] === 'faculty') {
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $dob = $_POST['dob'];
        $desig = $_POST['desig'];
        $qual = $_POST['qual'];
        $addr = $_POST['addr'];

        if (!can_edit_value($d['email'], $is_admin)) $email = $d['email'];
        if (!can_edit_value($d['phone'], $is_admin)) $phone = $d['phone'];
        if (!can_edit_value($d['dob'], $is_admin)) $dob = $d['dob'];
        if (!can_edit_value($d['desig'], $is_admin)) $desig = $d['desig'];
        if (!can_edit_value($d['qual'], $is_admin)) $qual = $d['qual'];
        if (!can_edit_value($d['addr'], $is_admin)) $addr = $d['addr'];

        // Correct Column: photovarchar
        $stmt = $mysqli->prepare("UPDATE employee_details1 SET EMAIL=?, PHONE_NO=?, date_of_birth=?, DESIGNATION=?, QUALIFICATION=?, STAFF_ADD1=?, photovarchar=? WHERE ID_NO=?");
        $stmt->bind_param("ssssssss", $email, $phone, $dob, $desig, $qual, $addr, $photo_db_val, $id);
    }

    if (isset($stmt) && $stmt->execute()) {
        $msg = "Profile updated successfully!";
        $msg_type = "success";
        if ($p['type'] === 'student') {
            $active_profile = get_student_data($mysqli, $id);
        } else {
            $active_profile = get_faculty_data($mysqli, $id);
        }
    } else {
        $msg = "Error: " . $mysqli->error;
        $msg_type = "error";
    }
}

// Helpers
function getLockStatus($val) { global $is_admin; return ($is_admin || empty($val) || $val == '0' || $val == 'N/A') ? '' : 'readonly_perm'; }
function getDisabledStatus($val) { global $is_admin; return ($is_admin || empty($val) || $val == '0' || $val == 'N/A') ? '' : 'disabled_perm'; }

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root { --brand: #bc1888; --insta-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); --bg-soft: #f8fafc; }
    body { background-color: var(--bg-soft); font-family: 'Outfit', sans-serif; }
    .profile-container { max-width: 900px; margin: 20px auto; padding: 0 15px 80px; }

    .search-wrap { position: relative; margin-bottom: 20px; }
    .search-box { width: 100%; padding: 12px 20px; border-radius: 50px; border: 2px solid #e2e8f0; outline: none; transition: 0.2s; }
    .search-box:focus { border-color: #cc2366; box-shadow: 0 0 0 4px rgba(204, 35, 102, 0.1); }
    .search-res { position: absolute; top: 100%; left: 0; width: 100%; background: white; z-index: 100; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; display: none; }
    .res-item { padding: 10px 20px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
    .res-item:hover { background: #f8fafc; color: #cc2366; }

    .profile-card { background: #fff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08); margin-bottom: 25px; border: 1px solid rgba(0,0,0,0.03); }
    .cover-photo { height: 130px; background: var(--insta-grad); }
    .profile-content { padding: 0 30px 40px; text-align: center; margin-top: -70px; position: relative; }
    .avatar-wrapper { width: 140px; height: 140px; border-radius: 50%; background: #fff; padding: 5px; margin: 0 auto 15px; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .avatar-img { width: 100%; height: 100%; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 3.5rem; color: #cc2366; background-size: cover; background-position: center; font-weight: 800; }
    .upload-trigger { position: absolute; bottom: 5px; right: 5px; background: #1e293b; color: #fff; width: 40px; height: 40px; border-radius: 50%; display: none; align-items: center; justify-content: center; cursor: pointer; border: 3px solid #fff; transition: transform 0.2s; }
    body.editing .upload-trigger { display: flex; animation: popIn 0.3s; }

    .user-name { margin: 10px 0 5px; font-size: 1.8rem; font-weight: 800; color: #1e293b; }
    .user-meta { color: #64748b; font-size: 0.95rem; font-weight: 500; }

    .section-title { font-size: 1.1rem; font-weight: 800; color: #334155; margin: 35px 0 20px; display: flex; align-items: center; gap: 10px; }
    .section-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; margin-left: 15px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    .input-group { background: #fff; padding: 5px; border-radius: 12px; }
    .input-label { display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 6px; }
    .input-field { width: 100%; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 10px; padding: 12px 15px; font-weight: 600; outline: none; transition: all 0.2s; }
    .input-field:read-only:not(.edit-mode) { background: transparent; border-color: transparent; padding-left: 0; cursor: default; }
    .input-field.edit-mode { background: #fff; border-color: #cbd5e1; }
    .req-tag { background: #fee2e2; color: #b91c1c; font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; display: none; }
    body.editing .req-tag { display: inline-block; }

    /* Scholarship Grid */
    .schol-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 5px; }
    .schol-item { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; color: #475569; }
    .schol-item input { accent-color: var(--brand); transform: scale(1.2); }
    .schol-item input:disabled { opacity: 0.6; }

    .fab-edit { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background: var(--insta-grad); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; cursor: pointer; border: none; box-shadow: 0 10px 30px rgba(220, 39, 67, 0.4); z-index: 100; transition: transform 0.2s; }
    .fab-edit:hover { transform: scale(1.1); }
    .save-bar { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(100px); background: #fff; padding: 10px 25px; border-radius: 50px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); display: flex; gap: 15px; z-index: 101; transition: 0.3s; border: 1px solid #e2e8f0; }
    body.editing .save-bar { transform: translateX(-50%) translateY(0); }
    .btn-main { background: #10b981; color: #fff; border: none; padding: 10px 20px; border-radius: 30px; font-weight: 700; cursor: pointer; }
    .btn-sec { background: transparent; color: #64748b; border: none; font-weight: 600; cursor: pointer; }
    .btn-danger { background: #ef4444; color: #fff; border: none; padding: 10px 20px; border-radius: 30px; font-weight: 700; cursor: pointer; margin-right: auto; }
    .progress-track { height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden; margin-top: 10px; }
    .progress-fill { height: 100%; background: var(--insta-grad); width: <?= $active_profile['percent'] ?? 0 ?>%; }
    .admin-controls { display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 20px; }
    .relieved-banner { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; text-align: center; margin-bottom: 15px; font-weight: bold; border: 1px solid #fecaca; }
    .new-faculty-card { background: #fff; border-radius: 16px; padding: 20px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
    .new-faculty-card summary { cursor: pointer; font-weight: 700; }
    @keyframes popIn { from { transform: scale(0); } to { transform: scale(1); } }
</style>

<div class="profile-container">

    <?php if($is_admin): ?>
    <div class="search-wrap">
        <input type="text" class="search-box" id="search" placeholder="Search Faculty or Student ID/Name..." autocomplete="off">
        <div class="search-res" id="searchResults"></div>
    </div>

    <details class="new-faculty-card">
        <summary><i class="fas fa-user-plus me-2"></i> Create New Faculty Profile</summary>
        <?php if($create_msg): ?>
            <div class="alert alert-<?= $create_msg_type === 'success' ? 'success' : 'danger' ?> mt-3"><?= htmlspecialchars($create_msg) ?></div>
        <?php endif; ?>
        <form method="POST" class="mt-3">
            <input type="hidden" name="create_faculty" value="1">
            <input type="hidden" name="_csrf" value="<?= vh_e($csrf_token) ?>">
            <div class="form-grid">
                <?php foreach($faculty_columns as $col): ?>
                    <?php
                        $field = $col['Field'];
                        $extra = strtolower($col['Extra'] ?? '');
                        if (strpos($extra, 'auto_increment') !== false) continue;
                        $type = strtolower($col['Type'] ?? 'text');
                        $input_type = 'text';
                        if (strpos($type, 'date') !== false) $input_type = 'date';
                        elseif (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) $input_type = 'number';
                    ?>
                    <div class="input-group">
                        <label class="input-label"><?= htmlspecialchars($field) ?></label>
                        <input type="<?= $input_type ?>" name="new_<?= htmlspecialchars($field) ?>" class="input-field" placeholder="<?= htmlspecialchars($field) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 text-end">
                <button type="submit" class="btn-main"><i class="fas fa-save me-2"></i> Create Faculty</button>
            </div>
        </form>
    </details>

    <?php if($active_profile): ?>
    <div class="admin-controls">
         <?php if(!$active_profile['is_relieved']): ?>
         <button type="button" class="btn-main" onclick="enableEdit()" style="background:#4f46e5;"><i class="fas fa-edit me-2"></i> Edit Profile</button>
         <button type="button" class="btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="fas fa-trash me-2"></i> Relieve User</button>
         <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$active_profile): ?>
        <div class="text-center text-muted mt-5"><i class="fas fa-id-badge fa-3x mb-3 text-secondary"></i><h3>Profile Not Found</h3></div>
    <?php else: $d = $active_profile['ui']; $type = $active_profile['type']; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="save_profile" value="1">
        <input type="hidden" name="_csrf" value="<?= vh_e($csrf_token) ?>">

        <div class="profile-card">
            <div class="cover-photo"></div>
            <div class="profile-content">
                <div class="avatar-wrapper">
                    <?php
                        $img = $d['photo'];
                        if ($type == 'faculty' && $img && strpos($img, '/') === false) $img_path = "../uploads/faculty_photos/" . $img;
                        elseif ($img) $img_path = "../uploads/profiles/" . $img;
                        else $img_path = "";
                    ?>
                    <div class="avatar-img" style="<?= $img_path ? "background-image:url('$img_path?t=".time()."');" : "" ?>"><?= !$img_path ? strtoupper(substr($d['name'], 0, 1)) : '' ?></div>
                    <label class="upload-trigger" for="pic"><i class="fas fa-camera"></i></label>
                    <input type="file" id="pic" name="profile_photo" style="display:none" onchange="preview(this)">
                </div>
                <h1 class="user-name"><?= htmlspecialchars($d['name']) ?></h1>
                <div class="user-meta"><?= htmlspecialchars($target_id) ?> • <?= htmlspecialchars($d['dept']) ?></div>
                <div class="progress-track" style="max-width:300px; margin: 15px auto 0;"><div class="progress-fill"></div></div>
            </div>
        </div>

        <?php if($active_profile['is_relieved'] && $is_admin): ?>
            <div class="relieved-banner"><i class="fas fa-user-slash me-2"></i> <?= htmlspecialchars($active_profile['relieved_info']) ?></div>
        <?php endif; ?>

        <?php if($msg): ?><div class="alert alert-<?= ($msg_type=='success')?'success':'danger' ?> mb-4"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <?php if($type == 'student'): $s = $active_profile['status']; ?>
        <div class="section-title"><i class="fas fa-user-circle"></i> Identity & Admission</div>
        <div class="form-grid">
            <div class="input-group"><label class="input-label">Register No</label><input type="text" name="reg" class="input-field" value="<?= htmlspecialchars($d['reg']) ?>" <?= getLockStatus($d['reg']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Batch</label><input type="text" name="batch" class="input-field" value="<?= htmlspecialchars($d['batch']) ?>" readonly></div>
            <div class="input-group"><label class="input-label">Year of Admission</label><input type="text" name="year_adm" class="input-field" value="<?= htmlspecialchars($d['year_adm']) ?>" <?= getLockStatus($d['year_adm']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Quota</label><input type="text" name="quota" class="input-field" value="<?= htmlspecialchars($d['quota']) ?>" <?= getLockStatus($d['quota']) ?> readonly></div>

            <div class="input-group" style="grid-column:1/-1;">
                <label class="input-label">Scholarship (Multi-Select)</label>
                <div class="schol-grid">
                    <?php
                    $opts = ["7.5% Govt School", "BC/MBC", "PMSS", "FG", "Tamil Pudhalvan", "Pudhumai Penn", "Merit"];
                    $my_schols = $d['schol_arr'] ?? [];
                    // Logic: If relieved, fully disabled. If Active + Admin/Empty, enabled.
                    $isDisabled = ($active_profile['is_relieved'] || (!$is_admin && !empty($d['scholarship']))) ? 'disabled' : '';
                    foreach($opts as $opt) {
                        $chk = in_array($opt, $my_schols) ? 'checked' : '';
                        echo "<label class='schol-item'><input type='checkbox' name='scholarship[]' value='$opt' $chk $isDisabled> $opt</label>";
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="section-title"><i class="fas fa-address-card"></i> Personal & Family</div>
        <div class="form-grid">
            <div class="input-group"><label class="input-label">DOB</label><input type="text" name="dob" class="input-field" value="<?= htmlspecialchars($d['dob']) ?>" readonly></div>
            <div class="input-group"><label class="input-label">Community</label><select name="comm" class="input-field" disabled <?= getDisabledStatus($d['community']) ?>><option value="">Select</option><?php foreach(['OC','BC','BCM','MBC','DNC','SC','SCA','ST'] as $c) echo "<option value='$c' ".($d['community']==$c?'selected':'').">$c</option>"; ?></select></div>
            <div class="input-group"><label class="input-label">Aadhaar</label><input type="text" name="aadhaar" class="input-field" value="<?= htmlspecialchars($d['aadhaar']) ?>" <?= getLockStatus($d['aadhaar']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Father Name</label><input type="text" name="father_name" class="input-field" value="<?= htmlspecialchars($d['father_name']) ?>" <?= getLockStatus($d['father_name']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Mother Name</label><input type="text" name="mother_name" class="input-field" value="<?= htmlspecialchars($d['mother_name']) ?>" <?= getLockStatus($d['mother_name']) ?> readonly></div>
        </div>

        <div class="section-title"><i class="fas fa-phone-alt"></i> Contact Details</div>
        <div class="form-grid">
            <div class="input-group"><label class="input-label">Student Mobile</label><input type="text" name="student_mobile" class="input-field" value="<?= htmlspecialchars($d['student_mobile']) ?>" <?= getLockStatus($d['student_mobile']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Parent Mobile</label><input type="text" name="parent_mobile" class="input-field" value="<?= htmlspecialchars($d['parent_mobile']) ?>" <?= getLockStatus($d['parent_mobile']) ?> readonly></div>
            <div class="input-group" style="grid-column:1/-1"><label class="input-label">Address</label><input type="text" name="addr" class="input-field" value="<?= htmlspecialchars($d['addr']) ?>" <?= getLockStatus($d['addr']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Pincode</label><input type="text" name="pin" class="input-field" value="<?= htmlspecialchars($d['pin']) ?>" <?= getLockStatus($d['pin']) ?> readonly></div>
        </div>

        <div class="section-title"><i class="fas fa-bus"></i> Status & Transport</div>
        <div class="form-grid">
            <div class="input-group" style="grid-column:1/-1"><label class="input-label">Residential Status</label><div class="input-field" style="color:<?= $s['color'] ?>; background:#f5f3ff; font-weight:700;"><?= htmlspecialchars($s['resid']) ?></div></div>
            <?php if(strpos($s['resid'], 'College Bus') !== false || $is_admin): ?>
            <div class="input-group"><label class="input-label">Transport Route</label><input type="text" name="route_no" class="input-field" value="<?= htmlspecialchars($s['route']) ?>" <?= getLockStatus($s['route']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Boarding Point</label><input type="text" name="boarding_point" class="input-field" value="<?= htmlspecialchars($s['point']) ?>" <?= getLockStatus($s['point']) ?> readonly></div>
            <?php endif; ?>
        </div>

        <?php elseif($type == 'faculty'): ?>
        <div class="section-title"><i class="fas fa-briefcase"></i> Professional Details</div>
        <div class="form-grid">
            <div class="input-group"><label class="input-label">Designation</label><input type="text" name="desig" class="input-field" value="<?= htmlspecialchars($d['desig']) ?>" <?= getLockStatus($d['desig']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Qualification</label><input type="text" name="qual" class="input-field" value="<?= htmlspecialchars($d['qual']) ?>" <?= getLockStatus($d['qual']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Email</label><input type="text" name="email" class="input-field" value="<?= htmlspecialchars($d['email']) ?>" <?= getLockStatus($d['email']) ?> readonly></div>
            <div class="input-group"><label class="input-label">Phone</label><input type="text" name="phone" class="input-field" value="<?= htmlspecialchars($d['phone']) ?>" <?= getLockStatus($d['phone']) ?> readonly></div>
            <div class="input-group" style="grid-column:1/-1"><label class="input-label">Address</label><input type="text" name="addr" class="input-field" value="<?= htmlspecialchars($d['addr']) ?>" <?= getLockStatus($d['addr']) ?> readonly></div>
        </div>
        <?php endif; ?>

        <?php if(!$active_profile['is_relieved']): ?>
        <div class="save-bar">
            <button type="button" class="btn-sec" onclick="location.reload()">Cancel</button>
            <button type="submit" class="btn-main" id="saveBtn"><i class="fas fa-check"></i> Save</button>
        </div>
        <?php endif; ?>
    </form>

    <?php if(!$active_profile['is_relieved']): ?>
    <button class="fab-edit" id="editBtn" onclick="enableEdit()"><i class="fas fa-pen"></i></button>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if($is_admin): ?>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-danger">Relieve User</h5></div>
            <div class="modal-body">
                <p class="text-muted small">This action will mark the user as relieved/inactive.</p>
                <div class="mb-3"><label class="form-label small fw-bold">Date</label><input type="date" id="rel_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="mb-3"><label class="form-label small fw-bold">Status</label><select id="rel_status" class="form-select"><option>Relieved</option><option>Long Absent</option><option>Deceased</option><option>Medical Leave</option><option>TC Issued</option></select></div>
                <div class="mb-3"><label class="form-label small fw-bold">Reason</label><textarea id="rel_reason" class="form-control" rows="2"></textarea></div>
                <button class="btn btn-danger w-100 fw-bold" onclick="confirmRelieve()">Confirm Action</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
const searchInput = document.getElementById('search');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        const val = this.value;
        const res = document.getElementById('searchResults');
        if (val.length < 2) { res.style.display = 'none'; return; }
        fetch(`profile.php?ajax_action=search&term=${val}`).then(r => r.json()).then(data => {
            res.innerHTML = '';
            if (data.length > 0) {
                res.style.display = 'block';
                data.forEach(s => {
                    const div = document.createElement('div');
                    div.className = 'res-item';
                    div.innerHTML = `<strong>${s.name}</strong> <span class="text-muted small">(${s.id})</span>`;
                    div.onclick = () => window.location.href = `profile.php?id=${s.id}`;
                    res.appendChild(div);
                });
            } else { res.style.display = 'none'; }
        });
    });
}

function enableEdit() {
    document.body.classList.add('editing');
    // Enable inputs
    document.querySelectorAll('.input-field').forEach(el => {
        if (!el.hasAttribute('readonly_perm') || <?= $is_admin ? 'true' : 'false' ?>) {
            el.removeAttribute('readonly'); el.removeAttribute('disabled'); el.classList.add('edit-mode');
        }
    });
    // Enable checkboxes
    document.querySelectorAll('.schol-item input').forEach(el => {
        if (<?= $is_admin ? 'true' : 'false' ?> || !el.hasAttribute('checked')) {
            el.removeAttribute('disabled');
        }
    });
    document.getElementById('editBtn').style.transform = 'scale(0)';
}

function confirmRelieve() {
    const fd = new FormData();
    fd.append('id', '<?= $target_id ?>');
    fd.append('type', '<?= $active_profile['subtype'] ?? $type ?>');
    fd.append('status', document.getElementById('rel_status').value);
    fd.append('reason', document.getElementById('rel_reason').value);
    fd.append('date', document.getElementById('rel_date').value);
    fd.append('_csrf', CSRF_TOKEN);

    fetch('profile.php?ajax_action=relieve_user', {method:'POST', body:fd}).then(r => r.json()).then(res => {
        if (res.success) { alert("User Relieved Successfully."); location.reload(); }
        else { alert("Error: " + res.error); }
    });
}

function preview(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.querySelector('.avatar-img').style.backgroundImage = 'url(' + e.target.result + ')';
            document.querySelector('.avatar-img').innerHTML = '';
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
