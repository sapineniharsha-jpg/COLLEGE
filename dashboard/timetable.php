<?php
// hod_timetable.php - ENTERPRISE v99.0 (Granular Types + Matrix Sync)
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);

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
    echo json_encode(['status' => 'error', 'message' => 'Missing include: db.php']);
    exit;
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

// --- 1. DB CONNECTION SAFETY ---
if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    die(json_encode(['status' => 'error', 'message' => 'Database connection failure']));
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    if (isset($_POST['delete_id'])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(401);
        }
        echo json_encode(['status' => 'error', 'message' => 'Session Expired']);
        exit;
    }
    header('Location: /login.php');
    exit();
}

$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';
$self_page = basename(__FILE__);

// --- 2. SESSION SELF-HEALING ---
$user_id = trim((string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? ''));
$user_role = strtoupper(trim((string) ($_SESSION['role'] ?? 'USER')));
$user_dept = strtoupper(trim((string) ($_SESSION['DEPARTMENT'] ?? '')));

if ($user_role === 'HOD' && $user_id !== '') {
    $q = $mysqli->prepare("SELECT DEPARTMENT FROM employee_details1 WHERE ID_NO = ? LIMIT 1");
    if ($q) {
        $q->bind_param("s", $user_id);
        $q->execute();
        $res = $q->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $user_dept = strtoupper(trim((string) ($row['DEPARTMENT'] ?? '')));
            $_SESSION['DEPARTMENT'] = $user_dept;
        }
    }
}

// --- 3. ROBUST DEPARTMENT MAPPING ---
$dept_full_names = [
    'CSE'     => 'B.E- COMPUTER SCIENCE AND ENGINEERING',
    'ECE'     => 'B.E- ELECTRONICS AND COMMUNICATION ENGINEERING',
    'MECH'    => 'B.E- MECHANICAL ENGINEERING',
    'CIVIL'   => 'B.E- CIVIL ENGINEERING',
    'IT'      => 'B.TECH- INFORMATION TECHNOLOGY',
    'AIDS'    => 'B.TECH- ARTIFICIAL INTELLIGENCE AND DATA SCIENCE',
    'AIML'    => 'B.E- COMPUTER SCIENCE AND ENGINEERING(ARTIFICIAL INTELLIGENCE AND MACHINE LEARNING)',
    'BIOTECH' => 'B.TECH- BIOTECHNOLOGY',
    'STRU'    => 'M.E- STRUCTURAL ENGINEERING',
    'CHEM'    => 'B.TECH. CHEMICAL ENGINEERING',
    'MBA'     => 'PG- MBA',
    'S&H'     => 'SCIENCE AND HUMANITIES',
];

if (!function_exists('get_dept_aliases')) {
    function get_dept_aliases($my_dept, $map)
    {
        $aliases = [];
        $my_dept_norm = strtoupper(trim((string) $my_dept));
        $aliases[] = $my_dept_norm;
        foreach ($map as $short => $full) {
            $short = strtoupper($short);
            $full = strtoupper($full);
            if ($my_dept_norm === $short) {
                $aliases[] = $full;
            } elseif ($my_dept_norm === $full) {
                $aliases[] = $short;
            }
        }
        $noise_words = ['DEPARTMENT', 'DEPT', 'ENGINEERING', 'OF', 'B.E', 'B.TECH', 'M.E', 'M.TECH', 'PG', '-', '.', '(', ')'];
        $core = str_replace($noise_words, '', $my_dept_norm);
        $core = trim($core);
        if (!empty($core) && strlen($core) > 2) {
            $aliases[] = $core;
        }
        return array_values(array_unique(array_filter($aliases)));
    }
}

// --- 4. DEFINE ROLES & CAPABILITIES ---
$is_admin       = ($user_role === 'ADMIN');
$is_hod         = ($user_role === 'HOD');
$is_student     = ($user_role === 'STUDENT');
$is_faculty     = ($user_role === 'FACULTY');
$is_super_view  = in_array($user_role, ['DEAN', 'DEAN_ACADEMICS', 'PRINCIPAL', 'VC', 'ADMIN'], true);

$can_create_edit = ($is_admin || $is_hod);
$can_view_all    = $is_super_view;

// --- 5. HANDLE AJAX DELETE ---
if (isset($_POST['delete_id'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');

    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(true);
    }

    if (!$can_create_edit) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
        exit;
    }

    $del_id = (int) $_POST['delete_id'];
    $check_stmt = $mysqli->prepare("SELECT id, department, status, unique_code FROM timetable_master WHERE id = ?");
    if (!$check_stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Internal error']);
        exit;
    }
    $check_stmt->bind_param("i", $del_id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();
    if (!$check_res || $check_res->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
        exit;
    }
    $row = $check_res->fetch_assoc();

    if ($is_hod && !$is_admin) {
        $allowed_aliases = get_dept_aliases($user_dept, $dept_full_names);
        $is_owner = false;
        foreach ($allowed_aliases as $alias) {
            $dept_val = strtoupper((string) ($row['department'] ?? ''));
            $alias_val = strtoupper((string) $alias);
            if ($alias_val !== '' && (strpos($dept_val, $alias_val) !== false || strpos($alias_val, $dept_val) !== false)) {
                $is_owner = true;
                break;
            }
        }
        if (!$is_owner) {
            echo json_encode(['status' => 'error', 'message' => 'Department restricted']);
            exit;
        }
        if (($row['status'] ?? '') === 'PUBLISHED') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Only Admin can delete PUBLISHED timetables.']);
            exit;
        }
    }

    $unique_code = (string) ($row['unique_code'] ?? '');

    $mysqli->begin_transaction();
    try {
        $del_details = $mysqli->prepare("DELETE FROM timetable_details WHERE master_id = ?");
        $del_details->bind_param("i", $del_id);
        $del_details->execute();

        $del_subjects = $mysqli->prepare("DELETE FROM timetable_subjects WHERE master_id = ?");
        $del_subjects->bind_param("i", $del_id);
        $del_subjects->execute();

        $del_matrix = $mysqli->prepare("DELETE FROM timetable_matrix WHERE unique_code = ?");
        $del_matrix->bind_param("s", $unique_code);
        $del_matrix->execute();

        $del_master = $mysqli->prepare("DELETE FROM timetable_master WHERE id = ?");
        $del_master->bind_param("i", $del_id);
        $del_master->execute();

        $mysqli->commit();
        echo json_encode(['status' => 'success']);
    } catch (Throwable $e) {
        $mysqli->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- 6. PERMISSIONS GATE ---
$allowed_roles = ['ADMIN', 'PRINCIPAL', 'DEAN', 'DEAN_ACADEMICS', 'HOD', 'FACULTY', 'STUDENT', 'VC'];
if (!in_array($user_role, $allowed_roles, true)) {
    die('Access Denied.');
}

// --- 7. LOAD DROPDOWNS ---
$prog_sql = "SELECT DISTINCT program_name FROM program_master";
if ($is_hod && !$is_admin) {
    $aliases = get_dept_aliases($user_dept, $dept_full_names);
    $like_clauses = [];
    foreach ($aliases as $alias) {
        $safe = $mysqli->real_escape_string($alias);
        $like_clauses[] = "program_name LIKE '%{$safe}%'";
    }
    if (!empty($like_clauses)) {
        $prog_sql .= " WHERE (" . implode(' OR ', $like_clauses) . ")";
    }
}
$prog_sql .= " ORDER BY program_name ASC";
$prog_res = $mysqli->query($prog_sql);
$programs = ($prog_res) ? $prog_res->fetch_all(MYSQLI_ASSOC) : [];

// --- 8. LOAD TIMETABLES ---
$safe_user_dept = $mysqli->real_escape_string($user_dept);
$saved_sql = "SELECT * FROM timetable_master WHERE 1=1";
if ($is_student) {
    $saved_sql .= " AND department = '{$safe_user_dept}' AND status = 'PUBLISHED'";
} elseif ($is_faculty && !$is_hod && !$can_view_all) {
    $saved_sql .= " AND department = '{$safe_user_dept}' AND status = 'PUBLISHED'";
} elseif ($is_hod && !$is_admin) {
    $aliases = get_dept_aliases($user_dept, $dept_full_names);
    $dept_clauses = [];
    foreach ($aliases as $a) {
        $safe_a = $mysqli->real_escape_string($a);
        $dept_clauses[] = "department = '{$safe_a}'";
        $dept_clauses[] = "department LIKE '%{$safe_a}%'";
    }
    if (!empty($dept_clauses)) {
        $saved_sql .= " AND (" . implode(" OR ", $dept_clauses) . ")";
    } else {
        $saved_sql .= " AND department = '{$safe_user_dept}'";
    }
}
$saved_sql .= " ORDER BY updated_at DESC";
$result = $mysqli->query($saved_sql);
$saved_data = ($result) ? $result->fetch_all(MYSQLI_ASSOC) : [];

// --- 9. EDIT MODE VARIABLES ---
$edit_mode = false;
$load_master = [
    'program' => '',
    'batch' => '',
    'semester' => 1,
    'intake' => 60,
    'section' => 'A',
    'class_advisor' => '',
    'class_advisor_id' => '',
    'status' => 'PUBLISHED',
    'department' => $user_dept,
    'advisors_json' => '{}',
];
$load_sectionData = (object) ['A' => (object) []];
$load_subjectCache = [];
$preloaded_grid = [];

if (isset($_GET['edit_id']) && $can_create_edit) {
    if ($_GET['edit_id'] === 'new') {
        $edit_mode = true;
    } else {
        $edit_id = (int) $_GET['edit_id'];
        $m_stmt = $mysqli->prepare("SELECT * FROM timetable_master WHERE id = ?");
        if ($m_stmt) {
            $m_stmt->bind_param("i", $edit_id);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();

            if ($m_res && $m_res->num_rows > 0) {
                $row = $m_res->fetch_assoc();
                $allow = false;
                if ($is_admin) {
                    $allow = true;
                } elseif ($is_hod) {
                    $aliases = get_dept_aliases($user_dept, $dept_full_names);
                    foreach ($aliases as $a) {
                        $row_dept = strtoupper((string) ($row['department'] ?? ''));
                        $alias = strtoupper((string) $a);
                        if ($alias !== '' && (strpos($row_dept, $alias) !== false || strpos($alias, $row_dept) !== false)) {
                            $allow = true;
                            break;
                        }
                    }
                }

                if ($allow) {
                    $edit_mode = true;
                    $load_master = $row;
                    if (empty($load_master['advisors_json'])) {
                        $load_master['advisors_json'] = json_encode([
                            $load_master['section'] => [
                                'name' => $load_master['class_advisor'],
                                'id' => $load_master['class_advisor_id'],
                            ],
                        ]);
                    }

                    $target_sec = (string) $load_master['section'];
                    $load_sectionData = (object) [$target_sec => (object) []];

                    $d_stmt = $mysqli->prepare("SELECT * FROM timetable_details WHERE master_id = ? ORDER BY day, hour");
                    if ($d_stmt) {
                        $d_stmt->bind_param("i", $edit_id);
                        $d_stmt->execute();
                        $d_res = $d_stmt->get_result();
                        if ($d_res) {
                            while ($r = $d_res->fetch_assoc()) {
                                $code = (string) $r['subject_code'];
                                if (!isset($load_sectionData->$target_sec)) {
                                    $load_sectionData->$target_sec = (object) [];
                                }

                                if (!isset($load_sectionData->$target_sec->$code)) {
                                    $load_sectionData->$target_sec->$code = [
                                        'subName' => $r['subject_name'],
                                        'facId' => $r['faculty_id'],
                                        'facName' => $r['faculty_name'],
                                        'type' => $r['type'],
                                        'cat' => $r['type'],
                                        'group' => isset($r['group_name']) ? $r['group_name'] : 'ENTIRE',
                                    ];
                                }
                                if (!isset($load_subjectCache[$code])) {
                                    $load_subjectCache[$code] = [
                                        'name' => $r['subject_name'],
                                        'type' => $r['type'],
                                        'cat' => $r['type'],
                                        'L' => $r['l'] ?? 0,
                                        'T' => $r['t'] ?? 0,
                                        'P' => $r['p'] ?? 0,
                                        'credits' => $r['c'] ?? 0,
                                    ];
                                }
                                if (!isset($preloaded_grid[$r['day']])) {
                                    $preloaded_grid[$r['day']] = [];
                                }
                                $preloaded_grid[$r['day']][$r['hour']] = [
                                    'code' => $code,
                                    'fac' => $r['faculty_id'],
                                    'name' => $r['faculty_name'],
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
}

// --- 10. MY TIMETABLE (Faculty) ---
$my_schedule = [];
if (!$is_student) {
    $my_tt_sql = "SELECT d.day, d.hour, d.subject_name, d.subject_code, m.batch, m.section, m.department
                  FROM timetable_details d
                  JOIN timetable_master m ON d.master_id = m.id
                  WHERE d.faculty_id = ? AND m.status = 'PUBLISHED'
                  ORDER BY d.day, d.hour";
    $my_tt_stmt = $mysqli->prepare($my_tt_sql);
    if ($my_tt_stmt) {
        $my_tt_stmt->bind_param("s", $user_id);
        $my_tt_stmt->execute();
        $my_tt_res = $my_tt_stmt->get_result();
        if ($my_tt_res) {
            while ($row = $my_tt_res->fetch_assoc()) {
                $my_schedule[(int) $row['day']][(int) $row['hour']] = $row;
            }
        }
    }
}

$special_subjects = ['VERBAL', 'QUANTZ', 'LOGICAL', 'CODING', 'SPORTS', 'LIBRARY', 'COUNSELING', 'MINI PROJECT', 'MENTOR'];

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}

$advisors_from_master = [];
if ($edit_mode && !empty($load_master['advisors_json'])) {
    $tmp_adv = json_decode((string) $load_master['advisors_json'], true);
    if (is_array($tmp_adv)) {
        $advisors_from_master = $tmp_adv;
    }
}
if ($edit_mode && empty($advisors_from_master)) {
    $advisors_from_master[(string) $load_master['section']] = [
        'name' => (string) ($load_master['class_advisor'] ?? ''),
        'id' => (string) ($load_master['class_advisor_id'] ?? ''),
    ];
}
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<link rel="stylesheet" href="../assets/css/style.css">
<style>
    :root {
        --insta-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
        --bg-body: #f8fafc;
    }
    body { background: var(--bg-body); font-family: 'Outfit', sans-serif; }
    .app-container { max-width: 1400px; margin: 20px auto; padding: 0 15px 80px; }

    .ui-autocomplete { z-index: 10050 !important; max-height: 250px; overflow-y: auto; overflow-x: hidden; border-radius: 8px; box-shadow: 0 15px 30px rgba(0,0,0,0.15); border:1px solid #e2e8f0; }

    .step-card { background: white; border-radius: 20px; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 30px; overflow: hidden; }
    .step-header { background: #fff; padding: 20px 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
    .step-title { font-size: 1.1rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 12px; }
    .step-badge { background: var(--insta-grad); color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; box-shadow: 0 4px 10px rgba(220, 39, 67, 0.3); }

    .card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 30px; }
    .card-header { padding: 20px 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fff; }
    .card-title { font-size: 1.2rem; font-weight: 800; background: var(--insta-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; padding: 30px; }
    .form-group label { display: block; font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input, .form-select { width: 100%; padding: 12px 16px; border: 2px solid #f1f5f9; border-radius: 12px; font-family: inherit; font-size: 0.95rem; font-weight: 500; background: #f8fafc; transition: 0.2s; }
    .form-input:focus, .form-select:focus { border-color: #cc2366; background: white; outline: none; box-shadow: 0 0 0 4px rgba(204, 35, 102, 0.05); }
    .form-input.is-valid { border-color: #22c55e; background-color: #f0fdf4; }
    .form-input.is-invalid { border-color: #ef4444; background-color: #fef2f2; }

    .alloc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; padding: 0 30px 30px; }
    .alloc-card { background: white; border-radius: 16px; padding: 20px; position: relative; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); transition: 0.3s; }
    .alloc-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px -5px rgba(0,0,0,0.15); }
    .alloc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.2); }
    .alloc-title h4 { margin: 0; font-size: 1.2rem; color: white; font-weight: 800; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .alloc-title p { margin: 2px 0 0; font-size: 0.85rem; color: rgba(255,255,255,0.9); font-weight: 600; }

    .matrix-table { width: 100%; border-collapse: collapse; }
    .matrix-table td { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.1); color: white; font-size: 0.9rem; }
    .matrix-table tr:last-child td { border-bottom: none; }
    .matrix-sec { font-weight: 800; width: 40px; }
    .matrix-fac { font-weight: 500; }
    .matrix-actions { text-align: right; }
    .btn-icon { background: rgba(255,255,255,0.2); border: none; color: white; border-radius: 6px; width: 28px; height: 28px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; margin-left: 5px; }
    .btn-icon:hover { background: white; color: #1e293b; }
    .btn-icon.add { background: rgba(255,255,255,0.9); color: #10b981; }
    .btn-icon.del { color: #fee2e2; }
    .btn-icon.del:hover { background: #ef4444; color: white; }

    .btn { padding: 12px 28px; border-radius: 50px; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; transition: 0.3s; }
    .btn-primary { background: var(--insta-grad); color: white; box-shadow: 0 8px 20px rgba(220, 39, 67, 0.25); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(220, 39, 67, 0.4); }
    .btn-outline { background: white; border: 2px solid #e2e8f0; color: #64748b; }
    .btn-mini { padding: 8px 14px; border-radius: 10px; font-size: 0.85rem; border: none; background: #f1f5f9; color: #64748b; cursor: pointer; text-decoration: none; }

    .list-table { width: 100%; border-collapse: collapse; min-width:800px; }
    .list-table th { padding: 18px; background: #f8fafc; text-align: left; color: #475569; border-bottom: 2px solid #e2e8f0; }
    .dept-row { border-bottom: 1px solid #f1f5f9; transition: 0.2s; border-left: 4px solid transparent; }
    .dept-row td { padding: 15px; }

    .dept-row.status-DRAFT { border-left-color: #f59e0b; }
    .dept-row.status-DRAFT:hover { background-color: #fffbeb; transform: translateX(5px); }
    .dept-row.status-PUBLISHED { border-left-color: #10b981; }
    .dept-row.status-PUBLISHED:hover { background-color: #ecfdf5; transform: translateX(5px); }

    .badge { padding: 5px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
    .badge.DRAFT { background: #fef3c7; color: #b45309; }
    .badge.PUBLISHED { background: #d1fae5; color: #047857; }

    .section-tabs { padding: 0 30px; margin-bottom: 20px; display: flex; gap: 10px; overflow-x: auto; }
    .sec-tab-btn { background: white; border: 2px solid #f1f5f9; padding: 10px 25px; border-radius: 30px; font-weight: 700; color: #94a3b8; cursor: pointer; transition: 0.2s; white-space: nowrap; }
    .sec-tab-btn.active { background: var(--insta-grad); color: white; border: none; box-shadow: 0 8px 20px rgba(220, 39, 67, 0.3); }

    .tt-table { width: 100%; border-collapse: separate; border-spacing: 6px; min-width: 900px; }
    .tt-table th { background: #f8fafc; color: #334155; padding: 15px; border-radius: 8px; font-size: 0.8rem; text-transform: uppercase; }
    .tt-table td { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0; height: 110px; width: 11%; position: relative; transition: 0.2s; }
    .tt-table td:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-color: #cbd5e1; }
    .day-col { background: var(--insta-grad) !important; color: white !important; font-weight: 800; writing-mode: vertical-rl; transform: rotate(180deg); text-align: center; width: 5%; }

    .cell-content { height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 8px; pointer-events: none; }
    .cell-code { font-weight: 800; font-size: 0.9rem; color: #1e293b; }
    .cell-sub { font-size: 0.7rem; color: #475569; font-weight: 600; text-transform: uppercase; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .cell-fac { font-size: 0.7rem; color: #fff; font-weight: 700; background: rgba(0,0,0,0.4); padding: 2px 6px; border-radius: 4px; backdrop-filter: blur(4px); }
    select.cell-select { width: 100%; height: 100%; position: absolute; top: 0; left: 0; opacity: 0; cursor: pointer; z-index: 2; }

    #toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 10060; }
    .toast { background: #1e293b; color: white; padding: 12px 24px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); margin-top: 10px; display: flex; align-items: center; gap: 10px; animation: slideIn 0.3s ease; }
    .toast.success { border-left: 5px solid #22c55e; }
    .toast.error { border-left: 5px solid #ef4444; }
    @keyframes slideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

    #page-loader { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); z-index: 99999; display: none; justify-content: center; align-items: center; backdrop-filter: blur(5px); }
    .spinner { width: 50px; height: 50px; border: 5px solid #f3f3f3; border-top: 5px solid #bc1888; border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    @media print { .no-print { display: none !important; } .step-card { box-shadow: none; border: none; } }
</style>

<div id="toast-container"></div>
<div id="page-loader"><div class="spinner"></div></div>

<div class="app-container">
    <div class="glass-header no-print">
        <div>
            <h1 style="margin:0; font-size:1.8rem; font-weight:900; background:var(--insta-grad); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">Academic Planner</h1>
            <p style="margin:5px 0 0; color:#64748b; font-weight:600;"><?= vh_e($is_student ? 'Student Portal' : ($user_dept . ' Department')) ?></p>
        </div>
        <?php if ($can_create_edit && !$edit_mode): ?>
        <button class="btn btn-primary" onclick="window.location.href='?edit_id=new&tab=institutional'">
            <i class="fas fa-plus-circle"></i> New Timetable
        </button>
        <?php endif; ?>
    </div>

    <div class="no-print" style="display:flex; justify-content:center; gap:15px; margin-bottom:30px;">
        <button class="btn btn-outline" id="btn-personal" onclick="window.location.href='?tab=personal'"><i class="fas fa-user-clock"></i> My Schedule</button>
        <button class="btn btn-primary" id="btn-institutional" onclick="window.location.href='?tab=institutional'"><i class="fas fa-university"></i> Department Timetables</button>
    </div>

    <div id="main-content">
        <?php if ($edit_mode): ?>

        <div class="step-card no-print">
            <div class="step-header">
                <div class="step-title"><div class="step-badge">1</div> Configuration</div>
                <button class="btn btn-outline" onclick="window.location.href='?tab=institutional'"><i class="fas fa-times"></i> Cancel</button>
            </div>
            <div class="form-grid">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Department / Program</label>
                    <select id="confDeptSelect" class="form-select" onchange="syncDeptProgram()">
                        <?php foreach ($programs as $p): ?>
                        <option value="<?= vh_e($p['program_name']) ?>" <?= ((string) $load_master['department'] === (string) $p['program_name']) ? 'selected' : '' ?>><?= vh_e($p['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" id="confProg">
                <input type="hidden" id="confDept">

                <div class="form-group"><label>Batch</label><select id="confBatch" class="form-select" onchange="updateSemesterOptions()">
                    <option value="2025-2029">2025-2029</option>
                    <option value="2024-2028" <?= ((string) $load_master['batch'] === '2024-2028') ? 'selected' : '' ?>>2024-2028</option>
                    <option value="2023-2027" <?= ((string) $load_master['batch'] === '2023-2027') ? 'selected' : '' ?>>2023-2027</option>
                    <option value="2022-2026" <?= ((string) $load_master['batch'] === '2022-2026') ? 'selected' : '' ?>>2022-2026</option>
                </select></div>
                <div class="form-group"><label>Semester</label><select id="confSem" class="form-select" onchange="checkFirstYear()"></select></div>
                <div class="form-group"><label>Intake Count</label><input type="number" id="confIntake" class="form-input" value="<?= vh_e((string) $load_master['intake']) ?>" onchange="calcSections()"></div>
                <div class="form-group"><label>Active Section (Edit Focus)</label><select id="currentSectionSelect" class="form-select" onchange="handleSectionChange()"></select></div>
            </div>

            <div style="padding: 0 30px 30px;">
                 <h4 style="margin:0 0 15px; font-size:0.9rem; color:#64748b; font-weight:700; text-transform:uppercase;">Class Advisors (Per Section)</h4>
                 <div id="advisorTableContainer"></div>
                 <input type="hidden" id="confAdvisor">
                 <input type="hidden" id="confAdvisorID">
            </div>
        </div>

        <div class="step-card no-print">
            <div class="step-header">
                <div class="step-title"><div class="step-badge">2</div> Subject Allocation Matrix</div>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>Category</label><select id="inCategory" class="form-select" onchange="updateTypeOptions()"><option value="ACADEMIC">Academic</option><option value="SPECIAL">Special</option></select></div>

                <div class="form-group">
                    <label>Type</label>
                    <select id="inType" class="form-select" onchange="toggleSubjectInput()"></select>
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label>Subject Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="inSubName" class="form-input" placeholder="Search Subject Code or Name...">
                    <select id="inSpecialSelect" class="form-select" style="display:none;"><?php foreach ($special_subjects as $s) { echo "<option>" . vh_e($s) . "</option>"; } ?></select>
                </div>

                <div class="form-group"><label>Code</label><input type="text" id="inSubCode" class="form-input readonly-field" readonly tabindex="-1"></div>

                <div class="form-group"><label>Credits (L-T-P-C)</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:5px;">
                        <input type="number" id="inL" class="form-input" value="3" placeholder="L">
                        <input type="number" id="inT" class="form-input" value="0" placeholder="T">
                        <input type="number" id="inP" class="form-input" value="0" placeholder="P">
                        <input type="number" id="inC" class="form-input" value="3" placeholder="C">
                    </div>
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label>Faculty <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="inFacSearch" class="form-input" placeholder="Search Faculty Name or ID...">
                    <input type="hidden" id="inFacID">
                    <input type="hidden" id="inFacName">
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="addPair()" style="width:100%; justify-content:center;">ADD / UPDATE</button>
                </div>
            </div>

            <div id="allocationList" class="alloc-grid"></div>
        </div>

        <div style="text-align:center; padding: 0 0 50px;">
            <button class="btn btn-primary" onclick="generateAllGrids()" style="font-size:1.1rem; padding:15px 40px; border-radius:50px;">
                <i class="fas fa-th"></i> &nbsp; GENERATE TIMETABLE GRID
            </button>
        </div>

        <div id="gridArea" class="no-print" style="display:none;">
            <div class="section-tabs" id="sectionTabs"></div>
            <div id="gridContainer"></div>

            <div class="step-card" style="margin-top:30px;">
                <div class="step-header"><div class="step-title">Workload Summary</div></div>
                <div id="summaryContainer" style="padding:20px;"></div>
            </div>

            <div class="step-card" style="border: 2px solid #bc1888;">
                <div class="step-header">
                    <div class="step-title" style="color:#bc1888;">Final Actions</div>
                    <div style="display:flex; gap:15px;">
                        <button class="btn btn-outline" onclick="saveData('DRAFT')"><i class="fas fa-save"></i> Save Draft</button>
                        <button class="btn btn-primary" onclick="saveData('PUBLISHED')"><i class="fas fa-check-circle"></i> Publish Now</button>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>

        <?php if (isset($_GET['tab']) && $_GET['tab'] === 'personal'): ?>

        <div class="card" id="myTimetableCard">
            <div class="card-header no-print">
                <div class="card-title"><i class="fas fa-calendar-check"></i> My Schedule</div>
                <div class="header-actions">
                    <button class="btn btn-outline" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
            <?php if (empty($my_schedule) && !$is_student): ?>
            <div class="empty-state" style="text-align:center; padding:60px;"><i class="fas fa-mug-hot" style="font-size:4rem; color:#cbd5e1; margin-bottom:20px;"></i><h3 style="color:#64748b;">No classes found.</h3></div>
            <?php else: ?>
            <div class="tt-wrapper">
                <table class="tt-table">
                    <thead><tr><th>Day</th><?php for ($i = 1; $i <= 8; $i++) { echo "<th>Hour {$i}</th>"; } ?></tr></thead>
                    <tbody>
                        <?php for ($d = 1; $d <= 5; $d++): ?>
                        <tr><td class="day-col">DAY <?= vh_e((string) $d) ?></td>
                            <?php for ($h = 1; $h <= 8; $h++): ?>
                            <td><div class="cell-content">
                                <?php if (isset($my_schedule[$d][$h])): $c = $my_schedule[$d][$h]; ?>
                                    <div class="cell-code" style="color:#bc1888;"><?= vh_e($c['subject_code']) ?></div>
                                    <div class="cell-sub" title="<?= vh_e($c['subject_name']) ?>"><?= vh_e($c['subject_name']) ?></div>
                                    <div class="cell-fac"><?= vh_e($c['batch']) ?>-<?= vh_e($c['section']) ?></div>
                                <?php else: ?>
                                    <span style="opacity:0.2; font-size:0.8rem;">FREE</span>
                                <?php endif; ?>
                            </div></td>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>

        <div class="card no-print">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-folder-tree"></i> Directory</div>
                <?php if ($can_view_all): ?><input type="text" id="deptFilter" placeholder="Search Department..." class="form-input" style="width:250px;" onkeyup="filterTable()"><?php endif; ?>
            </div>
            <div style="overflow-x:auto;">
                <table class="list-table">
                    <thead><tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; color:#475569;"><th style="padding:18px;">Program / Dept</th><th>Batch</th><th>Semester</th><th>Section</th><th>Updated</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (count($saved_data) > 0): foreach ($saved_data as $row):
                        $statusClass = ((string) $row['status'] === 'DRAFT') ? 'status-DRAFT' : 'status-PUBLISHED';
                        $statusBadge = ((string) $row['status'] === 'DRAFT') ? '<span class="badge DRAFT">PENDING</span>' : '<span class="badge PUBLISHED">COMPLETED</span>';
                    ?>
                        <tr class="dept-row <?= vh_e($statusClass) ?>">
                            <td class="dept-name"><span class="badge" style="background:#f1f5f9; color:#475569;"><?= vh_e($row['department']) ?></span></td>
                            <td><b><?= vh_e($row['batch']) ?></b></td>
                            <td>Semester <?= vh_e((string) $row['semester']) ?></td>
                            <td>Sec <?= vh_e($row['section']) ?></td>
                            <td style="font-size:0.8rem; color:var(--text-light);"><?= vh_e(date('d M, h:i A', strtotime((string) $row['updated_at']))) ?></td>
                            <td><?= $statusBadge ?></td>
                            <td style="display:flex; gap:5px;">
                                <a href="view_timetable.php?id=<?= (int) $row['id'] ?>" target="_blank" class="btn-mini" title="View"><i class="fas fa-eye"></i> View</a>
                                <?php
                                $can_modify_this = false;
                                if ($is_admin) {
                                    $can_modify_this = true;
                                } elseif ($is_hod) {
                                    $aliases = get_dept_aliases($user_dept, $dept_full_names);
                                    foreach ($aliases as $a) {
                                        $row_dept = strtoupper((string) $row['department']);
                                        $alias = strtoupper((string) $a);
                                        if (strpos($row_dept, $alias) !== false || strpos($alias, $row_dept) !== false) {
                                            if ((string) $row['status'] !== 'PUBLISHED') {
                                                $can_modify_this = true;
                                            }
                                            break;
                                        }
                                    }
                                }
                                if ($can_modify_this): ?>
                                    <a href="?edit_id=<?= (int) $row['id'] ?>&tab=institutional" class="btn-mini" title="Edit"><i class="fas fa-pen"></i></a>
                                    <button onclick="deleteTimetable(<?= (int) $row['id'] ?>)" class="btn-mini danger" title="Delete"><i class="fas fa-trash"></i></button>
                                <?php endif;
                                if ($is_admin && !$can_modify_this): ?>
                                    <button onclick="deleteTimetable(<?= (int) $row['id'] ?>)" class="btn-mini danger" title="Delete"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px;">No timetables found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
    const CSRF_TOKEN = <?= json_encode($csrf_token, JSON_UNESCAPED_SLASHES) ?>;
    const SELF_PAGE = <?= json_encode($self_page, JSON_UNESCAPED_SLASHES) ?>;
    const EDIT_ID = <?= (isset($_GET['edit_id']) && $_GET['edit_id'] !== 'new') ? (int) $_GET['edit_id'] : 'null' ?>;

    function withCsrf(data) {
        data._csrf = CSRF_TOKEN;
        return data;
    }

    function showLoader(){ $('#page-loader').css('display','flex'); }
    function hideLoader(){ $('#page-loader').fadeOut(200); }
    function showToast(msg, type='success') {
        let t = $(`<div class="toast ${type}"><i class="fas fa-info-circle"></i> ${msg}</div>`);
        $('#toast-container').append(t);
        setTimeout(() => t.remove(), 3000);
    }

    $('input[type="text"]').on('input', function() { if(!$(this).hasClass('ui-autocomplete-input')) $(this).val($(this).val().toUpperCase()); });

    function checkFileExistence() {
        $.ajax({url:'search_subject.php', type:'HEAD', error: function(){ console.error('search_subject.php NOT FOUND'); }});
        $.ajax({url:'search_faculty.php', type:'HEAD', error: function(){ console.error('search_faculty.php NOT FOUND'); }});
    }

    let sectionData = <?= json_encode($load_sectionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; if(Array.isArray(sectionData)) sectionData = {};
    let subjectCache = <?= json_encode($load_subjectCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; if(Array.isArray(subjectCache)) subjectCache = {};
    let preloadedGrid = <?= json_encode($preloaded_grid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let sectionAdvisors = <?= json_encode($advisors_from_master, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let activeSections = Object.keys(sectionData);

    const cardGradients = [
        'linear-gradient(135deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)',
        'linear-gradient(135deg, #833ab4 0%, #fd1d1d 50%, #fcb045 100%)',
        'linear-gradient(135deg, #FEAC5E 0%, #C779D0 50%, #4BC0C8 100%)',
        'linear-gradient(135deg, #EE9CA7 0%, #FFDDE1 100%)',
        'linear-gradient(135deg, #B24592 0%, #F15F79 100%)'
    ];
    let subjectColorMap = {};

    function getSubjectGradient(code){
        if(!code) return '#fff';
        if(subjectColorMap[code]) return subjectColorMap[code];
        let hash = 0; for (let i = 0; i < code.length; i++) hash = code.charCodeAt(i) + ((hash << 5) - hash);
        subjectColorMap[code] = cardGradients[Math.abs(hash) % cardGradients.length];
        return subjectColorMap[code];
    }

    function getGridCellColor(code) {
        if(!code) return '#fff';
        const colors = ['#fce7f3', '#f0fdf4', '#fff7ed', '#e0f2fe', '#f3f4f6', '#fff1f2'];
        let hash = 0; for (let i = 0; i < code.length; i++) hash = code.charCodeAt(i) + ((hash << 5) - hash);
        return colors[Math.abs(hash) % colors.length];
    }

    function switchMainTab(tabName) {
        $('.main-tab-btn').removeClass('active');
        $('#btn-personal').removeClass('btn-primary').addClass('btn-outline');
        $('#btn-institutional').removeClass('btn-primary').addClass('btn-outline');
        $('.tab-content-area').removeClass('active');

        if(tabName === 'personal') {
            $('#btn-personal').removeClass('btn-outline').addClass('btn-primary');
            $('#tab-personal').addClass('active');
        } else {
            $('#btn-institutional').removeClass('btn-outline').addClass('btn-primary');
            $('#tab-institutional').addClass('active');
        }
    }

    function refreshMySchedule() { showLoader(); setTimeout(() => { hideLoader(); location.reload(); }, 500); }

    function syncDeptProgram() {
        let val = $("#confDeptSelect").val();
        $("#confProg").val(val); $("#confDept").val(val);
        updateSemesterOptions();
    }

    $(document).ready(function() {
        checkFileExistence();
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'personal';
        switchMainTab(tab);
        updateTypeOptions();

        <?php if ($can_create_edit): ?>
            calcSections();
            let initialDept = <?= json_encode((string) $load_master['department']) ?>;
            if(initialDept) { $("#confDeptSelect").val(initialDept); syncDeptProgram(); }

            $("#inSubName").autocomplete({
                source: function(req, res) {
                    $.ajax({ url: "search_subject.php", dataType: "json", data: { term: req.term }, success: function(data) { res(data); } });
                },
                appendTo: ".form-grid",
                minLength: 1,
                select: function(event, ui) {
                    $("#inSubName").val(ui.item.value); $("#inSubName").addClass('is-valid').removeClass('is-invalid');
                    $("#inSubCode").val(ui.item.code);
                    $("#inL").val(ui.item.l || ui.item.L || 0);
                    $("#inT").val(ui.item.t || ui.item.T || 0);
                    $("#inP").val(ui.item.p || ui.item.P || 0);
                    $("#inC").val(ui.item.c || ui.item.C || 0);
                    return false;
                }
            }).on('input', function(){ $("#inSubCode").val(''); $(this).removeClass('is-valid').addClass('is-invalid'); });

            $("#inFacSearch").autocomplete({
                source: function(req, res) { $.ajax({ url: "search_faculty.php", dataType: "json", data: { term: req.term }, success: function(d) { res(d); } }); },
                select: function(e, ui) {
                    $("#inFacSearch").val(ui.item.value); $("#inFacSearch").addClass('is-valid').removeClass('is-invalid');
                    $("#inFacID").val(ui.item.id); $("#inFacName").val(ui.item.value);
                    return false;
                }
            }).on('input', function(){ $("#inFacID").val(''); $(this).removeClass('is-valid').addClass('is-invalid'); });

            $("#confAdvisor").autocomplete({
                source: function(req, res) { $.ajax({ url: "search_faculty.php", dataType: "json", data: { term: req.term }, success: function(d) { res(d); } }); },
                select: function(e, ui) {
                    let sec = $("#currentSectionSelect").val();
                    $("#confAdvisor").val(ui.item.value); $("#confAdvisor").addClass('is-valid').removeClass('is-invalid');
                    $("#confAdvisorID").val(ui.item.id);
                    $("#advisorStatus").html('<i class="fas fa-check-circle"></i> Valid Selection').css('color','green');
                    sectionAdvisors[sec] = { name: ui.item.value, id: ui.item.id };
                    return false;
                }
            }).on('input', function() {
                $("#confAdvisorID").val(''); $(this).removeClass('is-valid').addClass('is-invalid');
                $("#advisorStatus").html('<i class="fas fa-exclamation-circle"></i> Please select from list').css('color','red');
            });

            <?php if ($edit_mode): ?>
                $("#confBatch").val(<?= json_encode((string) $load_master['batch']) ?>);
                $("#confSem").val(<?= json_encode((string) $load_master['semester']) ?>); $("#confIntake").val(<?= json_encode((string) $load_master['intake']) ?>);

                renderAdvisorTable();

                setTimeout(() => {
                    generateAllGrids();
                    if(Object.keys(preloadedGrid).length > 0) {
                        activeSections.forEach(sec => { if(preloadedGrid[sec]) return; });
                        activeSections.forEach(sec => {
                             $.each(preloadedGrid, (d, hrs) => {
                                $.each(hrs, (h, data) => {
                                    let uid = `cell_${sec}_${d}_${h}`;
                                    let $sel = $(`#sel_${uid}`);
                                    if($sel.length) { $sel.val(data.code).trigger('change'); }
                                });
                             });
                        });
                    }
                    renderAllocationList();
                }, 800);
            <?php endif; ?>
        <?php endif; ?>
    });

    function updateSemesterOptions() {
        let batch = $("#confBatch").val();
        if(!batch) return;
        let sems = [];
        if (batch === '2025-2029') sems = [1, 2];
        else if (batch === '2024-2028') sems = [3, 4];
        else if (batch === '2023-2027') sems = [5, 6];
        else if (batch === '2022-2026') sems = [7, 8];
        else sems = [1,2,3,4,5,6,7,8];

        let prog = $("#confProg").val();
        if (prog && (prog.includes("M.E") || prog.includes("MBA") || prog.includes("M.TECH"))) {
             if (batch === '2025-2029') sems = [1, 2];
             else if (batch === '2024-2028') sems = [3, 4];
             else sems = [1,2,3,4];
        }
        let h = ''; sems.forEach(s => { h += `<option value="${s}">${s}</option>`; });
        $("#confSem").html(h); checkFirstYear();
    }

    function calcSections() {
        let n = parseInt($("#confIntake").val(), 10) || 60; let c = Math.ceil(n/60);
        activeSections = ['A','B','C','D','E','F'].slice(0,c);
        if(activeSections.length === 0) activeSections = ['A'];
        renderAdvisorTable();
        let h=''; activeSections.forEach(s => { h+=`<option value="${s}">${s}</option>`; if(!sectionData[s]) sectionData[s] = {}; });
        $("#currentSectionSelect").html(h).trigger('change');
    }

    function renderAdvisorTable() {
        let html = '<table class="list-table" style="border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;"><thead><tr style="background:#f8fafc;"><th style="width:20%;">Section</th><th>Class Advisor (Search & Select)</th></tr></thead><tbody>';
        activeSections.forEach(sec => {
            let existingName = (sectionAdvisors[sec]) ? sectionAdvisors[sec].name : '';
            let existingID = (sectionAdvisors[sec]) ? sectionAdvisors[sec].id : '';
            html += `<tr><td style="font-weight:800; color:#bc1888; text-align:center;">SECTION ${sec}</td><td><input type="text" class="form-input advisor-search" data-sec="${sec}" value="${existingName}" placeholder="Search Faculty Name..." style="width:100%;"><input type="hidden" class="advisor-id" id="adv_id_${sec}" value="${existingID}"></td></tr>`;
        });
        html += '</tbody></table>';
        $('#advisorTableContainer').html(html);

        $(".advisor-search").each(function() {
            let sec = $(this).data('sec');
            $(this).autocomplete({
                source: function(req, res) { $.ajax({ url: "search_faculty.php", dataType: "json", data: { term: req.term }, success: function(d) { res(d); } }); },
                select: function(e, ui) { $(this).val(ui.item.value); $(`#adv_id_${sec}`).val(ui.item.id); sectionAdvisors[sec] = { name: ui.item.value, id: ui.item.id }; return false; }
            }).on('input', function() { $(`#adv_id_${sec}`).val(''); if(sectionAdvisors[sec]) delete sectionAdvisors[sec]; });
        });
    }

    function handleSectionChange() { renderAllocationList(); }

    function updateTypeOptions() {
        let cat = $("#inCategory").val();
        let html = '';
        if (cat === 'ACADEMIC') {
            html += '<option value="T">Theory (T)</option>';
            html += '<option value="P">Practical (P)</option>';
            html += '<option value="IN">Integrated (IN)</option>';
            html += '<option value="PE">Professional Elective (PE)</option>';
            html += '<option value="OE">Open Elective (OE)</option>';
            $("#inSubName").show(); $("#inSpecialSelect").hide(); $("#inSubCode").prop('disabled', false);
        } else {
            html += '<option value="C">Coding (C)</option>';
            html += '<option value="L">Logical (L)</option>';
            html += '<option value="Q">Quantz (Q)</option>';
            html += '<option value="V">Verbal (V)</option>';
            html += '<option value="O">Other (O)</option>';
            html += '<option value="SL">Sports/Library (S|L)</option>';
            $("#inSubName").hide(); $("#inSpecialSelect").show(); $("#inSubCode").val('NA').prop('disabled', true);
        }
        $("#inType").html(html);
    }

    function toggleSubjectInput() {}

    function addPair() {
        let sec = $("#currentSectionSelect").val();
        if(!sectionData[sec]) sectionData[sec] = {};
        let type = $("#inType").val();
        let cat = $("#inCategory").val();
        let fid = $("#inFacID").val(); let fname = $("#inFacName").val();

        let sub, code;
        if(cat === 'SPECIAL') {
            sub = $("#inSpecialSelect").val();
            code = sub.substr(0,4)+"_"+type;
        } else {
            sub = $("#inSubName").val().trim().toUpperCase();
            code = $("#inSubCode").val().trim().toUpperCase();
            if(!code) { alert("Select Subject!"); return; }
        }

        if(!fid) { alert("Select Faculty!"); return; }
        let grp = 'ENTIRE';

        let isNewSubject = true;
        activeSections.forEach(s => { if(sectionData[s] && sectionData[s][code]) isNewSubject = false; });

        if (isNewSubject) {
            activeSections.forEach(s => {
                if(!sectionData[s]) sectionData[s] = {};
                if(!subjectCache[code]) subjectCache[code] = { name:sub, type:type, cat:cat, L:$("#inL").val(), T:$("#inT").val(), P:$("#inP").val(), credits:$("#inC").val() };
                sectionData[s][code] = { subName:sub, facId:fid, facName:fname, type:type, cat:cat, group:grp };
            });
            showToast("Added to ALL Sections!");
        } else {
            if(!subjectCache[code]) subjectCache[code] = { name:sub, type:type, cat:cat, L:$("#inL").val(), T:$("#inT").val(), P:$("#inP").val(), credits:$("#inC").val() };
            sectionData[sec][code] = { subName:sub, facId:fid, facName:fname, type:type, cat:cat, group:grp };
            showToast("Updated Section " + sec);
        }

        renderAllocationList();
        if(cat === 'ACADEMIC'){ $("#inSubName").val(''); $("#inSubCode").val(''); $("#inSubName").removeClass('is-valid'); }
        $("#inFacSearch").val(''); $("#inFacID").val(''); $("#inFacName").val(''); $("#inFacSearch").removeClass('is-valid');
    }

    function renderAllocationList() {
        let allCodes = [];
        activeSections.forEach(s => {
            if(sectionData[s]) { Object.keys(sectionData[s]).forEach(c => { if(!allCodes.includes(c)) allCodes.push(c); }); }
        });

        let h = '';
        if(allCodes.length === 0) { h = '<div style="grid-column:1/-1; text-align:center; padding:20px; color:#94a3b8;">No subjects added.</div>'; }
        else {
            allCodes.forEach(k => {
                let grad = getSubjectGradient(k);
                let meta = subjectCache[k] || {};
                let subName = meta.name || k;

                let typeBadge = `<span class="badge" style="background:rgba(0,0,0,0.2); color:white; margin-left:5px;">${meta.type || ''}</span>`;

                h += `<div class="alloc-card" style="background:${grad}; color:white; border:none; display:block;">
                        <div class="alloc-header"><div class="alloc-title"><h4>${k} ${typeBadge}</h4><p>${subName}</p></div><button class="btn-icon del" onclick="removePair('${k}', 'ALL')" title="Delete All"><i class="fas fa-trash"></i></button></div>
                        <table class="matrix-table">`;

                activeSections.forEach(sec => {
                    let assigned = (sectionData[sec] && sectionData[sec][k]);
                    let facName = assigned ? assigned.facName : '<span style="opacity:0.6; font-style:italic;">Not Assigned</span>';

                    h += `<tr><td class="matrix-sec">Sec ${sec}</td><td class="matrix-fac">${facName}</td><td class="matrix-actions">`;
                    if(assigned) {
                        h += `<button class="btn-icon" title="Edit" onclick="editPair('${k}', '${sec}')"><i class="fas fa-pen"></i></button><button class="btn-icon del" title="Remove" onclick="removePair('${k}', '${sec}')"><i class="fas fa-times"></i></button>`;
                    } else {
                        h += `<button class="btn-icon add" title="Assign" onclick="editPair('${k}', '${sec}')"><i class="fas fa-plus"></i></button>`;
                    }
                    h += `</td></tr>`;
                });
                h += `</table></div>`;
            });
        }
        $("#allocationList").html(h); refreshGridDropdowns();
        generateSummary();
    }

    function refreshGridDropdowns() {
        activeSections.forEach(sec => {
            let list = sectionData[sec] || {};
            let options = '<option value="">- Select -</option>';
            $.each(list, (k, v) => {
                let c = getGridCellColor(k);
                options += `<option value="${k}" style="background-color: ${c}">${k} (${v.type}) - ${v.subName}</option>`;
            });
            $(`select[id^='sel_cell_${sec}_']`).each(function() {
                let currentVal = $(this).val(); $(this).html(options);
                if(currentVal && list[currentVal]) $(this).val(currentVal);
            });
        });
    }

    function editPair(code, sec) {
        $("#currentSectionSelect").val(sec).trigger('change');
        if(sectionData[sec] && sectionData[sec][code]) {
            let d = sectionData[sec][code];
            $("#inFacSearch").val(d.facName); $("#inFacID").val(d.facId); $("#inFacName").val(d.facName);
        } else {
            $("#inFacSearch").val(''); $("#inFacID").val(''); $("#inFacName").val('');
        }
        if(subjectCache[code]) {
             let s = subjectCache[code];
             let cat = (['C','L','Q','V','O','SL'].includes(s.type)) ? 'SPECIAL' : 'ACADEMIC';
             $("#inCategory").val(cat).trigger('change');
             setTimeout(() => $("#inType").val(s.type), 100);

             if(cat === 'SPECIAL') $("#inSpecialSelect").val(s.name);
             else { $("#inSubName").val(s.name); $("#inSubCode").val(code); }
             $("#inL").val(s.L); $("#inT").val(s.T); $("#inP").val(s.P); $("#inC").val(s.credits);
        }
    }

    function removePair(code, sec) {
        if(!confirm('Remove subject?')) return;
        if(sec === 'ALL') { activeSections.forEach(s => { if(sectionData[s] && sectionData[s][code]) delete sectionData[s][code]; }); }
        else { if(sectionData[sec] && sectionData[sec][code]) delete sectionData[sec][code]; }
        renderAllocationList();
    }

    function generateAllGrids() {
        if(activeSections.length === 0) calcSections();
        showLoader();
        setTimeout(() => {
            $('#sectionTabs').empty(); $('#gridContainer').empty();
            activeSections.forEach((sec, i) => {
                let ops = '<option value="">- Select -</option>';
                if(sectionData[sec]) $.each(sectionData[sec], (k,v) => { let c = getGridCellColor(k); ops += `<option value="${k}" style="background-color:${c}">${k} (${v.type})</option>`; });

                $('#sectionTabs').append(`<button class="sec-tab-btn ${i===0?'active':''}" onclick="switchSecTab('${sec}')">Section ${sec}</button>`);

                let t = `<div class="card tt-wrapper grid-view" id="grid_${sec}" style="display:${i===0?'block':'none'}"><table class="tt-table"><thead><tr><th>DAY</th>`;
                for(let j=1; j<=8; j++) t+=`<th>HR ${j}</th>`;
                t+=`</tr></thead><tbody>`;
                for(let d=1; d<=5; d++) {
                    t+=`<tr><td class="day-col">DAY ${d}</td>`;
                    for(let h=1; h<=8; h++) {
                        let uid = `cell_${sec}_${d}_${h}`;
                        t+=`<td><div class="cell-content" id="content_${uid}"><div class="empty-state" style="opacity:0.3"><i class="fas fa-plus"></i></div></div><select class="cell-select" id="sel_${uid}" onchange="updateCell(this,'${uid}','${sec}')">${ops}</select></td>`;
                    }
                    t+=`</tr>`;
                }
                t+=`</tbody></table></div>`;
                $('#gridContainer').append(t);
            });
            $('#gridArea').fadeIn(); generateSummary();
            hideLoader();
        }, 300);
    }

    function switchSecTab(sec) { $('.sec-tab-btn').removeClass('active'); $(event.target).addClass('active'); $('.grid-view').hide(); $(`#grid_${sec}`).show(); }

    function updateCell(el, uid, sec) {
        let code = $(el).val(); let contentDiv = $(`#content_${uid}`);
        if(code) {
            let d = sectionData[sec][code];
            contentDiv.html(`<div class="cell-code">${code} <span style="font-size:0.7em;">(${d.type})</span></div><div class="cell-sub" title="${d.subName}">${d.subName}</div><div class="cell-fac">${d.facName}</div>`);
            $(el).parent().css('background', getGridCellColor(code));
        } else {
            contentDiv.html(`<div class="empty-state" style="opacity:0.3"><i class="fas fa-plus"></i></div>`);
            $(el).parent().css('background', 'white');
        }
        generateSummary();
    }

    function generateSummary() {
        let container = $('#summaryContainer');
        container.empty();

        let tabsHtml = '<div class="section-tabs" style="justify-content:center; margin-bottom:15px;">';
        activeSections.forEach((sec, idx) => {
            let active = (idx === 0) ? 'active' : '';
            tabsHtml += `<button class="sec-tab-btn ${active}" onclick="switchSummaryTab('${sec}')">Section ${sec}</button>`;
        });
        tabsHtml += '</div>';

        let contentHtml = '';
        activeSections.forEach((sec, idx) => {
            let display = (idx === 0) ? 'block' : 'none';
            contentHtml += `<div id="sum_tab_${sec}" class="summary-tab-content" style="display:${display}; animation:fadeInUp 0.3s;">
                <table class="list-table">
                    <thead style="background:#f8fafc;">
                        <tr><th style="padding:12px;">Code</th><th>Type</th><th>Subject</th><th>Credits</th><th>Faculty</th><th style="text-align:center;">Assigned</th></tr>
                    </thead>
                    <tbody>`;

            let secCounts = {};
            $(`#grid_${sec} .cell-select`).each(function() { let val = $(this).val(); if(val) secCounts[val] = (secCounts[val] || 0) + 1; });

            let list = sectionData[sec] || {};
            if(Object.keys(list).length === 0) {
                 contentHtml += '<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No subjects allocated.</td></tr>';
            } else {
                $.each(list, (code, data) => {
                     let meta = subjectCache[code] || {L:0,T:0,P:0,credits:0};
                     let count = secCounts[code] || 0;
                     contentHtml += `<tr>
                        <td style="font-weight:700;">${code}</td>
                        <td><span class="badge" style="background:#e0f2fe; color:#0369a1;">${data.type}</span></td>
                        <td>${data.subName}</td>
                        <td>${meta.L}-${meta.T}-${meta.P}-${meta.credits}</td>
                        <td style="color:#bc1888; font-weight:600;">${data.facName}</td>
                        <td style="text-align:center;"><span class="badge" style="background:#f1f5f9; color:#1e293b; font-size:0.9rem;">${count}</span></td>
                     </tr>`;
                });
            }
            contentHtml += `</tbody></table></div>`;
        });
        container.html(tabsHtml + contentHtml);
    }

    function switchSummaryTab(sec) {
        $('#summaryContainer .sec-tab-btn').removeClass('active');
        $(event.target).addClass('active');
        $('.summary-tab-content').hide();
        $(`#sum_tab_${sec}`).show();
    }

    function updateSubMeta(code, field, val) { if(subjectCache[code]) subjectCache[code][field] = parseInt(val, 10) || 0; }
    function deleteSubjectGlobal(code) { if(!confirm('Delete?')) return; delete subjectCache[code]; activeSections.forEach(s => { if(sectionData[s][code]) delete sectionData[s][code]; }); generateAllGrids(); }

    function validateConfiguration() {
        for (let i = 0; i < activeSections.length; i++) {
            let sec = activeSections[i];
            if (!sectionAdvisors[sec] || !sectionAdvisors[sec].id) {
                alert(`Error: Please select a Class Advisor for Section ${sec} in the Configuration table.`);
                return false;
            }
        }
        return true;
    }

    function saveData(status) {
        if (!validateConfiguration()) return;
        showLoader();
        let mainAdvisor = sectionAdvisors['A'] || {name:'', id:''};
        let payload = {
            batch: $("#confBatch").val(), sem: $("#confSem").val(), intake: $("#confIntake").val(),
            program: $("#confProg").val(), department: $("#confDept").val(),
            class_advisor: mainAdvisor.name, class_advisor_id: mainAdvisor.id,
            advisors_json: JSON.stringify(sectionAdvisors),
            status: status, sections: [], subjects_meta: subjectCache
        };
        activeSections.forEach(sec => {
            let g = {};
            for(let d=1; d<=5; d++) {
                g[d] = {};
                for(let h=1; h<=8; h++) {
                    let uid = `cell_${sec}_${d}_${h}`;
                    let c = $(`#sel_${uid}`).val();
                    if(c) {
                        let i = sectionData[sec][c];
                        if(i) g[d][h] = { sub:c, sub_name:i.subName, fac_id:i.facId, fac_name:i.facName, type:i.type, l:subjectCache[c].L, t:subjectCache[c].T, p:subjectCache[c].P, c:subjectCache[c].credits, group:i.group };
                    }
                }
            }
            payload.sections.push({ sec_name:sec, grid:g });
        });
        $.ajax({
            url: 'save_timetable.php',
            type: 'POST',
            data: withCsrf({ json_data: JSON.stringify(payload), edit_id: EDIT_ID }),
            dataType: 'json',
            success: function(res) {
                hideLoader();
                if(res.status === 'success') { showToast("Saved!"); setTimeout(() => window.location.href=`${SELF_PAGE}?tab=institutional`, 1000); }
                else { alert("Error: " + res.message); }
            },
            error: function(e) { hideLoader(); console.error(e); showToast("Save failed. Check console.", "error"); }
        });
    }

    function deleteTimetable(id) {
        if(confirm("Delete?")) {
            $.post(SELF_PAGE, withCsrf({delete_id:id}), function(r){ if(r.status==='success') location.reload(); }, 'json');
        }
    }
    function filterTable() { let v = $('#deptFilter').val().toLowerCase(); $('.dept-row').toggle(function(){ return $(this).text().toLowerCase().indexOf(v) > -1; }); }
    function checkFirstYear() { let p = $("#confProg").val(); let s = parseInt($("#confSem").val(), 10); $("#confDept").val(p); }
</script>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
