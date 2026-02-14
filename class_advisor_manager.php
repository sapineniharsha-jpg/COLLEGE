<?php
// class_advisor_manager.php - Assign Class Advisor & Section
ini_set('display_errors', 0);
error_reporting(E_ALL);
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

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

$user_id   = $_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? '';
$role      = strtolower($_SESSION['role'] ?? '');
$user_dept = $_SESSION['DEPARTMENT'] ?? '';

if (!in_array($role, ['admin', 'hod'], true)) {
    echo '<div style="padding:20px;font-family:Arial;">Access denied.</div>';
    exit();
}

// HOD dept fix
if ($role === 'hod' && empty($user_dept)) {
    $dept_q = $mysqli->query("SELECT DEPARTMENT FROM employee_details1 WHERE ID_NO = '" . $mysqli->real_escape_string($user_id) . "'");
    if ($dept_q && $row = $dept_q->fetch_assoc()) {
        $user_dept = $row['DEPARTMENT'];
        $_SESSION['DEPARTMENT'] = $user_dept;
    }
}

function is_science_humanities_dept($department) {
    $dep = strtolower(preg_replace('/[^a-z]/', '', (string) $department));
    return strpos($dep, 'science') !== false && strpos($dep, 'humanities') !== false;
}

function get_table_columns($mysqli, $table) {
    $cols = [];
    $res = $mysqli->query("SHOW COLUMNS FROM {$table}");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = $row;
        }
    }
    return $cols;
}

function pick_column($columns, array $candidates) {
    foreach ($candidates as $name) {
        if (isset($columns[$name])) {
            return $name;
        }
        foreach ($columns as $key => $meta) {
            if (strcasecmp($key, $name) === 0) {
                return $key;
            }
        }
    }
    return '';
}

$columns_meta = get_table_columns($mysqli, 'mentor_mentee');
$class_advisor_col = pick_column($columns_meta, ['ClassAdvisorID', 'class_advisor_id', 'class_advisor', 'advisor_id']);
$section_col = pick_column($columns_meta, ['section', 'Section', 'student_section', 'class_section']);
$mentor_col = pick_column($columns_meta, ['Employee_ID_No', 'Employee_ID', 'mentor_id']);
$dept_col = pick_column($columns_meta, ['department', 'Dept']);
$batch_col = pick_column($columns_meta, ['batch', 'Batch']);

$is_sh_hod = ($role === 'hod' && is_science_humanities_dept($user_dept));
$message = '';
$message_type = 'success';
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assignment'])) {
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(false);
    }
    if ($class_advisor_col === '') {
        $message = 'Class advisor column not found in mentor_mentee.';
        $message_type = 'danger';
    } else {
        $student_id = trim($_POST['student_id'] ?? '');
        $student_type = trim($_POST['student_type'] ?? '');
        $action = trim($_POST['action_type'] ?? 'save');
        $advisor_id = trim($_POST['class_advisor_id'] ?? '');
        $section_val = trim($_POST['section_value'] ?? '');

        if ($action === 'remove') {
            $advisor_id = '';
            $section_val = '';
        } elseif ($advisor_id === '') {
            $message = 'Please select a Class Advisor.';
            $message_type = 'danger';
        }

        if ($message_type !== 'danger') {
            $student_row = null;
            $student_dept = '';
            $student_batch = '';

            if ($student_type === 'fresher') {
                $stmt = $mysqli->prepare("SELECT department, batch FROM students_batch_25_26 WHERE id_no = ? LIMIT 1");
                $stmt->bind_param("s", $student_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $student_row = $res->fetch_assoc();
                    $student_dept = $student_row['department'] ?? '';
                    $student_batch = $student_row['batch'] ?? '';
                }
            } else {
                $stmt = $mysqli->prepare("SELECT Dept, Batch FROM students_login_master WHERE IDNo = ? LIMIT 1");
                $stmt->bind_param("s", $student_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $student_row = $res->fetch_assoc();
                    $student_dept = $student_row['Dept'] ?? '';
                    $student_batch = $student_row['Batch'] ?? '';
                }
            }

            if (!$student_row) {
                $message = 'Student not found.';
                $message_type = 'danger';
            } elseif ($role === 'hod') {
                if ($is_sh_hod) {
                    if ($student_type !== 'fresher') {
                        $message = 'Science & Humanities HOD can update only UG First Years.';
                        $message_type = 'danger';
                    }
                } elseif ($student_dept !== $user_dept) {
                    $message = 'You can update only your department students.';
                    $message_type = 'danger';
                }
            }
        }

        if ($message_type !== 'danger') {
            $exists = false;
            $stmt = $mysqli->prepare("SELECT id FROM mentor_mentee WHERE Student_ID_No = ? LIMIT 1");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $exists = true;
            }

            if ($action === 'remove' && !$exists) {
                $message = 'No existing record to remove.';
                $message_type = 'danger';
            } elseif ($exists) {
                $fields = [];
                $types = '';
                $values = [];
                $fields[] = "`{$class_advisor_col}` = ?";
                $types .= 's';
                $values[] = $advisor_id;
                if ($section_col !== '') {
                    $fields[] = "`{$section_col}` = ?";
                    $types .= 's';
                    $values[] = $section_val;
                }
                $types .= 's';
                $values[] = $student_id;

                $sql = "UPDATE mentor_mentee SET " . implode(', ', $fields) . " WHERE Student_ID_No = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $message = ($action === 'remove') ? 'Class advisor removed.' : 'Class advisor updated.';
                $message_type = 'success';
            } else {
                $insert_cols = ['Student_ID_No', $class_advisor_col];
                $insert_vals = [$student_id, $advisor_id];
                $insert_types = 'ss';

                if ($section_col !== '') {
                    $insert_cols[] = $section_col;
                    $insert_vals[] = $section_val;
                    $insert_types .= 's';
                }

                if ($mentor_col !== '' && isset($columns_meta[$mentor_col]) && ($columns_meta[$mentor_col]['Null'] ?? 'YES') === 'NO') {
                    $insert_cols[] = $mentor_col;
                    $insert_vals[] = $advisor_id;
                    $insert_types .= 's';
                }
                if ($dept_col !== '' && $student_dept !== '') {
                    $insert_cols[] = $dept_col;
                    $insert_vals[] = $student_dept;
                    $insert_types .= 's';
                }
                if ($batch_col !== '' && $student_batch !== '') {
                    $insert_cols[] = $batch_col;
                    $insert_vals[] = $student_batch;
                    $insert_types .= 's';
                }

                $placeholders = implode(',', array_fill(0, count($insert_cols), '?'));
                $sql = "INSERT INTO mentor_mentee (" . implode(', ', $insert_cols) . ") VALUES ({$placeholders})";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param($insert_types, ...$insert_vals);
                if ($stmt->execute()) {
                    $message = 'Class advisor saved.';
                    $message_type = 'success';
                } else {
                    $message = 'Error: ' . $mysqli->error;
                    $message_type = 'danger';
                }
            }
        }
    }
}

// Department filter for admin
$filter_dept = $_GET['dept'] ?? 'all';
if ($role === 'hod') {
    $filter_dept = $user_dept;
}

// Faculty list
$faculty = [];
$faculty_map = [];
$faculty_filter = ($role === 'admin' && $filter_dept !== 'all') ? $filter_dept : ($role === 'hod' ? $user_dept : '');
$faculty_sql = "SELECT ID_NO, NAME, DEPARTMENT FROM employee_details1";
if ($faculty_filter !== '') {
    $faculty_sql .= " WHERE DEPARTMENT = '" . $mysqli->real_escape_string($faculty_filter) . "'";
}
$faculty_sql .= " ORDER BY NAME ASC";
$fres = $mysqli->query($faculty_sql);
if ($fres) {
    while ($row = $fres->fetch_assoc()) {
        $faculty[] = $row;
        $faculty_map[$row['ID_NO']] = $row['NAME'];
    }
}

// Department list for admin filter
$dept_list = [];
if ($role === 'admin') {
    $dres = $mysqli->query("SELECT DISTINCT Dept FROM students_login_master ORDER BY Dept");
    if ($dres) {
        while ($r = $dres->fetch_assoc()) $dept_list[] = $r['Dept'];
    }
    if (!in_array('Science and Humanities', $dept_list, true)) {
        $dept_list[] = 'Science and Humanities';
    }
    sort($dept_list);
}

// Student list
$students = [];
$dept_filter_s = ($filter_dept && $filter_dept !== 'all') ? "AND s.Dept = '" . $mysqli->real_escape_string($filter_dept) . "'" : "";
$dept_filter_f = ($filter_dept && $filter_dept !== 'all') ? "AND s.department = '" . $mysqli->real_escape_string($filter_dept) . "'" : "";

$select_class = $class_advisor_col ? "m.`{$class_advisor_col}` as class_advisor_id" : "'' as class_advisor_id";
$select_section = $section_col ? "m.`{$section_col}` as section_val" : "'' as section_val";
$select_mentor = $mentor_col ? "m.`{$mentor_col}` as mentor_id" : "'' as mentor_id";

if (!$is_sh_hod) {
    $sql1 = "SELECT s.IDNo as student_id, s.Name as student_name, s.Dept as dept, s.Batch as batch, s.RegisterNo as register_no, 'senior' as type,
             {$select_class}, {$select_section}, {$select_mentor}
             FROM students_login_master s
             LEFT JOIN mentor_mentee m ON m.Student_ID_No = s.IDNo
             WHERE 1=1 {$dept_filter_s}
             ORDER BY s.Name ASC";
    $r1 = $mysqli->query($sql1);
    if ($r1) {
        while ($row = $r1->fetch_assoc()) $students[] = $row;
    }
}

$sql2 = "SELECT s.id_no as student_id, s.student_name as student_name, s.department as dept, s.batch as batch, s.register_no as register_no, 'fresher' as type,
         {$select_class}, {$select_section}, {$select_mentor}
         FROM students_batch_25_26 s
         LEFT JOIN mentor_mentee m ON (m.Student_ID_No = s.id_no OR m.Student_ID_No = s.register_no)
         WHERE 1=1 {$dept_filter_f}
         ORDER BY s.student_name ASC";
if ($is_sh_hod) {
    $sql2 = "SELECT s.id_no as student_id, s.student_name as student_name, s.department as dept, s.batch as batch, s.register_no as register_no, 'fresher' as type,
             {$select_class}, {$select_section}, {$select_mentor}
             FROM students_batch_25_26 s
             LEFT JOIN mentor_mentee m ON (m.Student_ID_No = s.id_no OR m.Student_ID_No = s.register_no)
             ORDER BY s.student_name ASC";
}
$r2 = $mysqli->query($sql2);
if ($r2) {
    while ($row = $r2->fetch_assoc()) $students[] = $row;
}

$students = array_map(function ($stu) use ($faculty_map) {
    $advisor_id = $stu['class_advisor_id'] ?? '';
    $mentor_id = $stu['mentor_id'] ?? '';
    $stu['advisor_name'] = $advisor_id && isset($faculty_map[$advisor_id]) ? $faculty_map[$advisor_id] : '';
    $stu['mentor_name'] = $mentor_id && isset($faculty_map[$mentor_id]) ? $faculty_map[$mentor_id] : '';
    return $stu;
}, $students);

$total_students = count($students);
$sort_by = $_GET['sort1'] ?? ($_GET['sort'] ?? 'name');
$sort_dir = $_GET['dir1'] ?? ($_GET['dir'] ?? 'asc');
$sort_by2 = $_GET['sort2'] ?? '';
$sort_dir2 = $_GET['dir2'] ?? 'asc';
$limit = (int) ($_GET['limit'] ?? 50);
$limit_options = [25, 50, 100, 200];
if (!in_array($limit, $limit_options, true)) {
    $limit = 50;
}
$sort_map = [
    'name' => 'student_name',
    'id' => 'student_id',
    'reg' => 'register_no',
    'dept' => 'dept',
    'batch' => 'batch',
    'type' => 'type',
    'advisor' => 'advisor_name',
    'mentor' => 'mentor_name',
    'section' => 'section_val'
];
if (!isset($sort_map[$sort_by])) {
    $sort_by = 'name';
}
$sort_field = $sort_map[$sort_by];
$sort_dir = strtolower($sort_dir) === 'desc' ? 'desc' : 'asc';

$sort_field2 = $sort_map[$sort_by2] ?? '';
$sort_dir2 = strtolower($sort_dir2) === 'desc' ? 'desc' : 'asc';

usort($students, function ($a, $b) use ($sort_field, $sort_dir, $sort_field2, $sort_dir2) {
    $va = strtolower((string) ($a[$sort_field] ?? ''));
    $vb = strtolower((string) ($b[$sort_field] ?? ''));
    if ($va !== $vb) {
        $cmp = $va <=> $vb;
        return $sort_dir === 'desc' ? -$cmp : $cmp;
    }
    if ($sort_field2 !== '') {
        $v2a = strtolower((string) ($a[$sort_field2] ?? ''));
        $v2b = strtolower((string) ($b[$sort_field2] ?? ''));
        if ($v2a !== $v2b) {
            $cmp2 = $v2a <=> $v2b;
            return $sort_dir2 === 'desc' ? -$cmp2 : $cmp2;
        }
    }
    return 0;
});

$students = array_slice($students, 0, $limit);

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) include $header_path;
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root { --ig-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); }
    body { font-family: 'Outfit', sans-serif; background: #f8fafc; }
    .wrap { max-width: 1300px; margin: 0 auto; padding: 24px; }
    .card { background: #fff; border-radius: 16px; border: 1px solid #eef2f7; box-shadow: 0 6px 20px rgba(0,0,0,0.04); }
    .header { display:flex; justify-content: space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .badge-role { background: var(--ig-grad); color: #fff; padding: 6px 12px; border-radius: 999px; font-weight: 700; font-size: 0.8rem; }
    .search-box { border: 2px solid #e2e8f0; border-radius: 999px; padding: 10px 18px; width: 100%; }
    .table-wrap { overflow: auto; border-radius: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
    th { position: sticky; top: 0; background: #f8fafc; z-index: 5; text-align: left; }
    .btn-insta { background: var(--ig-grad); color: #fff; border: none; padding: 7px 12px; border-radius: 999px; font-weight: 700; font-size: 0.8rem; }
    .btn-ghost { border: 1px solid #e2e8f0; background: #fff; padding: 7px 12px; border-radius: 999px; font-size: 0.8rem; }
    .select { border: 1px solid #e2e8f0; border-radius: 10px; padding: 6px 10px; width: 100%; }
    .input { border: 1px solid #e2e8f0; border-radius: 10px; padding: 6px 10px; width: 100%; }
    .muted { color: #64748b; font-size: 0.8rem; }
    .hint { font-size: 0.75rem; color: #94a3b8; }
    .alert { padding: 10px 14px; border-radius: 10px; margin: 12px 0; }
    .alert-success { background: #dcfce7; color: #166534; }
    .alert-danger { background: #fee2e2; color: #991b1b; }
    .nowrap { white-space: nowrap; }
</style>

<div class="wrap">
    <div class="card p-4 mb-4">
        <div class="header">
            <div>
                <h3 class="fw-bold mb-1">Class Advisor & Section Manager</h3>
                <div class="muted">Assign Class Advisor and Section in mentor_mentee</div>
            </div>
            <span class="badge-role"><?= strtoupper($role) ?> MODE</span>
        </div>

        <?php if($class_advisor_col === ''): ?>
            <div class="alert alert-danger">Missing class advisor column in mentor_mentee. Please add ClassAdvisorID.</div>
        <?php endif; ?>
        <?php if($section_col === ''): ?>
            <div class="alert alert-danger">Section column not found in mentor_mentee. Section updates will not be saved.</div>
        <?php endif; ?>

        <?php if($message): ?>
            <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-danger' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="d-flex gap-3 flex-wrap align-items-center">
            <div style="flex:1; min-width:260px;">
                <input type="text" id="searchInput" class="search-box" placeholder="Search student ID, name, dept...">
            </div>
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                <?php if($role === 'admin'): ?>
                <div style="min-width:200px;">
                    <select name="dept" class="select">
                        <option value="all">All Departments</option>
                        <?php foreach($dept_list as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $filter_dept === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="dept" value="<?= htmlspecialchars($filter_dept) ?>">
                <?php endif; ?>
                <select name="sort1" class="select">
                    <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>Sort: Name</option>
                    <option value="id" <?= $sort_by === 'id' ? 'selected' : '' ?>>Sort: Student ID</option>
                    <option value="reg" <?= $sort_by === 'reg' ? 'selected' : '' ?>>Sort: Register No</option>
                    <option value="dept" <?= $sort_by === 'dept' ? 'selected' : '' ?>>Sort: Dept</option>
                    <option value="batch" <?= $sort_by === 'batch' ? 'selected' : '' ?>>Sort: Batch</option>
                    <option value="type" <?= $sort_by === 'type' ? 'selected' : '' ?>>Sort: Type</option>
                    <option value="advisor" <?= $sort_by === 'advisor' ? 'selected' : '' ?>>Sort: Class Advisor</option>
                    <option value="mentor" <?= $sort_by === 'mentor' ? 'selected' : '' ?>>Sort: Mentor</option>
                    <option value="section" <?= $sort_by === 'section' ? 'selected' : '' ?>>Sort: Section</option>
                </select>
                <select name="dir1" class="select">
                    <option value="asc" <?= $sort_dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                    <option value="desc" <?= $sort_dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                </select>
                <select name="sort2" class="select">
                    <option value="" <?= $sort_by2 === '' ? 'selected' : '' ?>>Then by: None</option>
                    <option value="name" <?= $sort_by2 === 'name' ? 'selected' : '' ?>>Then by: Name</option>
                    <option value="id" <?= $sort_by2 === 'id' ? 'selected' : '' ?>>Then by: Student ID</option>
                    <option value="reg" <?= $sort_by2 === 'reg' ? 'selected' : '' ?>>Then by: Register No</option>
                    <option value="dept" <?= $sort_by2 === 'dept' ? 'selected' : '' ?>>Then by: Dept</option>
                    <option value="batch" <?= $sort_by2 === 'batch' ? 'selected' : '' ?>>Then by: Batch</option>
                    <option value="type" <?= $sort_by2 === 'type' ? 'selected' : '' ?>>Then by: Type</option>
                    <option value="advisor" <?= $sort_by2 === 'advisor' ? 'selected' : '' ?>>Then by: Class Advisor</option>
                    <option value="mentor" <?= $sort_by2 === 'mentor' ? 'selected' : '' ?>>Then by: Mentor</option>
                    <option value="section" <?= $sort_by2 === 'section' ? 'selected' : '' ?>>Then by: Section</option>
                </select>
                <select name="dir2" class="select">
                    <option value="asc" <?= $sort_dir2 === 'asc' ? 'selected' : '' ?>>Asc</option>
                    <option value="desc" <?= $sort_dir2 === 'desc' ? 'selected' : '' ?>>Desc</option>
                </select>
                <select name="limit" class="select">
                    <?php foreach($limit_options as $opt): ?>
                        <option value="<?= $opt ?>" <?= $limit === $opt ? 'selected' : '' ?>>Limit <?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-ghost">Apply</button>
                <a class="btn-ghost" href="class_advisor_manager.php?dept=<?= htmlspecialchars($filter_dept) ?>&limit=<?= (int) $limit ?>">Clear Sort</a>
            </form>
        </div>
        <div class="muted mt-2">Showing <?= count($students) ?> of <?= $total_students ?> students</div>
    </div>

    <div class="card p-3">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Dept</th>
                    <th>Batch</th>
                    <th>Mentor</th>
                    <th>Class Advisor</th>
                    <th>Current Section</th>
                    <th>Assign Advisor</th>
                    <th>Section</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody id="studentTable">
                <?php foreach($students as $stu): ?>
                    <?php
                        $advisor_id = $stu['class_advisor_id'] ?? '';
                        $advisor_name = $advisor_id && isset($faculty_map[$advisor_id]) ? $faculty_map[$advisor_id] : '-';
                        $mentor_id = $stu['mentor_id'] ?? '';
                        $mentor_name = $mentor_id && isset($faculty_map[$mentor_id]) ? $faculty_map[$mentor_id] : '-';
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($stu['student_name']) ?></div>
                            <div class="muted"><?= htmlspecialchars($stu['student_id']) ?><?= $stu['register_no'] ? ' • ' . htmlspecialchars($stu['register_no']) : '' ?></div>
                        </td>
                        <td><?= htmlspecialchars($stu['dept']) ?></td>
                        <td><?= htmlspecialchars($stu['batch']) ?></td>
                        <td><?= htmlspecialchars($mentor_name) ?></td>
                        <td>
                            <?php if($advisor_id): ?>
                                <?= htmlspecialchars($advisor_name) ?>
                            <?php else: ?>
                                <div class="text-danger fw-bold">Not Assigned</div>
                                <div class="muted">Add using ID/Name below</div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($stu['section_val'] ?? '-') ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="update_assignment" value="1">
                                <input type="hidden" name="_csrf" value="<?= vh_e($csrf_token) ?>">
                                <input type="hidden" name="student_id" value="<?= htmlspecialchars($stu['student_id']) ?>">
                                <input type="hidden" name="student_type" value="<?= htmlspecialchars($stu['type']) ?>">
                                <select name="class_advisor_id" class="select" required>
                                    <option value="">Select Advisor</option>
                                    <?php foreach($faculty as $fac): ?>
                                        <option value="<?= htmlspecialchars($fac['ID_NO']) ?>" <?= $advisor_id === $fac['ID_NO'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($fac['NAME']) ?> (<?= htmlspecialchars($fac['ID_NO']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">Pick by ID/Name</div>
                        </td>
                        <td>
                            <input type="text" name="section_value" class="input" value="<?= htmlspecialchars($stu['section_val'] ?? '') ?>" placeholder="Section">
                        </td>
                        <td class="nowrap">
                                <button type="submit" name="action_type" value="save" class="btn-insta">Save</button>
                                <button type="submit" name="action_type" value="remove" class="btn-ghost">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase();
        document.querySelectorAll('#studentTable tr').forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(term) ? '' : 'none';
        });
    });
</script>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) include $footer_path;
?>
