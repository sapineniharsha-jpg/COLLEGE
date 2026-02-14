<?php
// =========================================================
// 1. BACKEND LOGIC & CONFIG
// =========================================================
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$security_path = __DIR__ . '/platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}

// Auth Check
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit(); }

$user_id   = $_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? '';
$role      = strtolower($_SESSION['role'] ?? 'student');
$user_dept = $_SESSION['DEPARTMENT'] ?? '';

// Student access not required here
if ($role === 'student') {
    header('Location: /login.php');
    exit();
}

// HOD Fix
if ($role === 'hod' && empty($user_dept)) {
    $dept_q = $mysqli->query("SELECT DEPARTMENT FROM employee_details1 WHERE ID_NO = '$user_id'");
    if ($dept_q && $row = $dept_q->fetch_assoc()) {
        $user_dept = $row['DEPARTMENT'];
        $_SESSION['DEPARTMENT'] = $user_dept;
    }
}

function is_science_humanities_dept($department) {
    $dep = strtolower(preg_replace('/[^a-z]/', '', (string) $department));
    return strpos($dep, 'science') !== false && strpos($dep, 'humanities') !== false;
}

$my_type = ($role === 'student') ? 'student' : 'employee';
$is_admin_hod = in_array($role, ['admin', 'principal', 'dean', 'hod'], true);
$can_view_directory = $is_admin_hod;
$is_sh_hod = ($role === 'hod' && is_science_humanities_dept($user_dept));
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

// ---------------------------------------------------------
// AJAX HANDLERS
// ---------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    try {
        // 1. SEARCH FACULTY
        if ($action == 'search_faculty') {
            $term = $mysqli->real_escape_string($_GET['term'] ?? '');
            $filter_dept = $mysqli->real_escape_string($_GET['dept'] ?? 'all');

            $sql = "SELECT ID_NO, NAME, DEPARTMENT, DESIGNATION,
                    (SELECT COUNT(*) FROM mentor_mentee mm WHERE mm.Employee_ID_No = employee_details1.ID_NO) as mentee_count
                    FROM employee_details1 WHERE (NAME LIKE '%$term%' OR ID_NO LIKE '%$term%')";

            if ($role == 'faculty' || $role == 'hod') {
                $sql .= " AND DEPARTMENT = '$user_dept'";
            } elseif ($filter_dept != 'all') {
                $sql .= " AND DEPARTMENT = '$filter_dept'";
            }

            $sql .= " ORDER BY NAME ASC LIMIT 50";
            $res = $mysqli->query($sql);
            echo json_encode($res->fetch_all(MYSQLI_ASSOC));
            exit;
        }

        // 2. GET MENTEES
        if ($action == 'get_mentees') {
            $fid = $mysqli->real_escape_string($_GET['fid']);
            $sql = "SELECT m.id as map_id, s.IDNo as sid, s.Name as name, s.Batch as batch, s.Dept as dept, 'Senior' as type
                    FROM mentor_mentee m JOIN students_login_master s ON m.Student_ID_No = s.IDNo WHERE m.Employee_ID_No = '$fid'
                    UNION ALL
                    SELECT m.id as map_id, s.id_no as sid, s.student_name as name, s.batch as batch, s.department as dept, 'Fresher' as type
                    FROM mentor_mentee m JOIN students_batch_25_26 s ON (m.Student_ID_No = s.id_no OR m.Student_ID_No = s.register_no) WHERE m.Employee_ID_No = '$fid'";
            $res = $mysqli->query($sql);
            echo json_encode(['data' => $res->fetch_all(MYSQLI_ASSOC)]);
            exit;
        }

        // 3. AUTO-SUGGEST UNMAPPED
        if ($action == 'get_dept_suggestions') {
            $target_dept = $mysqli->real_escape_string($_GET['dept'] ?? '');
            if ($role == 'hod') $target_dept = $user_dept;

            // Special handling for Science and Humanities
            $dept_filter_s = ($target_dept && $target_dept != 'all') ? "AND s.Dept = '$target_dept'" : "";
            $dept_filter_f = ($target_dept && $target_dept != 'all') ? "AND s.department = '$target_dept'" : "";
            if ($is_sh_hod) {
                $dept_filter_f = "";
            }

            $results = [];

            if ($is_sh_hod) {
                $sql2 = "SELECT s.id_no as id, s.student_name as name, s.batch as batch, s.department as dept FROM students_batch_25_26 s
                         LEFT JOIN mentor_mentee m ON s.id_no = m.Student_ID_No WHERE m.Student_ID_No IS NULL LIMIT 50";
                $r2 = $mysqli->query($sql2);
                if ($r2) {
                    while($row = $r2->fetch_assoc()) { $row['status']='free'; $results[] = $row; }
                }
            } else {
                $sql1 = "SELECT s.IDNo as id, s.Name as name, s.Batch as batch, s.Dept as dept FROM students_login_master s
                         LEFT JOIN mentor_mentee m ON s.IDNo = m.Student_ID_No WHERE m.Student_ID_No IS NULL $dept_filter_s LIMIT 50";
                $sql2 = "SELECT s.id_no as id, s.student_name as name, s.batch as batch, s.department as dept FROM students_batch_25_26 s
                         LEFT JOIN mentor_mentee m ON s.id_no = m.Student_ID_No WHERE m.Student_ID_No IS NULL $dept_filter_f LIMIT 50";

                $r1 = $mysqli->query($sql1); if($r1) while($row = $r1->fetch_assoc()) { $row['status']='free'; $results[] = $row; }
                $r2 = $mysqli->query($sql2); if($r2) while($row = $r2->fetch_assoc()) { $row['status']='free'; $results[] = $row; }
            }

            echo json_encode(['data' => $results]);
            exit;
        }

        // 4. SEARCH STUDENTS
        if ($action == 'search_students') {
            $term = $mysqli->real_escape_string($_GET['term']);
            $results = [];

            if ($is_sh_hod) {
                $q2 = "SELECT id_no as id, student_name as name, batch as batch, department as dept FROM students_batch_25_26 WHERE id_no LIKE '%$term%' OR student_name LIKE '%$term%' LIMIT 5";
                $r2 = $mysqli->query($q2); while($row = $r2->fetch_assoc()) $results[] = $row;
            } else {
                $q1 = "SELECT IDNo as id, Name as name, Batch as batch, Dept as dept FROM students_login_master WHERE IDNo LIKE '%$term%' OR Name LIKE '%$term%' LIMIT 5";
                $r1 = $mysqli->query($q1); while($row = $r1->fetch_assoc()) $results[] = $row;

                $q2 = "SELECT id_no as id, student_name as name, batch as batch, department as dept FROM students_batch_25_26 WHERE id_no LIKE '%$term%' OR student_name LIKE '%$term%' LIMIT 5";
                $r2 = $mysqli->query($q2); while($row = $r2->fetch_assoc()) $results[] = $row;
            }

            foreach ($results as &$stu) {
                $sid = $stu['id'];
                $chk = $mysqli->query("SELECT m.Employee_ID_No, e.NAME as mentor_name FROM mentor_mentee m JOIN employee_details1 e ON m.Employee_ID_No = e.ID_NO WHERE m.Student_ID_No = '$sid' LIMIT 1");
                if ($chk && $chk->num_rows > 0) {
                    $m = $chk->fetch_assoc();
                    $stu['status'] = 'assigned';
                    $stu['mentor_name'] = $m['mentor_name'];
                    $stu['mentor_id'] = $m['Employee_ID_No'];
                } else {
                    $stu['status'] = 'free';
                }
            }
            echo json_encode($results);
            exit;
        }

        // 5. BULK OPERATIONS
        if ($action == 'assign_bulk') {
            if (!$is_admin_hod) exit;
            if (function_exists('vh_require_csrf_or_exit')) {
                vh_require_csrf_or_exit(true);
            }
            $fid = $mysqli->real_escape_string($_POST['fid']);
            $sids = explode(',', $_POST['sids']);
            foreach ($sids as $sid) {
                $sid = trim($sid); if(empty($sid)) continue;
                $dept=''; $batch='';
                $q = $mysqli->query("SELECT Dept, Batch FROM students_login_master WHERE IDNo='$sid'");
                if($q->num_rows){ $d=$q->fetch_assoc(); $dept=$d['Dept']; $batch=$d['Batch']; }
                else { $q = $mysqli->query("SELECT department, batch FROM students_batch_25_26 WHERE id_no='$sid'"); if($q->num_rows){ $d=$q->fetch_assoc(); $dept=$d['department']; $batch=$d['batch']; }}

                $chk = $mysqli->query("SELECT id FROM mentor_mentee WHERE Student_ID_No='$sid'");
                if($chk->num_rows > 0) $mysqli->query("UPDATE mentor_mentee SET Employee_ID_No='$fid' WHERE Student_ID_No='$sid'");
                else {
                    $stmt = $mysqli->prepare("INSERT INTO mentor_mentee (Student_ID_No, Employee_ID_No, department, batch) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $sid, $fid, $dept, $batch);
                    $stmt->execute();
                }
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action == 'remove_bulk') {
            if (!$is_admin_hod) exit;
            if (function_exists('vh_require_csrf_or_exit')) {
                vh_require_csrf_or_exit(true);
            }
            $sids = explode(',', $_POST['sids']);
            $sids_str = "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $sids)) . "'";
            $mysqli->query("DELETE FROM mentor_mentee WHERE Student_ID_No IN ($sids_str)");
            echo json_encode(['success' => true]); exit;
        }

    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
}

// ---------------------------------------------------------
// VIEW PREP & HELPERS
// ---------------------------------------------------------
$my_mentees = [];
$my_mentor_info = null;
$dept_list = [];

// Helper: Smart Year Calculation
function getSmartYear($batch) {
    if(empty($batch)) return '';
    $parts = explode('-', $batch);
    $start_year = intval($parts[0]);
    if($start_year == 0) return $batch;

    $current_year = date('Y');
    $current_month = date('n');
    $year_diff = $current_year - $start_year;
    if ($current_month >= 6) { $year_diff += 1; }
    if($year_diff < 1) $year_diff = 1;

    switch($year_diff) {
        case 1: return '<span class="badge-soft bg-g">I Year</span>';
        case 2: return '<span class="badge-soft bg-b">II Year</span>';
        case 3: return '<span class="badge-soft bg-o">III Year</span>';
        case 4: return '<span class="badge-soft bg-p">IV Year</span>';
        default: return '<span class="badge-soft bg-gray">Passout</span>';
    }
}

if ($role != 'student') {
    $q = "SELECT s.IDNo as student_id, s.Name as student_name, s.Batch as batch, s.RegisterNo as register_no, s.Dept as dept, 'Senior' as type
          FROM mentor_mentee m JOIN students_login_master s ON m.Student_ID_No = s.IDNo WHERE m.Employee_ID_No='$user_id'
          UNION ALL
          SELECT s.id_no as student_id, s.student_name as student_name, s.batch as batch, s.register_no as register_no, s.department as dept, 'Fresher' as type
          FROM mentor_mentee m JOIN students_batch_25_26 s ON m.Student_ID_No = s.id_no WHERE m.Employee_ID_No='$user_id'";
    $r = $mysqli->query($q);
    if($r) $my_mentees = $r->fetch_all(MYSQLI_ASSOC);
}

if ($role == 'admin' || $role == 'principal') {
    $dres = $mysqli->query("SELECT DISTINCT Dept FROM students_login_master ORDER BY Dept");
    while($r = $dres->fetch_assoc()) $dept_list[] = $r['Dept'];
    // Manually add S&H if not present
    if(!in_array('Science and Humanities', $dept_list)) {
        $dept_list[] = 'Science and Humanities';
    }
    sort($dept_list);
}

include '../includes/header.php';
?>

<style>
    :root {
        --ig-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
        --ig-text: #bc1888;
        --bg-soft: #fafafa;
        --border-light: #f1f5f9;
        --shadow-soft: 0 4px 20px rgba(0,0,0,0.03);
    }

    .pwa-container { max-width: 1400px; margin: 0 auto; padding: 25px; min-height: 85vh; font-family: 'Outfit', sans-serif; }

    /* NAV TABS */
    .nav-tabs { border-bottom: 2px solid #eee; margin-bottom: 25px; gap: 15px; border:none; display:flex; list-style:none; padding:0; flex-wrap: wrap; }
    .nav-link {
        color: #64748b; font-weight: 700; border: 1px solid #eee; padding: 12px 25px;
        background: white; transition: all 0.3s ease; border-radius: 50px !important; cursor: pointer;
        display: flex; align-items: center; gap: 8px; font-size: 0.95rem;
    }
    .nav-link i { font-size: 1.1rem; }
    .nav-link.active {
        background: var(--ig-grad) !important; color: white !important; border-color: transparent;
        box-shadow: 0 5px 15px rgba(188, 24, 136, 0.25); transform: translateY(-2px);
    }
    .nav-link:hover:not(.active) { background: #f8fafc; color: var(--ig-text); border-color: #ddd; }

    /* CONTAINERS & LISTS */
    .saas-container {
        background: white; border-radius: 20px; border: 1px solid var(--border-light);
        box-shadow: var(--shadow-soft); overflow: hidden; display: flex; flex-direction: column;
        height: 75vh; transition: all 0.3s ease;
    }

    .saas-container.auto-height { height: auto; min-height: 500px; }

    .list-header {
        padding: 18px 25px; background: #fff; border-bottom: 1px solid #eee;
        display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;
    }
    .list-body { flex: 1; overflow-y: auto; background: #fff; scroll-behavior: smooth; }

    /* MODERN LIST ITEM */
    .list-item {
        display: flex; align-items: center; padding: 15px 25px; border-bottom: 1px solid #f8fafc;
        transition: all 0.2s ease; cursor: pointer; gap: 15px; position: relative;
    }
    .list-item:hover { background: #fff5f8; transform: translateX(5px); }
    .list-item.active { background: #fce7f3; border-left: 4px solid var(--ig-text); }

    .table-row {
        display: grid; grid-template-columns: 50px 2fr 1fr; align-items: center;
        padding: 16px 24px; border-bottom: 1px solid #f8fafc; transition: background 0.2s; gap: 15px;
    }
    .table-row:hover { background: #fdfdfd; }

    .mentee-card {
        display: grid; grid-template-columns: 60px 1fr auto; gap: 16px;
        padding: 16px 20px; border-bottom: 1px solid #f1f5f9; align-items: center;
        transition: background 0.2s;
    }
    .mentee-card:hover { background: #fff5f8; }
    .mentee-avatar {
        width: 54px; height: 54px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; color: #bc1888; background: #fce7f3;
        font-size: 1.1rem;
    }
    .mentee-name { font-weight: 800; color: #0f172a; }
    .mentee-meta { color: #64748b; font-size: 0.82rem; margin-top: 2px; }
    .mentee-tags { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .badge-chip {
        background: #f8fafc; color: #334155; border: 1px solid #e2e8f0;
        padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700;
    }

    .alloc-row {
        display: flex; align-items: center; padding: 12px 20px; border-bottom: 1px solid #f8fafc;
        gap: 12px; cursor: pointer; transition: all 0.2s;
    }
    .alloc-row:hover { background: #fff5f8; }
    .alloc-row.active { background: #fce7f3; border-right: 4px solid var(--ig-text); }

    .av-circle {
        width: 45px; height: 45px; border-radius: 50%; background: #f1f5f9;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; color: #64748b; font-size: 1.2rem; flex-shrink: 0;
        background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%);
        border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    /* SPLIT VIEW */
    .split-view { display: grid; grid-template-columns: 350px 1fr; gap: 25px; height: 100%; }
    .list-container {
        background: #fff;
        border: 1px solid var(--border-light);
        border-radius: 20px;
        box-shadow: var(--shadow-soft);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        height: 75vh;
    }
    #allocActions { display: none; align-items: center; gap: 10px; flex-wrap: wrap; }

    /* BUTTONS */
    .btn-insta {
        background: var(--ig-grad); color: white; border: none;
        padding: 10px 24px; border-radius: 50px; font-weight: 700; font-size: 0.9rem;
        transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
        box-shadow: 0 4px 10px rgba(188, 24, 136, 0.2);
    }
    .btn-insta:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(188, 24, 136, 0.4); color: white; }
    .btn-insta.btn-sm { padding: 6px 14px; font-size: 0.78rem; }

    .btn-outline {
        background: white; border: 2px solid #f1f5f9; color: #64748b;
        padding: 8px 20px; border-radius: 50px; font-weight: 700; font-size: 0.85rem; transition: 0.3s; cursor: pointer;
    }
    .btn-outline:hover { border-color: var(--ig-text); color: var(--ig-text); background: #fff0f6; }

    .search-inp {
        width: 100%; padding: 12px 20px; border-radius: 50px; border: 2px solid #f1f5f9;
        background: #f8fafc; outline: none; transition: 0.3s; font-size: 0.95rem;
    }
    .search-inp:focus { background: white; border-color: var(--ig-text); box-shadow: 0 0 0 4px rgba(188, 24, 136, 0.1); }

    .filter-select {
        border-radius: 50px; border: 2px solid #f1f5f9; padding: 10px 35px 10px 20px;
        font-size: 0.9rem; background-color: #fff; cursor: pointer; outline: none; transition: 0.3s;
    }
    .filter-select:focus { border-color: var(--ig-text); }

    /* BADGES */
    .badge-soft { padding: 5px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block;}
    .bg-g { background: #dcfce7; color: #166534; }
    .bg-b { background: #dbeafe; color: #1e40af; }
    .bg-o { background: #ffedd5; color: #9a3412; }
    .bg-p { background: #f3e8ff; color: #6b21a8; }
    .bg-r { background: #fee2e2; color: #991b1b; }

    /* TOAST */
    .toast-custom {
        position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
        background: #1e293b; color: white; padding: 12px 30px; border-radius: 50px;
        font-weight: 600; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        opacity: 0; transition: 0.3s; pointer-events: none; z-index: 9999;
    }
    .toast-custom.show { opacity: 1; bottom: 50px; }

    /* ADD MENTEES MODAL */
    .add-modal .modal-content { border-radius: 18px; }
    .add-modal .modal-body { overflow: hidden; display: flex; flex-direction: column; }
    .add-toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .add-toolbar .search-inp { flex: 1; min-width: 220px; }
    .add-info {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 10px 12px; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;
    }
    .add-select-bar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0;
        border-bottom: none; border-radius: 12px 12px 0 0; gap: 10px;
    }
    .add-results {
        border: 1px solid #e2e8f0; border-radius: 0 0 12px 12px;
        max-height: 360px; overflow: auto; background: #fff; flex: 1;
    }
    .add-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f1f5f9; }
    .add-row:hover { background: #fff5f8; }
    .add-row .meta { font-size: 0.8rem; color: #64748b; }
    .add-footer { position: sticky; bottom: -1px; background: #fff; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 12px; }
    .add-count { background: #fdf2f8; color: #bc1888; border-radius: 999px; padding: 4px 10px; font-weight: 700; font-size: 0.8rem; }

    @media(max-width: 992px) {
        .split-view { grid-template-columns: 1fr; height: auto; display: flex; flex-direction: column; }
        .saas-container { height: 600px; margin-bottom: 20px; }
        .table-row { grid-template-columns: 50px 1fr; gap: 10px; position: relative; }
        .table-row .actions-col { position: absolute; top: 15px; right: 15px; }
    }
</style>

<div id="toast" class="toast-custom">Action Successful</div>

<main class="pwa-container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1 fw-bold" style="background: var(--ig-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Mentor Hub</h2>
            <p class="text-muted mb-0 small"><?= ($role=='student') ? 'My Assigned Mentor' : 'Manage & Connect' ?></p>
        </div>
        <?php if($is_admin_hod): ?>
            <span class="badge-soft bg-g border"><?= strtoupper($role) ?> MODE</span>
        <?php endif; ?>
    </div>

    <ul class="nav nav-tabs">
        <li class="nav-item"><button class="nav-link" id="btn-my_group" onclick="switchTab('my_group')"><i class="fas fa-users"></i> My Mentees</button></li>

        <?php if($can_view_directory): ?>
        <li class="nav-item"><button class="nav-link" id="btn-directory" onclick="switchTab('directory')"><i class="fas fa-address-book"></i> Faculty Directory</button></li>
        <?php endif; ?>

        <?php if($is_admin_hod): ?>
        <li class="nav-item"><button class="nav-link" id="btn-manage" onclick="switchTab('manage')"><i class="fas fa-tasks"></i> Manage Allocations</button></li>
        <?php endif; ?>
    </ul>

    <div id="tab-my_group" class="tab-pane">
            <div class="saas-container auto-height">
                <div class="list-header">
                    <span class="h6 mb-0 fw-bold">My Mentees</span>
                    <span class="badge bg-light text-dark border"><?= count($my_mentees) ?> Assigned</span>
                </div>
                <div class="list-body">
                    <?php if(empty($my_mentees)): ?>
                        <div class="text-center p-5 text-muted">No mentees assigned yet.</div>
                    <?php else: ?>
                        <?php foreach($my_mentees as $s): ?>
                        <?php
                            $student_name = $s['student_name'] ?? 'Student';
                            $student_id = $s['student_id'] ?? '';
                            $register_no = $s['register_no'] ?? '';
                            $dept = $s['dept'] ?? '';
                            $batch = $s['batch'] ?? '';
                            $type = $s['type'] ?? '';
                        ?>
                        <div class="mentee-card">
                            <div class="mentee-avatar"><?= strtoupper(substr($student_name, 0, 1)) ?></div>
                            <div>
                                <div class="mentee-name"><?= htmlspecialchars($student_name) ?></div>
                                <div class="mentee-meta">
                                    <?= htmlspecialchars($student_id) ?>
                                    <?= $register_no ? ' • Reg: ' . htmlspecialchars($register_no) : '' ?>
                                    <?= $dept ? ' • ' . htmlspecialchars($dept) : '' ?>
                                </div>
                                <div class="mentee-tags">
                                    <?php if($batch): ?>
                                        <span class="badge-chip">Batch <?= htmlspecialchars($batch) ?></span>
                                        <?= getSmartYear($batch) ?>
                                    <?php endif; ?>
                                    <?php if($type): ?>
                                        <span class="badge-chip"><?= htmlspecialchars($type) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="actions-col text-end">
                                <a href="profile.php?id=<?= htmlspecialchars($student_id) ?>" class="btn-insta btn-sm">Profile</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
    </div>

    <?php if($can_view_directory): ?>
    <div id="tab-directory" class="tab-pane" style="display:none;">
        <div class="saas-container">
            <div class="list-header gap-3 flex-wrap">
                <input type="text" id="dirSearch" class="search-inp" style="flex:1; min-width:200px;" placeholder="Search Faculty Name..." onkeyup="loadDirectory()">
                <?php if($role == 'admin'): ?>
                <select id="dirDeptFilter" class="filter-select" onchange="loadDirectory()">
                    <option value="all">All Depts</option>
                    <?php foreach($dept_list as $d) echo "<option value='$d'>$d</option>"; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="list-body" id="dirResults">
                </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($is_admin_hod): ?>
    <div id="tab-manage" class="tab-pane" style="display:none;">
        <div class="saas-container" style="background:transparent; border:none; box-shadow:none; overflow:visible;">
            <div class="split-view">

                <div class="list-container h-100">
                    <div class="list-header">
                        <span>Faculty List</span>
                    </div>
                    <div class="p-3 border-bottom bg-white">
                        <input type="text" id="allocSearch" class="search-inp w-100" placeholder="Search Faculty..." onkeyup="searchFacultyAlloc()">
                    </div>
                    <div class="list-body" id="allocList">
                        <div class="text-center p-4 text-muted"><div class="spinner-border text-primary"></div></div>
                    </div>
                </div>

                <div class="list-container h-100">
                    <div class="list-header">
                        <div class="d-flex align-items-center gap-3">
                            <div class="av-circle" id="selFacAv" style="width:36px; height:36px; font-size:0.9rem; display:none;">A</div>
                            <span id="allocFacName" class="text-truncate fw-bold">Select a Faculty</span>
                        </div>
                        <div id="allocActions" style="display:none; gap:10px;">
                            <button class="btn-outline text-danger border-danger btn-sm" onclick="removeBulk()">Remove</button>
                            <button class="btn-insta btn-sm" onclick="openAddModal()">+ Add Students</button>
                        </div>
                    </div>
                    <div class="list-body" id="allocMentees">
                        <div class="text-center p-5 text-muted opacity-50 mt-5">
                            <i class="fas fa-hand-pointer fa-3x mb-3"></i><br>Select a faculty from the left list.
                        </div>
                    </div>
                    <div class="p-3 border-top bg-light small fw-bold text-muted text-end">
                        <span id="allocCount">0 Mentees</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<div class="modal fade" id="addModal" tabindex="-1" data-bs-scroll="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow-lg add-modal">
      <div class="modal-header border-0 pb-0 pt-4 px-4">
        <h5 class="modal-title fw-bold">Add Mentees</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <div class="add-toolbar mb-3">
             <input type="text" id="stuSearch" class="search-inp" placeholder="Search specific student ID/Name..." onkeyup="searchStudents()">
             <?php if($role == 'admin'): ?>
             <select id="addModalDept" class="filter-select" onchange="autoLoadSuggestions()">
                 <option value="">Auto (Same Dept)</option>
                 <?php foreach($dept_list as $d) echo "<option value='$d'>$d</option>"; ?>
             </select>
             <?php endif; ?>
             <span class="add-count">Selected: <span id="selCount">0</span></span>
        </div>

        <div class="add-info mb-2">
            <i class="fas fa-magic text-primary"></i>
            <span>Showing unmapped students from <strong><span id="suggDeptDisplay">Department</span></strong></span>
        </div>

        <div class="add-select-bar">
            <div class="d-flex align-items-center">
                <input type="checkbox" class="form-check-input me-2" id="selectAllAdd" onchange="toggleSelectAll(this)">
                <label for="selectAllAdd" class="small fw-bold cursor-pointer">Select All Visible</label>
            </div>
            <div class="text-muted small">Tip: Search by ID or Name</div>
        </div>

        <div id="stuResults" class="add-results"></div>

        <div class="add-footer">
            <button class="btn-insta w-100 py-3" onclick="confirmAdd()">
                Confirm Allocation (<span id="selCountFooter">0</span>)
            </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include '../assets/ui/floating_chat_widget.php'; ?>

<script>
    let currentFacId = null;
    let currentFacDept = '';
    let selectedStudents = new Set();
    const isAdminHOD = <?= $is_admin_hod ? 'true' : 'false' ?>;
    const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

    function showToast(msg) {
        const t = document.getElementById("toast");
        t.innerText = msg; t.classList.add("show");
        setTimeout(() => t.classList.remove("show"), 3000);
    }

    // --- TABS ---
    function switchTab(id) {
        document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
        const target = document.getElementById('tab-' + id);
        if(target) target.style.display = 'block';

        document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));
        const btn = document.getElementById('btn-' + id);
        if(btn) btn.classList.add('active');

        if(id === 'directory') loadDirectory();
        if(id === 'manage') searchFacultyAlloc();
    }

    // --- DIRECTORY ---
    function loadDirectory() {
        const term = document.getElementById('dirSearch').value;
        const dept = document.getElementById('dirDeptFilter') ? document.getElementById('dirDeptFilter').value : 'all';
        const grid = document.getElementById('dirResults');
        if(!grid) return;

        fetch(`?ajax=1&action=search_faculty&term=${term}&dept=${dept}`).then(r=>r.json()).then(data=>{
            grid.innerHTML = '';
            if(!data.length) grid.innerHTML = '<div class="text-center p-5 text-muted col-12">No faculty found.</div>';
            data.forEach(f => {
                let btnHtml = isAdminHOD
                    ? `<button class="btn-outline btn-sm" onclick="switchToManage('${f.ID_NO}', '${f.NAME}', '${f.DEPARTMENT}')">Manage</button>`
                    : `<span class="badge-soft bg-g border">Department View</span>`;

                grid.innerHTML += `
                <div class="table-row">
                    <div class="av-circle" style="background:#fce7f3; color:#bc1888;">${f.NAME[0]}</div>
                    <div>
                        <div class="fw-bold text-dark">${f.NAME}</div>
                        <small class="text-muted">${f.mentee_count} Mentees • ${f.DEPARTMENT}</small>
                    </div>
                    <div class="text-end actions-col">
                         ${btnHtml}
                    </div>
                </div>`;
            });
        });
    }

    function switchToManage(fid, name, dept) {
        switchTab('manage');
        selectFaculty(null, fid, name, dept);
    }

    // --- MANAGE ALLOCATIONS ---
    function searchFacultyAlloc() {
        const term = document.getElementById('allocSearch').value;
        const list = document.getElementById('allocList');
        if(!list) return;

        fetch(`?ajax=1&action=search_faculty&term=${term}`).then(r=>r.json()).then(data=>{
            list.innerHTML = '';
            data.forEach(f => {
                list.innerHTML += `
                <div class="alloc-row" onclick="selectFaculty(event, '${f.ID_NO}', '${f.NAME}', '${f.DEPARTMENT}')">
                    <div class="av-circle">${f.NAME[0]}</div>
                    <div>
                        <div class="fw-bold small text-dark">${f.NAME}</div>
                        <small class="text-muted" style="font-size:0.75rem;">${f.DEPARTMENT}</small>
                    </div>
                </div>`;
            });
        });
    }

    function selectFaculty(e, fid, name, dept) {
        currentFacId = fid;
        currentFacDept = dept;

        document.getElementById('allocFacName').innerText = name;
        document.getElementById('selFacAv').style.display = 'flex';
        document.getElementById('selFacAv').innerText = name[0];
        document.getElementById('allocActions').style.display = 'flex';

        // Highlight active
        document.querySelectorAll('.alloc-row').forEach(el => el.classList.remove('active'));
        if(e && e.currentTarget) e.currentTarget.classList.add('active');

        loadMentees();
    }

    function loadMentees() {
        fetch(`?ajax=1&action=get_mentees&fid=${currentFacId}`).then(r=>r.json()).then(res=>{
            const list = document.getElementById('allocMentees');
            list.innerHTML = '';
            if(res.data.length === 0) list.innerHTML = '<div class="text-center p-5 text-muted">No mentees assigned yet.</div>';

            res.data.forEach(s => {
                list.innerHTML += `
                <div class="alloc-row px-3 py-2">
                    <input type="checkbox" class="form-check-input me-2 remove-chk" value="${s.sid}">
                    <div>
                        <div class="fw-bold small text-dark">${s.name}</div>
                        <small class="text-muted">${s.sid} • ${s.batch}</small>
                    </div>
                    <span class="badge-soft bg-g ms-auto">${s.dept}</span>
                </div>`;
            });
            document.getElementById('allocCount').innerText = res.data.length + ' Mentees';
        });
    }

    // --- ADD MODAL ---
    const addModal = new bootstrap.Modal(document.getElementById('addModal'));
    function openAddModal() {
        selectedStudents.clear();
        updateCount();
        document.getElementById('stuSearch').value = '';
        if(document.getElementById('selectAllAdd')) document.getElementById('selectAllAdd').checked = false;
        autoLoadSuggestions();
        addModal.show();
    }

    function autoLoadSuggestions() {
        let d = currentFacDept;
        const adminSel = document.getElementById('addModalDept');
        if(adminSel && adminSel.value) d = adminSel.value;
        document.getElementById('suggDeptDisplay').innerText = d || 'All';

        document.getElementById('stuResults').innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div></div>';

        fetch(`?ajax=1&action=get_dept_suggestions&dept=${d}`).then(r=>r.json()).then(res=>{
            renderAddList(res.data);
        });
    }

    function searchStudents() {
        const term = document.getElementById('stuSearch').value;
        if(term.length < 3) return;
        fetch(`?ajax=1&action=search_students&term=${term}`).then(r=>r.json()).then(data=>renderAddList(data));
    }

    function renderAddList(data) {
        const list = document.getElementById('stuResults');
        list.innerHTML = '';
        if(!data || data.length === 0) {
            list.innerHTML = '<div class="p-4 text-center text-muted">No unmapped students found.</div>';
            return;
        }

        data.forEach(s => {
            let badge = (s.status=='assigned')
                ? `<span class="badge-soft bg-r ms-auto">Has Mentor: ${s.mentor_name}</span>`
                : `<span class="badge-soft bg-g ms-auto">Free</span>`;

            const isChecked = selectedStudents.has(s.id) ? 'checked' : '';

            list.innerHTML += `
            <div class="add-row">
                <input type="checkbox" class="form-check-input me-2 add-chk" value="${s.id}" ${isChecked} onchange="toggleAdd('${s.id}')">
                <div>
                    <div class="fw-bold small text-dark">${s.name}</div>
                    <div class="meta">${s.id} ${s.batch ? '• ' + s.batch : ''} ${s.dept ? '• ' + s.dept : ''}</div>
                </div>
                ${badge}
            </div>`;
        });
    }

    function toggleAdd(id) {
        if(selectedStudents.has(id)) selectedStudents.delete(id); else selectedStudents.add(id);
        updateCount();
    }

    function toggleSelectAll(master) {
        const checkboxes = document.querySelectorAll('.add-chk');
        checkboxes.forEach(cb => {
            cb.checked = master.checked;
            if(master.checked) selectedStudents.add(cb.value);
            else selectedStudents.delete(cb.value);
        });
        updateCount();
    }

    function updateCount() {
        document.getElementById('selCount').innerText = selectedStudents.size;
        const footerCount = document.getElementById('selCountFooter');
        if (footerCount) footerCount.innerText = selectedStudents.size;
    }

    function confirmAdd() {
        if(selectedStudents.size === 0) return alert("Select at least one student.");
        const ids = Array.from(selectedStudents).join(',');
        const fd = new FormData(); fd.append('fid', currentFacId); fd.append('sids', ids); fd.append('_csrf', CSRF_TOKEN);

        fetch('?ajax=1&action=assign_bulk', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            addModal.hide();
            showToast(`Successfully assigned ${selectedStudents.size} students!`);
            loadMentees();
        });
    }

    // --- REMOVE ---
    function removeBulk() {
        const chks = document.querySelectorAll('.remove-chk:checked');
        if(chks.length === 0) return alert("Select mentees to remove.");
        if(!confirm(`Remove ${chks.length} mentees from this faculty?`)) return;

        const ids = Array.from(chks).map(c=>c.value).join(',');
        const fd = new FormData(); fd.append('sids', ids); fd.append('_csrf', CSRF_TOKEN);

        fetch('?ajax=1&action=remove_bulk', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            showToast("Mentees removed successfully.");
            loadMentees();
        });
    }

    // --- CHAT ---
    function startChat(id, name) {
        if(typeof window.openChatWidget === 'function') {
            window.openChatWidget(id, name);
        } else {
            alert("Chat system loading...");
        }
    }

    // --- INIT TABS ---
    document.addEventListener("DOMContentLoaded", () => {
        const activeTab = document.querySelector('.nav-link.active');
        if(activeTab) {
            const tabId = activeTab.id.replace('btn-', '');
            switchTab(tabId);
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
