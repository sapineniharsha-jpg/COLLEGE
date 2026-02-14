<?php
// analytics.php
// Principal/Admin e-governance analytics dashboard.

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
        $safeT = $mysqli->real_escape_string($table);
        $safeC = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM `{$safeT}` LIKE '{$safeC}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('first_existing_column')) {
    function first_existing_column(mysqli $mysqli, string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (table_has_column($mysqli, $table, $c)) {
                return $c;
            }
        }
        return null;
    }
}
if (!function_exists('safe_date')) {
    function safe_date($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}

$user_role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$allowed_roles = ['admin', 'principal'];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(404);
    echo '404 Not Found';
    exit();
}

if (!function_exists('sql_row')) {
    function sql_row(mysqli $mysqli, string $sql, string $types = '', array $vals = []): array
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '' && !empty($vals)) {
            vh_bind_params($stmt, $types, $vals);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? ($res->fetch_assoc() ?: []) : [];
    }
}
if (!function_exists('sql_rows')) {
    function sql_rows(mysqli $mysqli, string $sql, string $types = '', array $vals = []): array
    {
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '' && !empty($vals)) {
            vh_bind_params($stmt, $types, $vals);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($res && $row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        return $out;
    }
}
if (!function_exists('build_date_condition')) {
    function build_date_condition(string $columnExpr, string $from, string $to, string &$types, array &$vals): string
    {
        $sql = '';
        if ($from !== '') {
            $sql .= " AND DATE({$columnExpr}) >= ?";
            $types .= 's';
            $vals[] = $from;
        }
        if ($to !== '') {
            $sql .= " AND DATE({$columnExpr}) <= ?";
            $types .= 's';
            $vals[] = $to;
        }
        return $sql;
    }
}
if (!function_exists('department_options')) {
    function department_options(mysqli $mysqli): array
    {
        $all = [];
        $sources = [
            ['students_login_master', ['Dept', 'department']],
            ['students_batch_25_26', ['department', 'Dept']],
            ['attendance_logs', ['department', 'dept']],
            ['topic_coverage', ['department']],
            ['counseling_records', ['department']],
            ['bonafide_requests', ['department']],
        ];
        foreach ($sources as $src) {
            [$table, $cands] = $src;
            if (!table_exists($mysqli, $table)) {
                continue;
            }
            $col = first_existing_column($mysqli, $table, $cands);
            if (!$col) {
                continue;
            }
            $rows = $mysqli->query("SELECT DISTINCT `{$col}` AS d FROM `{$table}` WHERE `{$col}` IS NOT NULL AND TRIM(`{$col}`) <> '' ORDER BY `{$col}` ASC");
            while ($rows && $r = $rows->fetch_assoc()) {
                $d = trim((string) ($r['d'] ?? ''));
                if ($d !== '') {
                    $all[$d] = true;
                }
            }
        }
        $list = array_keys($all);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        return $list;
    }
}

if (!function_exists('fetch_analytics_payload')) {
    function fetch_analytics_payload(mysqli $mysqli, string $fromDate, string $toDate, string $deptFilter): array
    {
        $metrics = [
            'attendance_percent' => 0,
            'attendance_entries' => 0,
            'attendance_sessions' => 0,
            'topic_count' => 0,
            'topic_with_files' => 0,
            'circular_total' => 0,
            'circular_sent' => 0,
            'circular_published' => 0,
            'counseling_sessions' => 0,
            'counseling_students' => 0,
            'students_total' => 0,
            'students_not_counseled' => 0,
            'bonafide_requests' => 0,
            'bonafide_approved' => 0,
            'bonafide_certificates' => 0,
        ];
        $attendanceTrend = [];
        $topicTrend = [];
        $pendingCounseling = [];
        $recentTopics = [];
        $recentBonafide = [];

        // Attendance metrics + trend
        if (table_exists($mysqli, 'attendance_logs')) {
            $types = '';
            $vals = [];
            $sql = "SELECT
                        COUNT(*) AS total_entries,
                        SUM(CASE WHEN status IN ('P','OD') THEN 1 ELSE 0 END) AS present_entries,
                        COUNT(DISTINCT student_id) AS students,
                        COUNT(DISTINCT CONCAT(date,'#',hour,'#',subject_code,'#',COALESCE(faculty_id,''))) AS sessions
                    FROM attendance_logs
                    WHERE 1=1";
            $sql .= build_date_condition('date', $fromDate, $toDate, $types, $vals);
            $deptCol = first_existing_column($mysqli, 'attendance_logs', ['department', 'dept']);
            if ($deptFilter !== '' && $deptCol) {
                $sql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $row = sql_row($mysqli, $sql, $types, $vals);
            $total = (int) ($row['total_entries'] ?? 0);
            $present = (int) ($row['present_entries'] ?? 0);
            $metrics['attendance_entries'] = $total;
            $metrics['attendance_sessions'] = (int) ($row['sessions'] ?? 0);
            $metrics['attendance_percent'] = $total > 0 ? round(($present * 100) / $total, 2) : 0;

            $types = '';
            $vals = [];
            $trendSql = "SELECT date AS d, COUNT(DISTINCT CONCAT(date,'#',hour,'#',subject_code,'#',COALESCE(faculty_id,''))) AS c
                         FROM attendance_logs
                         WHERE 1=1";
            $trendSql .= build_date_condition('date', $fromDate, $toDate, $types, $vals);
            if ($deptFilter !== '' && $deptCol) {
                $trendSql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $trendSql .= " GROUP BY date ORDER BY date ASC LIMIT 31";
            $attendanceTrend = sql_rows($mysqli, $trendSql, $types, $vals);
        }

        // Topic coverage metrics + trend + recent
        if (table_exists($mysqli, 'topic_coverage')) {
            $types = '';
            $vals = [];
            $dateCol = first_existing_column($mysqli, 'topic_coverage', ['date', 'created_at']);
            $deptCol = first_existing_column($mysqli, 'topic_coverage', ['department']);

            $sql = "SELECT COUNT(*) AS topics,
                           SUM(CASE WHEN file_path IS NOT NULL AND TRIM(file_path) <> '' THEN 1 ELSE 0 END) AS with_files
                    FROM topic_coverage
                    WHERE 1=1";
            if ($dateCol) {
                $sql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $sql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $row = sql_row($mysqli, $sql, $types, $vals);
            $metrics['topic_count'] = (int) ($row['topics'] ?? 0);
            $metrics['topic_with_files'] = (int) ($row['with_files'] ?? 0);

            if ($dateCol) {
                $types = '';
                $vals = [];
                $tsql = "SELECT DATE({$dateCol}) AS d, COUNT(*) AS c
                         FROM topic_coverage
                         WHERE 1=1";
                $tsql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
                if ($deptFilter !== '' && $deptCol) {
                    $tsql .= " AND `{$deptCol}` = ?";
                    $types .= 's';
                    $vals[] = $deptFilter;
                }
                $tsql .= " GROUP BY DATE({$dateCol}) ORDER BY DATE({$dateCol}) ASC LIMIT 31";
                $topicTrend = sql_rows($mysqli, $tsql, $types, $vals);
            }

            $types = '';
            $vals = [];
            $selectDateExpr = $dateCol ? "DATE({$dateCol}) AS date" : "'-' AS date";
            $rsql = "SELECT {$selectDateExpr}, hour, subject_code, topic_title, faculty_name, file_name
                     FROM topic_coverage WHERE 1=1";
            if ($dateCol) {
                $rsql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $rsql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $rsql .= " ORDER BY date DESC, hour DESC LIMIT 10";
            $recentTopics = sql_rows($mysqli, $rsql, $types, $vals);
        }

        // Circular metrics
        if (table_exists($mysqli, 'circulars')) {
            $types = '';
            $vals = [];
            $dateCol = first_existing_column($mysqli, 'circulars', ['circular_date', 'created_at', 'updated_at']);
            $deptCol = first_existing_column($mysqli, 'circulars', ['department']);

            $sql = "SELECT COUNT(*) AS total,
                           SUM(CASE WHEN LOWER(COALESCE(status,''))='sent' THEN 1 ELSE 0 END) AS sent_count,
                           SUM(CASE WHEN LOWER(COALESCE(status,''))='published' THEN 1 ELSE 0 END) AS published_count
                    FROM circulars WHERE 1=1";
            if ($dateCol) {
                $sql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $sql .= " AND (`{$deptCol}` = ? OR `{$deptCol}`='All')";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $row = sql_row($mysqli, $sql, $types, $vals);
            $metrics['circular_total'] = (int) ($row['total'] ?? 0);
            $metrics['circular_sent'] = (int) ($row['sent_count'] ?? 0);
            $metrics['circular_published'] = (int) ($row['published_count'] ?? 0);
        }

        // Counseling metrics
        if (table_exists($mysqli, 'counseling_records')) {
            $types = '';
            $vals = [];
            $dateCol = first_existing_column($mysqli, 'counseling_records', ['session_date', 'created_at']);
            $deptCol = first_existing_column($mysqli, 'counseling_records', ['department']);
            $sql = "SELECT COUNT(*) AS sessions, COUNT(DISTINCT student_id) AS students FROM counseling_records WHERE 1=1";
            if ($dateCol) {
                $sql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $sql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $row = sql_row($mysqli, $sql, $types, $vals);
            $metrics['counseling_sessions'] = (int) ($row['sessions'] ?? 0);
            $metrics['counseling_students'] = (int) ($row['students'] ?? 0);
        }

        // Student population + not counseled list
        if (table_exists($mysqli, 'students_login_master')) {
            $sidCol = first_existing_column($mysqli, 'students_login_master', ['IDNo', 'id_no']);
            $snameCol = first_existing_column($mysqli, 'students_login_master', ['Name', 'student_name']);
            $sdeptCol = first_existing_column($mysqli, 'students_login_master', ['Dept', 'department']);
            if ($sidCol && $snameCol && $sdeptCol) {
                $types = '';
                $vals = [];
                $sql = "SELECT COUNT(*) AS c FROM students_login_master WHERE 1=1";
                if ($deptFilter !== '') {
                    $sql .= " AND `{$sdeptCol}` = ?";
                    $types .= 's';
                    $vals[] = $deptFilter;
                }
                if (table_has_column($mysqli, 'students_login_master', 'current_status')) {
                    $sql .= " AND (current_status = 'Active' OR current_status IS NULL OR current_status = '')";
                }
                $row = sql_row($mysqli, $sql, $types, $vals);
                $metrics['students_total'] = (int) ($row['c'] ?? 0);

                if (table_exists($mysqli, 'counseling_records')) {
                    $types = '';
                    $vals = [];
                    $joinFilters = '';
                    if (first_existing_column($mysqli, 'counseling_records', ['session_date', 'created_at'])) {
                        $dcol = first_existing_column($mysqli, 'counseling_records', ['session_date', 'created_at']);
                        $joinFilters .= build_date_condition("c.{$dcol}", $fromDate, $toDate, $types, $vals);
                    }
                    if ($deptFilter !== '' && table_has_column($mysqli, 'counseling_records', 'department')) {
                        $joinFilters .= " AND c.department = ?";
                        $types .= 's';
                        $vals[] = $deptFilter;
                    }

                    $sql = "SELECT COUNT(*) AS c
                            FROM students_login_master s
                            LEFT JOIN (
                                SELECT DISTINCT student_id FROM counseling_records c WHERE 1=1 {$joinFilters}
                            ) x ON x.student_id = s.`{$sidCol}`
                            WHERE x.student_id IS NULL";
                    if ($deptFilter !== '') {
                        $sql .= " AND s.`{$sdeptCol}` = ?";
                        $types .= 's';
                        $vals[] = $deptFilter;
                    }
                    if (table_has_column($mysqli, 'students_login_master', 'current_status')) {
                        $sql .= " AND (s.current_status = 'Active' OR s.current_status IS NULL OR s.current_status = '')";
                    }
                    $row = sql_row($mysqli, $sql, $types, $vals);
                    $metrics['students_not_counseled'] = (int) ($row['c'] ?? 0);

                    $types = '';
                    $vals = [];
                    $joinFilters = '';
                    if (first_existing_column($mysqli, 'counseling_records', ['session_date', 'created_at'])) {
                        $dcol = first_existing_column($mysqli, 'counseling_records', ['session_date', 'created_at']);
                        $joinFilters .= build_date_condition("c.{$dcol}", $fromDate, $toDate, $types, $vals);
                    }
                    if ($deptFilter !== '' && table_has_column($mysqli, 'counseling_records', 'department')) {
                        $joinFilters .= " AND c.department = ?";
                        $types .= 's';
                        $vals[] = $deptFilter;
                    }
                    $sql = "SELECT s.`{$sidCol}` AS student_id, s.`{$snameCol}` AS student_name, s.`{$sdeptCol}` AS department
                            FROM students_login_master s
                            LEFT JOIN (
                                SELECT DISTINCT student_id FROM counseling_records c WHERE 1=1 {$joinFilters}
                            ) x ON x.student_id = s.`{$sidCol}`
                            WHERE x.student_id IS NULL";
                    if ($deptFilter !== '') {
                        $sql .= " AND s.`{$sdeptCol}` = ?";
                        $types .= 's';
                        $vals[] = $deptFilter;
                    }
                    if (table_has_column($mysqli, 'students_login_master', 'current_status')) {
                        $sql .= " AND (s.current_status = 'Active' OR s.current_status IS NULL OR s.current_status = '')";
                    }
                    $sql .= " ORDER BY s.`{$sidCol}` ASC LIMIT 12";
                    $pendingCounseling = sql_rows($mysqli, $sql, $types, $vals);
                }
            }
        }

        // Bonafide metrics + recent
        if (table_exists($mysqli, 'bonafide_requests')) {
            $types = '';
            $vals = [];
            $dateCol = first_existing_column($mysqli, 'bonafide_requests', ['request_date', 'RequestDate', 'created_at', 'submitted_on']);
            $deptCol = first_existing_column($mysqli, 'bonafide_requests', ['department']);
            $hasFinalApproved = table_has_column($mysqli, 'bonafide_requests', 'final_approved');

            $approvedExpr = $hasFinalApproved
                ? "SUM(CASE WHEN final_approved = 1 OR LOWER(COALESCE(status,''))='approved' THEN 1 ELSE 0 END)"
                : "SUM(CASE WHEN LOWER(COALESCE(status,''))='approved' THEN 1 ELSE 0 END)";
            $sql = "SELECT COUNT(*) AS total,
                           {$approvedExpr} AS approved_count
                    FROM bonafide_requests WHERE 1=1";
            if ($dateCol) {
                $sql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $sql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $row = sql_row($mysqli, $sql, $types, $vals);
            $metrics['bonafide_requests'] = (int) ($row['total'] ?? 0);
            $metrics['bonafide_approved'] = (int) ($row['approved_count'] ?? 0);

            $types = '';
            $vals = [];
            $rsql = "SELECT student_name, register_number, department, status
                     FROM bonafide_requests WHERE 1=1";
            if ($dateCol) {
                $rsql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $rsql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $rsql .= " ORDER BY id DESC LIMIT 10";
            $recentBonafide = sql_rows($mysqli, $rsql, $types, $vals);
        }
        if (table_exists($mysqli, 'bonafide_certificates')) {
            $types = '';
            $vals = [];
            $dateCol = first_existing_column($mysqli, 'bonafide_certificates', ['bonafide_date', 'created_at']);
            $deptCol = first_existing_column($mysqli, 'bonafide_certificates', ['department']);
            $sql = "SELECT COUNT(*) AS c FROM bonafide_certificates WHERE 1=1";
            if ($dateCol) {
                $sql .= build_date_condition($dateCol, $fromDate, $toDate, $types, $vals);
            }
            if ($deptFilter !== '' && $deptCol) {
                $sql .= " AND `{$deptCol}` = ?";
                $types .= 's';
                $vals[] = $deptFilter;
            }
            $row = sql_row($mysqli, $sql, $types, $vals);
            $metrics['bonafide_certificates'] = (int) ($row['c'] ?? 0);
        }

        return [
            'metrics' => $metrics,
            'attendance_trend' => $attendanceTrend,
            'topic_trend' => $topicTrend,
            'pending_counseling' => $pendingCounseling,
            'recent_topics' => $recentTopics,
            'recent_bonafide' => $recentBonafide,
        ];
    }
}

if (isset($_POST['action'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(true);
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'fetch_analytics') {
            throw new Exception('Invalid action.');
        }
        $fromDate = safe_date($_POST['from_date'] ?? '');
        $toDate = safe_date($_POST['to_date'] ?? '');
        $dept = trim((string) ($_POST['department'] ?? ''));
        if (strtolower($dept) === 'all') {
            $dept = '';
        }
        $payload = fetch_analytics_payload($mysqli, $fromDate, $toDate, $dept);
        echo json_encode(['status' => 'success', 'data' => $payload]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$dept_options = department_options($mysqli);
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    :root {
        --inst-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
        --inst-shadow: 0 12px 28px rgba(188, 24, 136, 0.2);
    }
    body { background: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif; }
    .an-wrap { max-width: 1380px; margin: 22px auto; padding: 0 14px 40px; }
    .hero {
        background: #fff; border: 1px solid #eef2f7; border-radius: 22px; padding: 20px; margin-bottom: 16px;
        box-shadow: 0 8px 22px rgba(15,23,42,.06);
    }
    .title {
        margin: 0; font-size: 1.7rem; font-weight: 800;
        background: var(--inst-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .subtitle { color: #64748b; font-weight: 500; margin-top: 6px; }
    .filter-box {
        background: #fff; border: 1px solid #eef2f7; border-radius: 18px; padding: 16px; margin-bottom: 16px;
        box-shadow: 0 8px 20px rgba(15,23,42,.04);
    }
    .lbl { font-size: .73rem; font-weight: 700; text-transform: uppercase; color:#64748b; letter-spacing:.4px; margin-bottom:6px; display:block; }
    .btn-inst {
        background: var(--inst-grad); color: #fff; border: none; border-radius: 12px; font-weight: 700;
        padding: 10px 16px; box-shadow: var(--inst-shadow); transition: .2s;
    }
    .btn-inst:hover { transform: translateY(-2px); color:#fff; }
    .card-kpi {
        background: #fff; border: 1px solid #eef2f7; border-radius: 18px; padding: 14px; height: 100%;
        box-shadow: 0 8px 20px rgba(15,23,42,.04); transition: .2s;
    }
    .card-kpi:hover { transform: translateY(-3px); box-shadow: 0 14px 26px rgba(15,23,42,.08); }
    .kpi-tag { font-size: .75rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
    .kpi-val { font-size: 1.6rem; font-weight: 800; margin-top: 3px; color: #0f172a; }
    .kpi-sub { font-size: .82rem; color: #6b7280; }
    .sec-card {
        background:#fff; border: 1px solid #eef2f7; border-radius: 18px; padding: 16px; margin-top: 16px;
        box-shadow: 0 8px 20px rgba(15,23,42,.04);
    }
    .sec-title { margin: 0 0 10px; font-weight: 800; font-size: 1rem; color:#111827; }
    .tbl td, .tbl th { font-size: .86rem; }
    .loader {
        display:none; margin-left: 8px; width:16px; height:16px; border:2px solid #fff; border-top-color: transparent; border-radius:50%; animation:spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="an-wrap">
    <div class="hero">
        <h1 class="title">E-Governance Analytics</h1>
        <div class="subtitle">Principal/Admin institutional insights: attendance, topic coverage, circulars, counseling and bonafide analytics.</div>
    </div>

    <div class="filter-box">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="lbl">From Date</label>
                <input type="date" id="fromDate" class="form-control" value="<?= vh_e(date('Y-m-01')) ?>">
            </div>
            <div class="col-md-3">
                <label class="lbl">To Date</label>
                <input type="date" id="toDate" class="form-control" value="<?= vh_e(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-4">
                <label class="lbl">Department</label>
                <select id="deptSel" class="form-select">
                    <option value="all">All Departments</option>
                    <?php foreach ($dept_options as $d): ?>
                        <option value="<?= vh_e($d) ?>"><?= vh_e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn-inst" id="btnLoad">
                    Load Analytics <span class="loader" id="loadSpin"></span>
                </button>
            </div>
        </div>
    </div>

    <div class="row g-3" id="kpiRow">
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Student Attendance %</div><div class="kpi-val" id="kAttendancePct">0%</div><div class="kpi-sub" id="kAttendanceSub">0 entries</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Topics Covered</div><div class="kpi-val" id="kTopics">0</div><div class="kpi-sub" id="kTopicsSub">0 with files</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Circulars</div><div class="kpi-val" id="kCirculars">0</div><div class="kpi-sub" id="kCircularsSub">0 sent</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Counseling Coverage</div><div class="kpi-val" id="kCounseling">0</div><div class="kpi-sub" id="kCounselingSub">0 students met</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Student Population</div><div class="kpi-val" id="kStudents">0</div><div class="kpi-sub" id="kStudentsSub">0 pending counseling</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Bonafide Requests</div><div class="kpi-val" id="kBonafideReq">0</div><div class="kpi-sub" id="kBonafideReqSub">0 approved</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Bonafide Issued</div><div class="kpi-val" id="kBonafideCert">0</div><div class="kpi-sub">Certificates generated</div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card-kpi"><div class="kpi-tag">Attendance Sessions</div><div class="kpi-val" id="kSessions">0</div><div class="kpi-sub">Distinct hour slots marked</div></div></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="sec-card">
                <h4 class="sec-title">Attendance Trend (sessions/day)</h4>
                <canvas id="attTrendChart" height="140"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="sec-card">
                <h4 class="sec-title">Topic Coverage Trend (topics/day)</h4>
                <canvas id="topicTrendChart" height="140"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="sec-card">
                <h4 class="sec-title">Students Pending Counseling</h4>
                <div class="table-responsive">
                    <table class="table table-hover tbl">
                        <thead><tr><th>ID</th><th>Name</th><th>Department</th></tr></thead>
                        <tbody id="pendingCounselingBody"><tr><td colspan="3" class="text-muted text-center">No data</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="sec-card">
                <h4 class="sec-title">Recent Topic Coverage</h4>
                <div class="table-responsive">
                    <table class="table table-hover tbl">
                        <thead><tr><th>Date</th><th>Hour</th><th>Subject</th><th>Topic</th><th>Faculty</th><th>File</th></tr></thead>
                        <tbody id="recentTopicsBody"><tr><td colspan="6" class="text-muted text-center">No data</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="sec-card">
                <h4 class="sec-title">Recent Bonafide Requests</h4>
                <div class="table-responsive">
                    <table class="table table-hover tbl">
                        <thead><tr><th>Student</th><th>Register No</th><th>Department</th><th>Status</th></tr></thead>
                        <tbody id="recentBonafideBody"><tr><td colspan="4" class="text-muted text-center">No data</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const API = <?= json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode($csrf_token, JSON_UNESCAPED_SLASHES) ?>;
let attTrendChart = null;
let topicTrendChart = null;

function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
function withCsrf(data) {
    data._csrf = CSRF_TOKEN;
    return data;
}
function showLoader(on) {
    document.getElementById('loadSpin').style.display = on ? 'inline-block' : 'none';
}
function renderTrend(canvasId, prevChart, labels, data, borderColor) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    if (prevChart) prevChart.destroy();
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                borderColor: borderColor,
                backgroundColor: 'rgba(188,24,136,0.10)',
                fill: true,
                tension: 0.35,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function loadAnalytics() {
    showLoader(true);
    $.post(API, withCsrf({
        action: 'fetch_analytics',
        from_date: $('#fromDate').val(),
        to_date: $('#toDate').val(),
        department: $('#deptSel').val()
    }), function(res) {
        showLoader(false);
        if (!res || res.status !== 'success') {
            alert(res && res.message ? res.message : 'Unable to load analytics');
            return;
        }
        const data = res.data || {};
        const m = data.metrics || {};

        setText('kAttendancePct', `${Number(m.attendance_percent || 0).toFixed(2)}%`);
        setText('kAttendanceSub', `${m.attendance_entries || 0} entries`);
        setText('kTopics', m.topic_count || 0);
        setText('kTopicsSub', `${m.topic_with_files || 0} with files`);
        setText('kCirculars', m.circular_total || 0);
        setText('kCircularsSub', `${m.circular_sent || 0} sent`);
        setText('kCounseling', m.counseling_sessions || 0);
        setText('kCounselingSub', `${m.counseling_students || 0} students met`);
        setText('kStudents', m.students_total || 0);
        setText('kStudentsSub', `${m.students_not_counseled || 0} pending counseling`);
        setText('kBonafideReq', m.bonafide_requests || 0);
        setText('kBonafideReqSub', `${m.bonafide_approved || 0} approved`);
        setText('kBonafideCert', m.bonafide_certificates || 0);
        setText('kSessions', m.attendance_sessions || 0);

        const attTrend = Array.isArray(data.attendance_trend) ? data.attendance_trend : [];
        const topicTrend = Array.isArray(data.topic_trend) ? data.topic_trend : [];
        attTrendChart = renderTrend('attTrendChart', attTrendChart, attTrend.map(x => x.d), attTrend.map(x => Number(x.c || 0)), '#bc1888');
        topicTrendChart = renderTrend('topicTrendChart', topicTrendChart, topicTrend.map(x => x.d), topicTrend.map(x => Number(x.c || 0)), '#dc2743');

        const pending = Array.isArray(data.pending_counseling) ? data.pending_counseling : [];
        const pBody = document.getElementById('pendingCounselingBody');
        if (!pending.length) {
            pBody.innerHTML = '<tr><td colspan="3" class="text-muted text-center">No pending student list in selected range.</td></tr>';
        } else {
            pBody.innerHTML = pending.map(r => `<tr><td>${esc(r.student_id)}</td><td>${esc(r.student_name)}</td><td>${esc(r.department || '-')}</td></tr>`).join('');
        }

        const topics = Array.isArray(data.recent_topics) ? data.recent_topics : [];
        const tBody = document.getElementById('recentTopicsBody');
        if (!topics.length) {
            tBody.innerHTML = '<tr><td colspan="6" class="text-muted text-center">No recent topic coverage data.</td></tr>';
        } else {
            tBody.innerHTML = topics.map(r => `<tr>
                <td>${esc(r.date)}</td>
                <td>H${esc(r.hour)}</td>
                <td>${esc(r.subject_code)}</td>
                <td>${esc(r.topic_title)}</td>
                <td>${esc(r.faculty_name || '-')}</td>
                <td>${esc(r.file_name || '-')}</td>
            </tr>`).join('');
        }

        const bons = Array.isArray(data.recent_bonafide) ? data.recent_bonafide : [];
        const bBody = document.getElementById('recentBonafideBody');
        if (!bons.length) {
            bBody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">No bonafide request data in range.</td></tr>';
        } else {
            bBody.innerHTML = bons.map(r => `<tr>
                <td>${esc(r.student_name || '-')}</td>
                <td>${esc(r.register_number || '-')}</td>
                <td>${esc(r.department || '-')}</td>
                <td>${esc(r.status || '-')}</td>
            </tr>`).join('');
        }
    }, 'json').fail(function() {
        showLoader(false);
        alert('Failed to load analytics');
    });
}

$('#btnLoad').on('click', loadAnalytics);
$(document).ready(loadAnalytics);
</script>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
