<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

function session_student_tokens() {
    $tokens = [];
    foreach (['student_id', 'ID_NO', 'id_no', 'user_id', 'register_no', 'register_number', 'RegisterNo'] as $key) {
        $val = trim((string) ($_SESSION[$key] ?? ''));
        if ($val !== '' && !in_array($val, $tokens, true)) {
            $tokens[] = $val;
        }
    }
    return $tokens;
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
$employee_id = $_SESSION['employee_id'] ?? $_SESSION['ID_NO'] ?? $_SESSION['user_id'];
$user_department = $_SESSION['department'] ?? $_SESSION['dept'] ?? '';
$student_id = $_SESSION['student_id'] ?? $_SESSION['ID_NO'] ?? $_SESSION['user_id'];

$cert_id = (int) ($_GET['id'] ?? 0);
if ($cert_id <= 0) {
    echo '<div class="text-danger">Invalid certificate.</div>';
    exit();
}

$conditions = ["c.id = ?"];
$params = [$cert_id];
$types = "i";

if ($user_role === 'student') {
    $student_tokens = session_student_tokens();
    if (empty($student_tokens)) {
        echo '<div class="text-danger">Access denied.</div>';
        exit();
    }
    $placeholders = implode(',', array_fill(0, count($student_tokens), '?'));
    $conditions[] = "(c.student_id IN ({$placeholders}) OR c.register_number IN ({$placeholders}))";
    foreach ($student_tokens as $token) {
        $params[] = $token;
        $types .= "s";
    }
    foreach ($student_tokens as $token) {
        $params[] = $token;
        $types .= "s";
    }
} elseif ($user_role === 'staff') {
    $conditions[] = "c.student_id IN (SELECT Student_ID_No FROM mentor_mentee WHERE Employee_ID_No = ? OR ClassAdvisorID = ?)";
    $params[] = $employee_id;
    $params[] = $employee_id;
    $types .= "ss";
} elseif ($user_role === 'hod' && $user_department !== '') {
    $conditions[] = "c.department = ?";
    $params[] = $user_department;
    $types .= "s";
}

$sql = "SELECT c.* FROM bonafide_certificates c WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    echo '<div class="text-danger">Certificate not found.</div>';
    exit();
}

$cert = $res->fetch_assoc();

function normalize_years($input) {
    $years = [];
    if (is_string($input)) {
        $input = explode(',', $input);
    }
    if (!is_array($input)) {
        return $years;
    }
    foreach ($input as $year) {
        $year = trim($year);
        if ($year === '') continue;
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    return $years;
}

function format_currency($amount) {
    return number_format((float) $amount, 2, '.', ',');
}

function build_fee_table($years, $rows, $total_label = 'TOTAL') {
    $cols = count($years);
    if ($cols === 0) {
        $years = [date('Y') . '-' . (date('Y') + 1)];
        $cols = 1;
    }

    $head = '<tr><th style="width: 40%">PARTICULARS</th>';
    foreach ($years as $year) {
        $head .= "<th style=\"width:" . (60 / $cols) . "%\">{$year}</th>";
    }
    $head .= '</tr>';

    $body = '';
    $total = 0;
    foreach ($rows as $row) {
        $total += (float) $row['amount'];
        $body .= '<tr><td>' . $row['label'] . '</td>';
        foreach ($years as $year) {
            $body .= '<td class="amount">' . format_currency($row['amount']) . '/-</td>';
        }
        $body .= '</tr>';
    }

    $body .= '<tr class="total-row"><td><strong>' . $total_label . '</strong></td>';
    foreach ($years as $year) {
        $body .= '<td class="amount"><strong>' . format_currency($total) . '/-</strong></td>';
    }
    $body .= '</tr>';

    return '<table class="fee-table"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table>';
}

function number_to_words($num) {
    $num = (float) $num;
    if ($num <= 0) {
        return 'Zero Rupees Only';
    }
    $integer = (int) floor($num);
    $decimal = (int) round(($num - $integer) * 100);

    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $convert = function ($n) use ($ones, $tens, &$convert) {
        if ($n === 0) return '';
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[intdiv($n, 100)] . ' Hundred ' . $convert($n % 100);
        if ($n < 100000) return $convert(intdiv($n, 1000)) . ' Thousand ' . $convert($n % 1000);
        if ($n < 10000000) return $convert(intdiv($n, 100000)) . ' Lakh ' . $convert($n % 100000);
        return $convert(intdiv($n, 10000000)) . ' Crore ' . $convert($n % 10000000);
    };

    $words = trim($convert($integer)) . ' Rupees';
    if ($decimal > 0) {
        $words .= ' and ' . trim($convert($decimal)) . ' Paise';
    }
    return trim($words) . ' Only';
}

$years = normalize_years($cert['academic_year']);
$type = $cert['certificate_type'];
$is_female = ($cert['student_prefix'] === 'Ms.');
$student_prefix = $cert['student_prefix'] ?: ($is_female ? 'Ms.' : 'Mr.');
$parent_prefix = $cert['parent_prefix'] ?: 'Mr.';
$relationship = $is_female ? 'Daughter of' : 'Son of';
$pronoun_subject = $is_female ? 'She' : 'He';
$pronoun_object = $is_female ? 'her' : 'him';

$facility_option = $cert['facility_option'] ?? 'None';
$facility_label = '';
$facility_amount = 0;
$facility_paid = 0;

if ($facility_option === 'Hostel') {
    $facility_label = 'Hostel Fee';
    $facility_amount = (float) $cert['hostel_fee'];
    $facility_paid = (float) $cert['paid_hostel_fee'];
} elseif ($facility_option === 'Transport') {
    $bus_zone = $cert['bus_zone'] ?? '';
    $bus_type = $cert['bus_type'] ?? '';
    $bus_detail_parts = [];
    if ($bus_zone && $bus_zone !== 'None') {
        $bus_detail_parts[] = ($bus_zone === 'AC') ? 'AC Bus' : $bus_zone;
    }
    if ($bus_type && $bus_type !== 'None' && stripos((string) $bus_zone, 'AC') === false) {
        $bus_detail_parts[] = $bus_type;
    }
    $bus_detail = trim(implode(' ', $bus_detail_parts));
    $facility_label = 'Transport Fee' . ($bus_detail ? ' (' . $bus_detail . ')' : '');
    $facility_amount = (float) $cert['bus_fee'];
    $facility_paid = (float) $cert['paid_hostel_fee'];
}

$fee_rows = [
    ['label' => 'Tuition Fee', 'amount' => (float) $cert['tuition_fee']],
    ['label' => 'Other Fee', 'amount' => (float) $cert['other_fee']]
];
if ($facility_label && $facility_amount > 0) {
    $fee_rows[] = ['label' => $facility_label, 'amount' => $facility_amount];
}

$paid_rows = [
    ['label' => 'Tuition Fee Paid', 'amount' => (float) $cert['paid_tuition_fee']],
    ['label' => 'Other Fee Paid', 'amount' => (float) $cert['paid_other_fee']]
];
if ($facility_label && $facility_paid > 0) {
    $paid_rows[] = ['label' => $facility_label . ' Paid', 'amount' => $facility_paid];
}

$content = '';
if ($type === 'General' || $type === 'Normal') {
    $content = "<p>This is to certify that <strong>{$student_prefix} {$cert['student_name']}</strong> (Register No: <strong>{$cert['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$cert['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$cert['year_pursuing']}</strong> Year B.E / B.Tech in <strong>{$cert['department']}</strong> during the academic year {$cert['content_academic_year']}.</p>
    <p>{$pronoun_subject} bears a good moral character and conduct.</p>
    <p>This certificate is issued on the request of the student for the purpose of <strong>{$cert['purpose']}</strong>.</p>";
} elseif ($type === 'Fee Structure') {
    $total = (float) $cert['tuition_fee'] + (float) $cert['other_fee'] + (float) $facility_amount;
    $content = "<p>This is to certify that <strong>{$student_prefix} {$cert['student_name']}</strong> (Register No: <strong>{$cert['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$cert['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$cert['year_pursuing']}</strong> Year {$cert['department']}.</p>
    <p><strong>FEE STRUCTURE FOR SELECTED ACADEMIC YEARS</strong></p>
    " . build_fee_table($years, $fee_rows) . "
    <div class=\"amount-words-box\">
        <div class=\"numeric\">Total Amount per annum: Rs. " . format_currency($total) . "</div>
        <div class=\"words\">Amount in Words: " . number_to_words($total) . "</div>
    </div>
    <p>This certificate is issued for the purpose of <strong>{$cert['purpose']}</strong>.</p>";
} elseif ($type === 'Fee Paid') {
    $total = (float) $cert['tuition_fee'] + (float) $cert['other_fee'] + (float) $facility_amount;
    $paid_total = (float) $cert['paid_tuition_fee'] + (float) $cert['paid_other_fee'] + (float) $facility_paid;
    $content = "<p>This is to certify that <strong>{$student_prefix} {$cert['student_name']}</strong> (Register No: <strong>{$cert['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$cert['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$cert['year_pursuing']}</strong> Year {$cert['department']}.</p>
    <p><strong>A. Fee Structure:</strong></p>
    " . build_fee_table($years, $fee_rows) . "
    <div class=\"amount-words-box\">
        <div class=\"numeric\">Total Fee per annum: Rs. " . format_currency($total) . "</div>
        <div class=\"words\">Amount in Words: " . number_to_words($total) . "</div>
    </div>
    <p><strong>B. Fee Paid Details:</strong></p>
    " . build_fee_table($years, $paid_rows, 'TOTAL PAID') . "
    <div class=\"amount-words-box\">
        <div class=\"numeric\">Total Paid per annum: Rs. " . format_currency($paid_total) . "</div>
        <div class=\"words\">Amount in Words: " . number_to_words($paid_total) . "</div>
    </div>
    <p>This certificate is issued for the purpose of <strong>{$cert['purpose']}</strong>.</p>";
} elseif ($type === 'Internship') {
    $content = "<p>This is to certify that <strong>{$student_prefix} {$cert['student_name']}</strong> (Register No: <strong>{$cert['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$cert['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$cert['year_pursuing']}</strong> Year {$cert['department']} during the academic year {$cert['content_academic_year']}.</p>
    <p>{$pronoun_subject} has been permitted to undertake an internship / industrial training at your esteemed organization.</p>
    <p>This certificate is issued for the purpose of <strong>{$cert['purpose']}</strong>.</p>";
} elseif ($type === 'Project') {
    $content = "<p>This is to certify that <strong>{$student_prefix} {$cert['student_name']}</strong> (Register No: <strong>{$cert['register_number']}</strong>),
    {$relationship} <strong>{$parent_prefix} {$cert['father_name']}</strong>, is a bonafide student of our Institution, pursuing
    <strong>{$cert['year_pursuing']}</strong> Year {$cert['department']} during the academic year {$cert['content_academic_year']}.</p>
    <p>We hereby grant permission to undertake project work at your esteemed organization.</p>
    <p>This certificate is issued for the purpose of <strong>{$cert['purpose']}</strong>.</p>";
} else {
    $content = "<p>Certificate content not configured for this type.</p>";
}

$show_bank_note = in_array($type, ['Fee Structure', 'Fee Paid'], true) && (($cert['note_details'] ?? 'No') === 'Yes');
if ($show_bank_note) {
    $content .= '<div style="font-size:10pt; border:1px solid #000; padding:8px; margin-top:12px; background:#f8f9fa;">
        <strong>Note:</strong> Demand Draft in favour of <strong>"The Principal, Vel Tech High Tech Dr. Rangarajan Dr. Sakunthala Engineering College"</strong><br>
        <strong>Account Number:</strong> 75330100003390 | <strong>IFSC:</strong> BARB0VJVELT | <strong>Bank:</strong> Bank of Baroda, Vel Tech Branch
    </div>';
}

$qr_src = '';
$qr_rel_path = '';
if (!empty($cert['qr_code_path'])) {
    $qr_rel_path = ltrim($cert['qr_code_path'], '/');
    $qr_src = '../' . $qr_rel_path;
}

// Ensure QR exists; generate on-demand if missing
$qr_fs_path = '';
if ($qr_rel_path !== '') {
    $qr_fs_path = dirname(__DIR__) . '/' . $qr_rel_path;
}
if ($qr_src === '' || ($qr_fs_path !== '' && !file_exists($qr_fs_path))) {
    $qr_lib = find_include_path($include_paths, 'includes/phpqrcode/qrlib.php');
    if ($qr_lib) {
        require_once $qr_lib;
        $qrData = "https://www.velhightech.com/verify_bonafide.php?ref=" . urlencode($cert['ref_number']);
        $qrDir = dirname(__DIR__) . '/temp';
        if (!is_dir($qrDir)) {
            mkdir($qrDir, 0755, true);
        }
        $qrFileName = 'qr_' . md5($cert['ref_number']) . '.png';
        $qrFilePath = $qrDir . '/' . $qrFileName;
        if (!file_exists($qrFilePath)) {
            QRcode::png($qrData, $qrFilePath, QR_ECLEVEL_L, 3);
        }
        if (file_exists($qrFilePath)) {
            $qr_rel_path = 'temp/' . $qrFileName;
            $qr_src = '../' . $qr_rel_path;
            if (empty($cert['qr_code_path'])) {
                $stmt = $mysqli->prepare("UPDATE bonafide_certificates SET qr_code_path = ?, verification_url = ?, qr_generated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssi", $qr_rel_path, $qrData, $cert_id);
                $stmt->execute();
            }
        }
    }
}

$is_print = isset($_GET['print']);
if ($is_print):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bonafide Certificate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #fff; font-family: 'Times New Roman', serif; }
        .certificate-preview { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 10mm 15mm; }
        .header-container { display: flex; justify-content: space-between; align-items: flex-start; padding: 10px 0; border-bottom: 1px solid #000; margin-bottom: 10px; }
        .logo-container { flex: 0 0 75px; padding: 5px; }
        .college-logo { height: 70px; width: auto; object-fit: contain; }
        .college-info { flex: 1; text-align: center; padding: 0 10px; }
        .college-name { font-size: 32px; font-weight: 900; color: #1a237e; margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.6px; line-height: 1.1; }
        .college-subtitle { font-size: 16px; font-weight: 700; color: #0d47a1; margin-bottom: 3px; line-height: 1.1; }
        .college-details { font-size: 12px; color: #37474f; line-height: 1.3; }
        .college-details span { display: block; margin-bottom: 1px; }
        .college-accreditation { font-size: 10px; font-weight: 600; color: #00695c; margin-top: 2px; line-height: 1.2; }
        .contact-info { flex: 0 0 180px; text-align: right; font-size: 12px; line-height: 1.3; padding: 5px; border-left: 1px solid #e0e0e0; }
        .ref-container { display: flex; justify-content: space-between; align-items: center; margin: 8px 30px 12px; padding-bottom: 5px; border-bottom: 0.5px solid #ddd; }
        .ref-number { font-size: 12px; font-weight: 700; color: #b71c1c; background: #fff3cd; padding: 5px 10px; border-radius: 3px; border-left: 3px solid #b71c1c; display: inline-block; }
        .certificate-date { font-size: 12px; font-weight: 600; color: #1b5e20; background: #e8f5e9; padding: 5px 10px; border-radius: 3px; border-left: 3px solid #1b5e20; margin-left: 10px; display: inline-block; }
        .cert-title { text-align: center; font-size: 18pt; font-weight: bold; text-decoration: underline; margin: 20px 0 15px; text-transform: uppercase; }
        .cert-content { font-size: 12.5pt; line-height: 1.5; text-align: justify; }
        .cert-footer { margin-top: 40px; position: relative; height: 80px; }
        .cert-qr { position: absolute; left: 0; bottom: 0; }
        .cert-sign { position: absolute; right: 50px; bottom: 25px; font-weight: bold; font-size: 13pt; }
        .fee-table { border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 10pt; }
        .fee-table th { background: #f1f5f9; padding: 6px; text-align: center; border: 1px solid #000; font-weight: bold; }
        .fee-table td { padding: 6px; border: 1px solid #000; }
        .fee-table .amount { text-align: right; font-family: 'Courier New', monospace; font-weight: bold; }
        .fee-table .total-row { background: #e7f5ff; font-weight: bold; }
        .amount-words-box { border: 1px solid #000; padding: 10px; margin: 12px 0; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body onload="window.print()">
<?php endif; ?>

<div class="certificate-preview">
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
        <div class="ref-number"><?= htmlspecialchars($cert['ref_number']) ?></div>
        <div class="certificate-date">Date: <?= date('d M Y', strtotime($cert['bonafide_date'])) ?></div>
    </div>

    <div class="cert-content">
        <div class="cert-title"><?= strtoupper(htmlspecialchars($type)) ?> CERTIFICATE</div>
        <?= $content ?>

        <div class="cert-footer">
            <div class="cert-qr">
                <?php if ($qr_src): ?>
                    <img src="<?= htmlspecialchars($qr_src) ?>" width="75" alt="QR Code"><br>SCAN TO VERIFY
                <?php else: ?>
                    <div style="width: 75px; height: 75px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 8px; text-align: center;">
                        QR CODE<br>NOT AVAILABLE
                    </div>
                <?php endif; ?>
            </div>
            <div class="cert-sign">PRINCIPAL</div>
            <div style="position: absolute; right: 10px; bottom: 5px; font-size: 10px; color: #666;">
                <i>Space for College Seal</i>
            </div>
        </div>
    </div>
</div>

<?php if ($is_print): ?>
</body>
</html>
<?php endif; ?>
