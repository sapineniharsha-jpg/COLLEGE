<?php
// =========================================================
// 1. BACKEND LOGIC & SECURITY
// =========================================================
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$include_paths = [__DIR__ . '/..', dirname(__DIR__)];
if (!function_exists('find_include_path')) {
    function find_include_path(array $paths, $relative) {
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
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    echo 'Database connection not initialized.';
    exit();
}

$security_path = __DIR__ . '/../platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}
if (!function_exists('vh_e')) {
    function vh_e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('vh_bind_params')) {
    function vh_bind_params($stmt, $types, array &$values) {
        $refs = [];
        $refs[] = &$types;
        foreach ($values as $k => $v) {
            $refs[] = &$values[$k];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

// Auth Check
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Session Expired']);
        exit;
    }
    header('Location: /login.php');
    exit();
}

$user_id = $_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? '';
$role = strtolower($_SESSION['role'] ?? '');
$user_dept = $_SESSION['DEPARTMENT'] ?? '';

// Access Control
$allowed_roles = ['admin', 'principal', 'dean', 'hod', 'counsellor', 'faculty'];
if (!in_array($role, $allowed_roles, true)) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'>Access Denied.</div>");
}

$can_edit = in_array($role, ['counsellor', 'admin', 'faculty'], true);
$is_super = in_array($role, ['admin', 'principal', 'dean'], true);
$is_admin = ($role === 'admin'); // Specific check for Delete feature
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

// ---------------------------------------------------------
// AJAX API
// ---------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    try {
        // 1. SEARCH STUDENTS
        if ($action === 'search_student') {
            $term = trim((string) ($_GET['term'] ?? ''));
            $like = '%' . $term . '%';
            $results = [];

            $q1 = $mysqli->prepare(
                "SELECT IDNo as id, Name as name, Batch as batch, Dept as dept
                 FROM students_login_master
                 WHERE IDNo LIKE ? OR Name LIKE ?
                 LIMIT 5"
            );
            if ($q1) {
                $q1->bind_param("ss", $like, $like);
                $q1->execute();
                $r1 = $q1->get_result();
                if ($r1) {
                    while ($r = $r1->fetch_assoc()) {
                        $results[] = $r;
                    }
                }
            }

            $q2 = $mysqli->prepare(
                "SELECT id_no as id, student_name as name, batch as batch, department as dept
                 FROM students_batch_25_26
                 WHERE id_no LIKE ? OR student_name LIKE ?
                 LIMIT 5"
            );
            if ($q2) {
                $q2->bind_param("ss", $like, $like);
                $q2->execute();
                $r2 = $q2->get_result();
                if ($r2) {
                    while ($r = $r2->fetch_assoc()) {
                        $results[] = $r;
                    }
                }
            }

            echo json_encode($results);
            exit;
        }

        // 2. DELETE RECORD (ADMIN ONLY)
        if ($action === 'delete_session') {
            if (function_exists('vh_require_csrf_or_exit')) {
                vh_require_csrf_or_exit(true);
            }
            if (!$is_admin) {
                throw new Exception("Access Denied: Only Admins can delete records.");
            }

            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $mysqli->prepare("DELETE FROM counseling_records WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("Delete failed: " . $stmt->error);
            }
            exit;
        }

        // 3. SAVE OR UPDATE SESSION RECORD
        if ($action === 'save_session') {
            if (function_exists('vh_require_csrf_or_exit')) {
                vh_require_csrf_or_exit(true);
            }
            if (!$can_edit) {
                throw new Exception("Permission denied");
            }

            $rec_id = trim((string) ($_POST['record_id'] ?? ''));
            $sid = trim((string) ($_POST['student_id'] ?? ''));
            $sname = trim((string) ($_POST['student_name'] ?? ''));
            $dept = trim((string) ($_POST['dept'] ?? ''));
            $batch = trim((string) ($_POST['batch'] ?? ''));
            $type = trim((string) ($_POST['type'] ?? ''));
            $date = trim((string) ($_POST['date'] ?? ''));

            $d = json_decode((string) ($_POST['session_data'] ?? '{}'), true);
            if (!is_array($d)) {
                $d = [];
            }
            $v = function ($k) use ($d) {
                return (isset($d[$k]) && $d[$k] !== '') ? $d[$k] : null;
            };

            if (empty($sid)) {
                throw new Exception("Student ID is missing.");
            }

            if (!empty($rec_id)) {
                // UPDATE RECORD
                $sql = "UPDATE counseling_records SET
                        student_name=?, department=?, batch=?, session_date=?, counseling_type=?,
                        ac_attendance=?, ac_arrears=?, ac_backlog=?, ac_cgpa=?, ac_time_mgmt=?, ac_stress=?, ac_peer_issues=?, ac_distractions=?, ac_goals=?, ac_additional=?, ac_followup=?,
                        ch_place=?, ch_contact=?, ch_mentor=?, ch_mother_occ=?, ch_father_occ=?, ch_family_tree=?, ch_family_type=?, ch_hosteller=?, ch_prior_counsel=?, ch_hobbies=?, ch_speech=?, ch_thought=?, ch_sleep=?, ch_appetite=?, ch_medical_hist=?, ch_problems=?, ch_intervention=?, ch_summary=?, ch_follow_date=?,
                        fu_prev_date=?, fu_progress=?, fu_strategies=?, fu_concerns=?, fu_intervention=?
                        WHERE id=?";

                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception($mysqli->error);
                }

                $rec_id_int = (int) $rec_id;
                $update_values = [
                    $sname, $dept, $batch, $date, $type,
                    $v('f_attendance'), $v('f_arrears'), $v('f_backlog'), $v('f_cgpa'), $v('f_timemgmt'), $v('f_stress'), $v('f_bullying'), $v('f_distractions'), $v('f_goals'), $v('f_additional'), $v('f_followup'),
                    $v('f_place'), $v('f_contact'), $v('f_mentor'), $v('f_mother_occ'), $v('f_father_occ'), $v('f_family_tree'), $v('f_family_type'), $v('f_stay'), $v('f_prior_counsel'), $v('f_hobbies'), $v('f_speech'), $v('f_thought'), $v('f_sleep'), $v('f_appetite'), $v('f_medical'), $v('f_problems'), $v('f_intervention'), $v('f_summary'), $v('f_follow_date'),
                    $v('f_prev_date'), $v('f_progress'), $v('f_strategies'), $v('f_concerns'), $v('f_intervention_fu'),
                    $rec_id_int
                ];
                $update_types = "sssssssssssssssssssssssssssssssssssssssssi";
                vh_bind_params($stmt, $update_types, $update_values);
            } else {
                // INSERT RECORD
                $placeholders = implode(',', array_fill(0, 42, '?'));
                $sql = "INSERT INTO counseling_records (
                        student_id, student_name, department, batch, counselor_id, session_date, counseling_type,
                        ac_attendance, ac_arrears, ac_backlog, ac_cgpa, ac_time_mgmt, ac_stress, ac_peer_issues, ac_distractions, ac_goals, ac_additional, ac_followup,
                        ch_place, ch_contact, ch_mentor, ch_mother_occ, ch_father_occ, ch_family_tree, ch_family_type, ch_hosteller, ch_prior_counsel, ch_hobbies, ch_speech, ch_thought, ch_sleep, ch_appetite, ch_medical_hist, ch_problems, ch_intervention, ch_summary, ch_follow_date,
                        fu_prev_date, fu_progress, fu_strategies, fu_concerns, fu_intervention
                    ) VALUES ($placeholders)";

                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception($mysqli->error);
                }

                $insert_values = [
                    $sid, $sname, $dept, $batch, $user_id, $date, $type,
                    $v('f_attendance'), $v('f_arrears'), $v('f_backlog'), $v('f_cgpa'), $v('f_timemgmt'), $v('f_stress'), $v('f_bullying'), $v('f_distractions'), $v('f_goals'), $v('f_additional'), $v('f_followup'),
                    $v('f_place'), $v('f_contact'), $v('f_mentor'), $v('f_mother_occ'), $v('f_father_occ'), $v('f_family_tree'), $v('f_family_type'), $v('f_stay'), $v('f_prior_counsel'), $v('f_hobbies'), $v('f_speech'), $v('f_thought'), $v('f_sleep'), $v('f_appetite'), $v('f_medical'), $v('f_problems'), $v('f_intervention'), $v('f_summary'), $v('f_follow_date'),
                    $v('f_prev_date'), $v('f_progress'), $v('f_strategies'), $v('f_concerns'), $v('f_intervention_fu')
                ];
                $insert_types = "sssssss" . "sssssssssss" . "sssssssssssssssssss" . "sssss";
                vh_bind_params($stmt, $insert_types, $insert_values);
            }

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("DB Error: " . $stmt->error);
            }
            exit;
        }

        // 4. FETCH HISTORY
        if ($action === 'fetch_history') {
            $filter_dept = trim((string) ($_GET['dept'] ?? 'all'));
            $filter_sid = trim((string) ($_GET['student_id'] ?? ''));

            $where = [];
            $params = [];
            $types = '';

            if ($role === 'hod') {
                $where[] = "department = ?";
                $params[] = $user_dept;
                $types .= 's';
            }
            if ($role === 'counsellor' || $role === 'faculty') {
                $where[] = "counselor_id = ?";
                $params[] = $user_id;
                $types .= 's';
            }
            if ($is_super && $filter_dept !== '' && $filter_dept !== 'all') {
                $where[] = "department = ?";
                $params[] = $filter_dept;
                $types .= 's';
            }
            if ($filter_sid !== '') {
                $where[] = "student_id = ?";
                $params[] = $filter_sid;
                $types .= 's';
            }

            $sql = "SELECT *, COALESCE(updated_at, created_at) as last_modified FROM counseling_records";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY session_date DESC LIMIT 50";

            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception($mysqli->error);
            }
            if (!empty($params)) {
                vh_bind_params($stmt, $types, $params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];

            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $form_data = [];
                    // Map columns back to JSON for JS compatibility
                    if (($row['counseling_type'] ?? '') === 'ACADEMIC') {
                        $form_data = [
                            'f_attendance' => $row['ac_attendance'], 'f_arrears' => $row['ac_arrears'], 'f_backlog' => $row['ac_backlog'],
                            'f_cgpa' => $row['ac_cgpa'], 'f_timemgmt' => $row['ac_time_mgmt'], 'f_stress' => $row['ac_stress'],
                            'f_bullying' => $row['ac_peer_issues'], 'f_distractions' => $row['ac_distractions'], 'f_goals' => $row['ac_goals'],
                            'f_additional' => $row['ac_additional'], 'f_followup' => $row['ac_followup']
                        ];
                    } elseif (($row['counseling_type'] ?? '') === 'CASE_HISTORY') {
                        $form_data = [
                            'f_place' => $row['ch_place'], 'f_contact' => $row['ch_contact'], 'f_mentor' => $row['ch_mentor'],
                            'f_mother_occ' => $row['ch_mother_occ'], 'f_father_occ' => $row['ch_father_occ'], 'f_family_tree' => $row['ch_family_tree'],
                            'f_family_type' => $row['ch_family_type'], 'f_stay' => $row['ch_hosteller'], 'f_prior_counsel' => $row['ch_prior_counsel'],
                            'f_hobbies' => $row['ch_hobbies'], 'f_speech' => $row['ch_speech'], 'f_thought' => $row['ch_thought'],
                            'f_sleep' => $row['ch_sleep'], 'f_appetite' => $row['ch_appetite'], 'f_medical' => $row['ch_medical_hist'],
                            'f_problems' => $row['ch_problems'], 'f_intervention' => $row['ch_intervention'], 'f_summary' => $row['ch_summary'], 'f_follow_date' => $row['ch_follow_date']
                        ];
                    } else {
                        $form_data = [
                            'f_prev_date' => $row['fu_prev_date'], 'f_progress' => $row['fu_progress'],
                            'f_strategies' => $row['fu_strategies'], 'f_concerns' => $row['fu_concerns'],
                            'f_intervention_fu' => $row['fu_intervention']
                        ];
                    }
                    $row['session_data'] = json_encode(array_filter($form_data, function ($v) {
                        return !is_null($v);
                    }));
                    $data[] = $row;
                }
            }
            echo json_encode($data);
            exit;
        }

        // 5. UPLOAD DOCUMENT
        if ($action === 'upload_doc') {
            if (function_exists('vh_require_csrf_or_exit')) {
                vh_require_csrf_or_exit(true);
            }
            $rec_id = (int) ($_POST['record_id'] ?? 0);
            if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
                $ext = strtolower((string) pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
                    throw new Exception("Invalid file type");
                }
                $filename = "counseling_" . $rec_id . "_" . time() . "." . $ext;
                $target_dir = "../uploads/counseling/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                if (move_uploaded_file($_FILES['file']['tmp_name'], $target_dir . $filename)) {
                    $up_stmt = $mysqli->prepare("UPDATE counseling_records SET signed_document_path = ? WHERE id = ?");
                    if ($up_stmt) {
                        $up_stmt->bind_param("si", $filename, $rec_id);
                        $up_stmt->execute();
                    }
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception("File upload failed");
                }
            }
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch Departments
$depts = [];
if ($is_super) {
    $dr = $mysqli->query("SELECT DISTINCT Dept FROM students_login_master ORDER BY Dept");
    if ($dr) {
        while ($r = $dr->fetch_assoc()) {
            $depts[] = $r['Dept'];
        }
    }
}

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.ckeditor.com/4.22.1/full/ckeditor.js"></script>

<script>
    const IS_ADMIN = <?= ($is_admin) ? 'true' : 'false' ?>;
    const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
</script>

<style>
    :root { --ig-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); --bg-glass: rgba(255, 255, 255, 0.95); --shadow-soft: 0 8px 32px 0 rgba(31, 38, 135, 0.07); }
    body { background-color: #f8fafc; font-family: 'Outfit', sans-serif; }
    .pwa-container { max-width: 1400px; margin: 0 auto; padding: 25px; min-height: 85vh; }
    .dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .page-title { font-weight: 800; font-size: 2rem; background: var(--ig-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0; }
    .glass-card { background: var(--bg-glass); backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.4); border-radius: 24px; padding: 30px; margin-bottom: 30px; box-shadow: var(--shadow-soft); }
    .form-label { font-weight: 600; color: #64748b; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-control, .form-select { border-radius: 12px; padding: 12px 15px; border: 1px solid #e2e8f0; font-size: 0.95rem; background: #f8fafc; transition: 0.2s; }
    .form-control:focus, .form-select:focus { background: #fff; border-color: #bc1888; box-shadow: 0 0 0 4px rgba(188, 24, 136, 0.1); }
    .section-title { font-weight: 800; color: #333; border-bottom: 3px solid #eee; padding-bottom: 10px; margin-top: 30px; margin-bottom: 20px; font-size: 1.1rem; }
    .btn-grad { background: var(--ig-grad); color: white; border: none; padding: 12px 30px; border-radius: 50px; font-weight: 700; font-size: 1rem; cursor: pointer; box-shadow: 0 5px 15px rgba(220, 39, 67, 0.3); transition: 0.3s; width: 100%; }
    .btn-grad:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(220, 39, 67, 0.5); color: white; }
    .search-result { padding: 12px; border-bottom: 1px solid #eee; cursor: pointer; transition: 0.2s; }
    .search-result:hover { background: #fff0f5; color: #bc1888; }
    .status-badge { padding: 5px 12px; border-radius: 8px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
    .status-pending { background: #fff7ed; color: #c2410c; }
    .status-done { background: #f0fdf4; color: #15803d; }
    .table-custom th { text-transform: uppercase; font-size: 0.75rem; color: #94a3b8; font-weight: 700; border-bottom: 2px solid #f1f5f9; padding: 15px; }
    .table-custom td { padding: 15px; vertical-align: middle; color: #334155; font-weight: 500; border-bottom: 1px solid #f8fafc; }
    @media print {
        body > *:not(#printSection) { display: none !important; }
        #printSection { display: block !important; position: absolute; left: 0; top: 0; width: 100%; padding: 40px; background: white; color: black; }
        .print-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 30px; }
        .print-logo { height: 80px; width: auto; margin-bottom: 10px; }
        .print-title { font-size: 22px; font-weight: bold; text-transform: uppercase; margin: 10px 0; }
        .print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 30px; font-size: 14px; }
        .print-field { margin-bottom: 15px; page-break-inside: avoid; }
        .print-label { font-weight: bold; font-size: 12px; text-transform: uppercase; color: #444; }
        .print-value { border-bottom: 1px dotted #999; padding-bottom: 5px; font-size: 14px; min-height: 20px; display: block; width: 100%; }
        .print-signature { margin-top: 60px; display: flex; justify-content: space-between; padding-top: 40px; }
        .sig-line { width: 30%; border-top: 1px solid #000; text-align: center; font-size: 12px; font-weight: bold; }
    }
</style>

<div class="pwa-container">
    <div class="dash-header">
        <div><h1 class="page-title">Counseling Portal</h1><p class="text-muted mb-0">Record, Track, and Guide</p></div>
        <div><span class="status-badge status-done border"><?= strtoupper(vh_e($role)) ?> ACCOUNT</span></div>
    </div>

    <div class="glass-card" id="formSection">
        <h4 class="mb-4 fw-bold text-dark"><i class="fas fa-edit me-2 text-primary"></i>Session Details</h4>
        <input type="hidden" id="editRecordId">

        <div class="row g-4 mb-4">
            <div class="col-md-6 position-relative">
                <label class="form-label">Find Student</label>
                <input type="text" id="stuSearch" class="form-control" placeholder="Enter ID or Name..." onkeyup="searchStudent()">
                <div id="searchResults" class="position-absolute w-100 bg-white shadow rounded-3 mt-1" style="z-index: 10; overflow:hidden;"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Counseling Type</label>
                <select id="cType" class="form-select" onchange="renderForm()">
                    <option value="ACADEMIC">Academic Performance</option>
                    <option value="CASE_HISTORY">Detailed Case History</option>
                    <option value="FOLLOW_UP">Follow-up Session</option>
                </select>
            </div>
        </div>

        <div id="studentContext" class="p-3 bg-light rounded-4 border mb-4 d-none">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-1" id="dispName">Name</h5>
                    <p class="mb-0 text-muted small" id="dispDetails">Details</p>
                    <input type="hidden" id="hidId"><input type="hidden" id="hidName"><input type="hidden" id="hidDept"><input type="hidden" id="hidBatch">
                </div>
                <div class="text-end">
                    <label class="form-label d-block text-muted">Date</label>
                    <input type="date" id="sessionDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </div>

        <div id="dynamicForm" class="mb-4"></div>

        <div class="text-end">
            <button class="btn btn-secondary me-2" onclick="resetForm()">Clear</button>
            <button class="btn-grad w-auto px-4" id="btnSave" onclick="saveRecord()"><i class="fas fa-save me-2"></i> Save Record</button>
        </div>
    </div>

    <div class="glass-card mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold text-dark mb-0" id="historyTitle"><i class="fas fa-history me-2 text-warning"></i>Recent History</h4>
            <?php if($is_super): ?>
            <select id="histFilter" class="form-select w-auto form-select-sm" onchange="loadHistory()">
                <option value="all">All Departments</option><?php foreach($depts as $d) echo "<option value='" . vh_e($d) . "'>" . vh_e($d) . "</option>"; ?>
            </select>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-custom table-hover">
                <thead><tr><th>Date</th><th>Student</th><th>Dept</th><th>Year</th><th>Type</th><th>Updated</th><th>Status</th><th>Action</th></tr></thead>
                <tbody id="historyBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="printSection"></div>

<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Upload Signed Report</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 text-center">
                <div class="mb-3"><input type="file" id="uploadFile" class="form-control" accept="application/pdf,image/*"><input type="hidden" id="uploadRecId"></div>
                <button class="btn-grad w-100" onclick="submitUpload()">Confirm Upload</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.allHistoryData = [];

    function escHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, function(m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
        });
    }

    // --- SMART YEAR CALCULATION ---
    function getStudyYear(batch) {
        if(!batch) return '-';
        const startYear = parseInt(String(batch).substring(0, 4), 10);
        if (isNaN(startYear)) return batch;
        const today = new Date();
        const currentYear = today.getFullYear();
        const currentMonth = today.getMonth() + 1;
        let effectiveYear = currentYear;
        if (currentMonth < 6) effectiveYear = currentYear - 1;
        let yearNum = (effectiveYear - startYear) + 1;
        if (yearNum < 1) yearNum = 1;
        if (yearNum > 4) return 'Alumni';
        const suffix = (yearNum === 1) ? 'st' : (yearNum === 2) ? 'nd' : (yearNum === 3) ? 'rd' : 'th';
        return `${yearNum}${suffix} Year`;
    }

    // --- UTILS ---
    function initEditor(id) {
        if (window.CKEDITOR) {
             if(CKEDITOR.instances[id]) CKEDITOR.instances[id].destroy(true);
             CKEDITOR.replace(id, { height: 150, toolbar: 'Full', allowedContent: true, extraPlugins: 'colorbutton,font,justify', resize_enabled: false });
        }
    }

    function searchStudent() {
        const val = document.getElementById('stuSearch').value;
        if(val.length < 3) { document.getElementById('searchResults').innerHTML = ''; return; }
        fetch(`?ajax=1&action=search_student&term=${encodeURIComponent(val)}`).then(r=>r.json()).then(data => {
            let html = '';
            data.forEach(s => {
                html += `<div class="search-result" onclick='selectStudent(${JSON.stringify(s.id)}, ${JSON.stringify(s.name)}, ${JSON.stringify(s.dept)}, ${JSON.stringify(s.batch)})'><strong>${escHtml(s.name)}</strong> <span class="text-muted small">(${escHtml(s.id)})</span></div>`;
            });
            document.getElementById('searchResults').innerHTML = html;
        });
    }

    function selectStudent(id, name, dept, batch) {
        document.getElementById('hidId').value = id;
        document.getElementById('hidName').value = name;
        document.getElementById('hidDept').value = dept;
        document.getElementById('hidBatch').value = batch;
        document.getElementById('dispName').innerText = name;
        document.getElementById('dispDetails').innerText = `${id} • ${dept} • Batch ${batch}`;
        document.getElementById('studentContext').classList.remove('d-none');
        document.getElementById('searchResults').innerHTML = '';
        document.getElementById('stuSearch').value = '';
        loadHistory(id);
    }

    function renderForm() {
        const type = document.getElementById('cType').value;
        const box = document.getElementById('dynamicForm');

        if (window.CKEDITOR) {
            Object.keys(CKEDITOR.instances).forEach(name => CKEDITOR.instances[name].destroy(true));
        }

        box.innerHTML = '';
        let h = '';

        if (type === 'ACADEMIC') {
            h = `<div class="section-title">BASIC ACADEMIC INFORMATION</div><div class="row g-3">
                <div class="col-md-6"><label class="form-label" id="lbl_attendance">Attendance Issues</label><select class="form-select" id="f_attendance"><option>No</option><option>Yes</option></select></div>
                <div class="col-md-6"><label class="form-label" id="lbl_arrears">Number of Arrears</label><input type="text" class="form-control" id="f_arrears"></div>
                <div class="col-12"><label class="form-label" id="lbl_backlog">Subjects with backlog</label><input type="text" class="form-control" id="f_backlog"></div>
                <div class="col-12"><label class="form-label" id="lbl_cgpa">Current CGPA</label><input type="text" class="form-control" id="f_cgpa"></div></div>
                <div class="section-title">OBSERVATIONS</div><div class="row g-3">
                <div class="col-md-4"><label class="form-label" id="lbl_timemgmt">Time Management</label><select class="form-select" id="f_timemgmt"><option>Good</option><option>Needs Improvement</option></select></div>
                <div class="col-md-4"><label class="form-label" id="lbl_stress">Academic Stress</label><select class="form-select" id="f_stress"><option>No</option><option>Yes</option></select></div>
                <div class="col-md-4"><label class="form-label" id="lbl_bullying">Peer Issues</label><select class="form-select" id="f_bullying"><option>No</option><option>Yes</option></select></div>
                <div class="col-12"><label class="form-label" id="lbl_distractions">Distractions</label><textarea class="form-control" id="f_distractions" rows="2"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_goals">Goals (Long Answer)</label><textarea class="form-control" id="f_goals" rows="4"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_additional">Additional Info (Long Answer)</label><textarea class="form-control" id="f_additional" rows="4"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_followup">Follow-up Required</label><select class="form-select" id="f_followup"><option>No</option><option>Yes</option></select></div></div>`;
        } else if (type === 'CASE_HISTORY') {
            h = `<div class="section-title">PERSONAL DATA</div><div class="row g-3">
                 <div class="col-md-4"><label class="form-label" id="lbl_place">Place</label><input type="text" class="form-control" id="f_place"></div>
                 <div class="col-md-4"><label class="form-label" id="lbl_contact">Contact No</label><input type="text" class="form-control" id="f_contact"></div>
                 <div class="col-md-4"><label class="form-label" id="lbl_mentor">Mentor Name</label><input type="text" class="form-control" id="f_mentor"></div>
                 <div class="col-md-6"><label class="form-label" id="lbl_mother_occ">Mother Occ</label><input type="text" class="form-control" id="f_mother_occ"></div>
                 <div class="col-md-6"><label class="form-label" id="lbl_father_occ">Father Occ</label><input type="text" class="form-control" id="f_father_occ"></div>
                 <div class="col-12"><label class="form-label" id="lbl_family_tree">Family Tree</label><textarea class="form-control" id="f_family_tree" rows="2"></textarea></div>
                 <div class="col-md-6"><label class="form-label" id="lbl_family_type">Family Type</label><select class="form-select" id="f_family_type"><option>Nuclear</option><option>Joint</option></select></div>
                 <div class="col-md-6"><label class="form-label" id="lbl_stay">Hosteller/DS</label><select class="form-select" id="f_stay"><option>Day Scholar</option><option>Hosteller</option></select></div>
                 <div class="col-md-6"><label class="form-label" id="lbl_prior_counsel">Prior Counseling?</label><select class="form-select" id="f_prior_counsel"><option>No</option><option>Yes</option></select></div>
                 <div class="col-12"><label class="form-label" id="lbl_hobbies">Hobbies</label><input type="text" class="form-control" id="f_hobbies"></div></div>
                 <div class="section-title">BEHAVIOR</div><div class="row g-3">
                <div class="col-md-3"><label class="form-label" id="lbl_speech">Speech</label><input type="text" class="form-control" id="f_speech"></div>
                <div class="col-md-3"><label class="form-label" id="lbl_thought">Thought</label><input type="text" class="form-control" id="f_thought"></div>
                <div class="col-md-3"><label class="form-label" id="lbl_sleep">Sleep</label><input type="text" class="form-control" id="f_sleep"></div>
                <div class="col-md-3"><label class="form-label" id="lbl_appetite">Appetite</label><input type="text" class="form-control" id="f_appetite"></div>
                <div class="col-12"><label class="form-label" id="lbl_medical">Medical History</label><select class="form-select" id="f_medical"><option>No</option><option>Yes</option></select></div></div>
                <div class="section-title">ANALYSIS</div><div class="row g-3">
                <div class="col-12"><label class="form-label" id="lbl_problems">Problems (Long Answer)</label><textarea class="form-control" id="f_problems" rows="4"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_intervention">Intervention (Long Answer)</label><textarea class="form-control" id="f_intervention" rows="4"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_summary">Summary (Long Answer)</label><textarea class="form-control" id="f_summary" rows="4"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_follow_date">Follow Up Date</label><input type="date" class="form-control" id="f_follow_date"></div></div>`;
        } else {
            h = `<div class="section-title">FOLLOW-UP DETAILS</div><div class="row g-3">
                <div class="col-md-6"><label class="form-label" id="lbl_prev_date">Prev Session Date</label><input type="date" class="form-control" id="f_prev_date"></div>
                <div class="col-md-6"><label class="form-label" id="lbl_progress">Progress</label><select class="form-select" id="f_progress"><option>Better</option><option>Same</option><option>Worse</option></select></div>
                <div class="col-12"><label class="form-label" id="lbl_strategies">Strategies Followed?</label><select class="form-select" id="f_strategies"><option>Yes</option><option>Partially</option><option>No</option></select></div>
                <div class="col-12"><label class="form-label" id="lbl_concerns">Concerns (Long Answer)</label><textarea class="form-control" id="f_concerns" rows="4"></textarea></div>
                <div class="col-12"><label class="form-label" id="lbl_intervention_fu">Intervention (Long Answer)</label><textarea class="form-control" id="f_intervention_fu" rows="4"></textarea></div></div>`;
        }
        box.innerHTML = h;

        setTimeout(() => {
            document.querySelectorAll('textarea').forEach(el => {
                if(el.previousElementSibling && el.previousElementSibling.innerText.includes('(Long Answer)')) { initEditor(el.id); }
            });
        }, 100);
    }

    // --- SAVE ---
    function saveRecord() {
        const sid = document.getElementById('hidId').value;
        if(!sid) return alert("Please select a student");

        if (window.CKEDITOR) {
            for (const n in CKEDITOR.instances) CKEDITOR.instances[n].updateElement();
        }

        let data = {};
        document.querySelectorAll('#dynamicForm input, #dynamicForm select, #dynamicForm textarea').forEach(el => {
            data[el.id] = el.value;
        });

        const fd = new FormData();
        fd.append('_csrf', CSRF_TOKEN);
        fd.append('record_id', document.getElementById('editRecordId').value);
        fd.append('student_id', sid);
        fd.append('student_name', document.getElementById('hidName').value);
        fd.append('dept', document.getElementById('hidDept').value);
        fd.append('batch', document.getElementById('hidBatch').value);
        fd.append('type', document.getElementById('cType').value);
        fd.append('date', document.getElementById('sessionDate').value);
        fd.append('session_data', JSON.stringify(data));

        fetch('?ajax=1&action=save_session', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(res => {
            if(res.success) {
                alert("Saved!");
                resetForm();
                loadHistory(sid);
            } else {
                alert("Error: " + (res.message || 'Unknown error'));
            }
        });
    }

    function resetForm() {
        document.getElementById('editRecordId').value = '';
        document.getElementById('btnSave').innerHTML = '<i class="fas fa-save me-2"></i> Save Record';
        renderForm();
    }

    // --- HISTORY (Dynamic, Smart Year, Delete) ---
    function loadHistory(studentId = '') {
        const dept = document.getElementById('histFilter') ? document.getElementById('histFilter').value : 'all';
        let url = `?ajax=1&action=fetch_history&dept=${encodeURIComponent(dept)}`;
        if(studentId) url += `&student_id=${encodeURIComponent(studentId)}`;

        const title = document.getElementById('historyTitle');
        if(studentId) title.innerHTML = `<i class="fas fa-user-clock me-2 text-primary"></i>History for selected student`;
        else title.innerHTML = `<i class="fas fa-history me-2 text-warning"></i>Recent History`;

        fetch(url).then(r=>r.json()).then(data=>{
            window.allHistoryData = data;
            let h = '';
            if(!Array.isArray(data) || data.length === 0) h = '<tr><td colspan="8" class="text-center text-muted">No records found.</td></tr>';
            else data.forEach(r => {
                let status = r.signed_document_path ? `<span class="status-badge status-done">Verified</span>` : `<span class="status-badge status-pending" onclick="triggerUpload(${Number(r.id)})" style="cursor:pointer">Upload</span>`;
                let studyYear = getStudyYear(r.batch);

                let deleteBtn = '';
                if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN === true) {
                    deleteBtn = `<button class="btn btn-sm btn-outline-danger me-1" onclick="deleteRecord(${Number(r.id)})" title="Delete"><i class="fas fa-trash"></i></button>`;
                }

                h += `<tr>
                        <td>${escHtml(r.session_date)}</td>
                        <td><div class="fw-bold text-dark">${escHtml(r.student_name)}</div><small class="text-muted">${escHtml(r.student_id)}</small></td>
                        <td>${escHtml(r.department || '-')}</td>
                        <td>${escHtml(studyYear)}</td>
                        <td>${escHtml(String(r.counseling_type || '').replace('_',' '))}</td>
                        <td>${escHtml(r.last_modified)}</td>
                        <td>${status}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="editRecord(${Number(r.id)})" title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-dark me-1" onclick="printHistoryRecord(${Number(r.id)})" title="Print"><i class="fas fa-print"></i></button>
                            ${deleteBtn}
                        </td>
                      </tr>`;
            });
            document.getElementById('historyBody').innerHTML = h;
        });
    }

    function editRecord(id) {
        const r = window.allHistoryData.find(x => Number(x.id) === Number(id));
        if(!r) return;

        window.scrollTo({ top: 0, behavior: 'smooth' });

        document.getElementById('editRecordId').value = r.id;
        document.getElementById('hidId').value = r.student_id;
        document.getElementById('hidName').value = r.student_name;
        document.getElementById('hidDept').value = r.department;
        document.getElementById('hidBatch').value = r.batch;
        document.getElementById('dispName').innerText = r.student_name;
        document.getElementById('dispDetails').innerText = `${r.student_id} • ${r.department} • Batch ${r.batch}`;
        document.getElementById('studentContext').classList.remove('d-none');
        document.getElementById('cType').value = r.counseling_type;
        document.getElementById('sessionDate').value = r.session_date;
        document.getElementById('btnSave').innerHTML = '<i class="fas fa-sync me-2"></i> Update Record';

        renderForm();

        setTimeout(() => {
            const data = JSON.parse(r.session_data || '{}');
            for(const [k, v] of Object.entries(data)) {
                if(document.getElementById(k)) {
                    document.getElementById(k).value = v;
                    if(CKEDITOR.instances[k]) CKEDITOR.instances[k].setData(v);
                }
            }
        }, 500);
    }

    function deleteRecord(id) {
        if(!confirm("Are you sure you want to delete this record? This cannot be undone.")) return;
        const fd = new FormData();
        fd.append('_csrf', CSRF_TOKEN);
        fd.append('id', id);
        fetch('?ajax=1&action=delete_session', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(res => {
            if(res.success) {
                alert("Record deleted.");
                loadHistory(document.getElementById('hidId').value);
            } else {
                alert(res.message || 'Delete failed');
            }
        });
    }

    function printHistoryRecord(id) {
        const r = window.allHistoryData.find(x => Number(x.id) === Number(id));
        if(!r) return;
        const data = JSON.parse(r.session_data || '{}');
        let fields = '';
        const labels = {
            'f_attendance':'Attendance', 'f_arrears':'Arrears', 'f_backlog':'Backlogs', 'f_cgpa':'CGPA', 'f_timemgmt':'Time Mgmt',
            'f_stress':'Stress', 'f_bullying':'Peer Issues', 'f_distractions':'Distractions', 'f_goals':'Goals', 'f_additional':'Additional Info',
            'f_place':'Place', 'f_contact':'Contact', 'f_mentor':'Mentor', 'f_mother_occ':'Mother Occ', 'f_father_occ':'Father Occ',
            'f_family_tree':'Family Tree', 'f_family_type':'Family Type', 'f_stay':'Hosteller/DS', 'f_prior_counsel':'Prior Counseling',
            'f_hobbies':'Hobbies', 'f_speech':'Speech', 'f_thought':'Thought', 'f_sleep':'Sleep', 'f_appetite':'Appetite', 'f_medical':'Medical Hist',
            'f_problems':'Problems Identified', 'f_intervention':'Intervention', 'f_summary':'Summary', 'f_follow_date':'Follow Up Date',
            'f_prev_date':'Prev Date', 'f_progress':'Progress', 'f_strategies':'Strategies', 'f_concerns':'Concerns', 'f_intervention_fu':'Intervention'
        };

        for (const [k, v] of Object.entries(data)) {
            let label = labels[k] || k;
            fields += `<div class="print-field"><div class="print-label">${escHtml(label)}</div><div class="print-value">${escHtml(v)}</div></div>`;
        }

        const html = `<div class="print-header"><img src="/assets/images/logo.png" class="print-logo"><h2>Vel Tech High Tech Dr.Rangarajan Dr.Sakunthala Engineering College</h2><div class="print-title">${escHtml(String(r.counseling_type || '').replace('_',' '))} REPORT</div></div>
            <div class="print-grid"><div class="print-field"><div class="print-label">Student Name</div><div class="print-value">${escHtml(r.student_name)}</div></div>
            <div class="print-field"><div class="print-label">Register No</div><div class="print-value">${escHtml(r.student_id)}</div></div>
            <div class="print-field"><div class="print-label">Date</div><div class="print-value">${escHtml(r.session_date)}</div></div>
            <div class="print-field"><div class="print-label">Department</div><div class="print-value">${escHtml(r.department)}</div></div>
            <div class="print-field"><div class="print-label">Batch</div><div class="print-value">${escHtml(r.batch)}</div></div></div>
            <hr><div style="margin-top:20px;">${fields}</div>
            <div class="print-signature"><div class="sig-line">Student Signature</div><div class="sig-line">Counselor Signature</div><div class="sig-line">Principal Signature</div></div>`;

        document.getElementById('printSection').innerHTML = html;
        window.print();
    }

    // UPLOAD LOGIC
    const upModal = new bootstrap.Modal(document.getElementById('uploadModal'));
    function triggerUpload(id) { document.getElementById('uploadRecId').value = id; upModal.show(); }
    function submitUpload() {
        const id = document.getElementById('uploadRecId').value;
        const file = document.getElementById('uploadFile').files[0];
        if(!file) return alert("Select file");
        const fd = new FormData();
        fd.append('_csrf', CSRF_TOKEN);
        fd.append('record_id', id);
        fd.append('file', file);
        fetch(`?ajax=1&action=upload_doc`, {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if(res.success) { upModal.hide(); loadHistory(); } else { alert("Upload failed"); }
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        renderForm();
        loadHistory();
    });
</script>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
