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

$year_pursuing = $request['year_pursuing'] ?: calculate_year_pursuing($request['batch']);
$is_female = stripos($request['gender'], 'F') === 0;
$student_prefix = $is_female ? 'Ms.' : 'Mr.';
$parent_prefix = $request['parent_prefix'] ?? 'Mr.';
$relationship = $is_female ? 'Daughter of' : 'Son of';
$pronoun_subject = $is_female ? 'She' : 'He';
$pronoun_object = $is_female ? 'her' : 'him';

$facility_option = $request['facility_option'] ?? 'None';
$fee_placeholder = '<span class="text-muted">To be entered by admin</span>';
if ($facility_option === 'Transport') {
    $facility_fee = $request['bus_fee'] ?? 0;
} else {
    $facility_fee = $request['hostel_fee'] ?? 0;
}
$facility_label = $facility_option . ' Fee';
if ($facility_option === 'Transport') {
    $bus_zone = $request['bus_zone'] ?? '';
    if ($bus_zone !== '' && $bus_zone !== 'None') {
        $facility_label = 'Transport Fee (' . ($bus_zone === 'AC' ? 'AC Bus' : $bus_zone) . ')';
    } else {
        $facility_label = 'Transport Fee';
    }
}

function build_fee_row($label, $value, $placeholder) {
    $display = ($value && (float)$value > 0) ? number_format((float)$value, 2) : $placeholder;
    return "<tr><td>{$label}</td><td class=\"amount\">{$display}</td></tr>";
}

$content = '';
$type = $request['bonafide_type'];
$purpose = $request['purpose'];

if ($type === 'General') {
    $content = "<p>This is to certify that <strong>{$student_prefix} {$request['student_name']}</strong> (Register No: <strong>{$request['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$request['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$year_pursuing}</strong> Year B.E / B.Tech in <strong>{$request['department']}</strong> during the academic year " . current_academic_year() . ".</p>
    <p>{$pronoun_subject} bears a good moral character and conduct.</p>
    <p>This certificate is issued on the request of the student for the purpose of <strong>{$purpose}</strong>.</p>";
} elseif ($type === 'Fee Structure') {
    $rows = '';
    $rows .= build_fee_row('Tuition Fee', $request['tuition_fee'], $fee_placeholder);
    $rows .= build_fee_row('Other Fee', $request['other_fee'], $fee_placeholder);
    if ($facility_option !== 'None') {
        $rows .= build_fee_row($facility_label, $facility_fee, $fee_placeholder);
    }
    $rows .= "<tr class=\"total-row\"><td><strong>Total</strong></td><td class=\"amount\"><strong>{$fee_placeholder}</strong></td></tr>";

    $content = "<p>This is to certify that <strong>{$student_prefix} {$request['student_name']}</strong> (Register No: <strong>{$request['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$request['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$year_pursuing}</strong> Year {$request['department']}.</p>
    <p><strong>FEE STRUCTURE (Admin will fill final values)</strong></p>
    <table class=\"fee-table\">{$rows}</table>
    <p>This certificate is issued for the purpose of <strong>{$purpose}</strong>.</p>";
} elseif ($type === 'Fee Paid') {
    $rows = '';
    $rows .= build_fee_row('Tuition Fee', $request['tuition_fee'], $fee_placeholder);
    $rows .= build_fee_row('Other Fee', $request['other_fee'], $fee_placeholder);
    if ($facility_option !== 'None') {
        $rows .= build_fee_row($facility_label, $facility_fee, $fee_placeholder);
    }
    $rows .= "<tr class=\"total-row\"><td><strong>Total</strong></td><td class=\"amount\"><strong>{$fee_placeholder}</strong></td></tr>";

    $paid_rows = '';
    $paid_rows .= build_fee_row('Tuition Fee Paid', $request['paid_tuition_fee'], $fee_placeholder);
    $paid_rows .= build_fee_row('Other Fee Paid', $request['paid_other_fee'], $fee_placeholder);
    if ($facility_option !== 'None') {
        $paid_rows .= build_fee_row($facility_label . ' Paid', $request['paid_hostel_fee'], $fee_placeholder);
    }
    $paid_rows .= "<tr class=\"total-row\"><td><strong>Total Paid</strong></td><td class=\"amount\"><strong>{$fee_placeholder}</strong></td></tr>";

    $content = "<p>This is to certify that <strong>{$student_prefix} {$request['student_name']}</strong> (Register No: <strong>{$request['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$request['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$year_pursuing}</strong> Year {$request['department']}.</p>
    <p><strong>FEE STRUCTURE</strong></p>
    <table class=\"fee-table\">{$rows}</table>
    <p><strong>FEE PAID DETAILS (Admin Entry)</strong></p>
    <table class=\"fee-table\">{$paid_rows}</table>
    <p>This certificate is issued for the purpose of <strong>{$purpose}</strong>.</p>";
} elseif ($type === 'Internship') {
    $content = "<p>This is to certify that <strong>{$student_prefix} {$request['student_name']}</strong> (Register No: <strong>{$request['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$request['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$year_pursuing}</strong> Year {$request['department']} during the academic year " . current_academic_year() . ".</p>
    <p>{$pronoun_subject} has been permitted to undertake an internship at your esteemed organization.</p>
    <p>This certificate is issued for the purpose of <strong>{$purpose}</strong>.</p>";
} elseif ($type === 'Project') {
    $content = "<p>This is to certify that <strong>{$student_prefix} {$request['student_name']}</strong> (Register No: <strong>{$request['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$request['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$year_pursuing}</strong> Year {$request['department']} during the academic year " . current_academic_year() . ".</p>
    <p>We hereby grant permission to undertake project work at your esteemed organization.</p>
    <p>This certificate is issued for the purpose of <strong>{$purpose}</strong>.</p>";
} else {
    $content = "<p>Preview not available for this certificate type.</p>";
}
?>

<div class="certificate-preview" style="transform: scale(0.9); transform-origin: top center; margin: 0 auto;">
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
        <div class="ref-number">Ref/VH/BONA/<?= current_academic_year() ?>/001</div>
        <div class="certificate-date">Date: Not Approved</div>
    </div>

    <div class="cert-content">
        <div class="cert-title"><?= htmlspecialchars($type) ?> CERTIFICATE</div>
        <?= $content ?>

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
