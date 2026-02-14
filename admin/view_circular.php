<?php
// =========================================================
// 1. BUSINESS LOGIC (PRESERVED + HARDENED)
// =========================================================
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

// Auth + role gate
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}
$page_role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$page_allowed_roles = ['admin', 'principal', 'dean', 'dean_academics'];
if (!in_array($page_role, $page_allowed_roles, true)) {
    http_response_code(404);
    die('404 Not Found');
}

// ROBUST LOOKUP
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$circular_no = trim((string) ($_GET['circular_no'] ?? $_GET['ref_no'] ?? ''));
$circular = null;

if ($id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM circulars WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $circular = $stmt->get_result()->fetch_assoc();
} elseif ($circular_no !== '') {
    $stmt = $mysqli->prepare("SELECT * FROM circulars WHERE circular_number = ?");
    $stmt->bind_param("s", $circular_no);
    $stmt->execute();
    $circular = $stmt->get_result()->fetch_assoc();
}

// PERMISSION CHECK
$user_role = strtoupper((string) ($_SESSION['role'] ?? 'USER'));
$allowed_edit = ['ADMIN', 'PRINCIPAL', 'DEAN', 'DEAN_ACADEMICS'];
$can_edit = in_array($user_role, $allowed_edit, true);
$manager_roles = ['ADMIN', 'PRINCIPAL', 'DEAN', 'DEAN_ACADEMICS'];
$back_link = in_array($user_role, $manager_roles, true) ? 'manage_circulars.php' : 'circulars.php';

// QR GENERATION
$qr_content = "";
if ($circular) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qr_content = $scheme . '://' . $host . '/verify_circular.php?no=' . urlencode((string) $circular['circular_number']);
}

// =========================================================
// 2. PORTAL UI INTEGRATION
// =========================================================
if (!isset($_GET['print'])) {
    $header_path = find_include_path($include_paths, 'includes/header.php');
    if ($header_path) {
        include $header_path;
    }
}
?>

<?php if (!$circular): ?>
    <div style="text-align:center; padding:50px; font-family:'Plus Jakarta Sans', sans-serif;">
        <h1 style="color:#ef4444; font-weight:800;">Circular Not Found</h1>
        <p style="color:#64748b;">The requested circular could not be loaded.</p>
        <a href="<?= vh_e($back_link) ?>" style="text-decoration:none; color:#bc1888; font-weight:700;">Return to Dashboard</a>
    </div>
<?php else: ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
    .controls-bar {
        max-width: 900px; margin: 20px auto; padding: 15px 25px;
        background: rgba(255,255,255,0.9); backdrop-filter: blur(10px);
        border: 1px solid rgba(0,0,0,0.05); border-radius: 20px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    }
    .btn-action {
        padding: 8px 18px; border-radius: 50px; font-weight: 700; font-size: 0.9rem;
        border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
        text-decoration: none; transition: 0.2s; font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-print { background: #e0f2fe; color: #0284c7; }
    .btn-edit { background: #fdf2f8; color: #db2777; }
    .btn-back { background: #f1f5f9; color: #64748b; }
    .btn-action:hover { transform: translateY(-2px); opacity: 0.9; }
    .page-wrapper {
        background: #f1f5f9; padding: 40px 20px; min-height: 100vh;
        display: flex; justify-content: center;
    }
    .page {
        background: white; width: 210mm; min-height: 297mm; padding: 20mm;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1); font-family: 'Times New Roman', serif;
        position: relative; box-sizing: border-box;
    }
    table { width: 100%; border-collapse: collapse; }
    .header-content { display: flex; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
    .logo { width: 95px; margin-right: 15px; }
    .titles { flex: 1; text-align: center; }
    .titles h1 { color: #2d2c91; font-size: 28px; margin: 0; font-weight: 900; text-transform: uppercase; }
    .titles h2 { font-size: 16px; margin: 5px 0; color: #000; font-weight: bold; }
    .autonomy { font-size: 14px; font-weight: bold; margin-bottom: 3px; }
    .accreditation { font-size: 10px; line-height: 1.4; font-weight: bold; }
    .contact { font-size: 13px; text-align: right; min-width: 150px; line-height: 1.5; padding-left: 15px; font-weight: 500; }
    .main-heading { text-align: center; font-weight: bold; font-size: 18px; text-decoration: underline; margin-bottom: 25px; text-transform: uppercase; letter-spacing: 1px; }
    .meta-info { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 16px; font-weight: bold; }
    .subject-line { font-weight: bold; font-size: 16px; margin-bottom: 25px; display: flex; line-height: 1.5; text-align: justify; }
    .content { font-size: 16px; line-height: 1.6; text-align: justify; margin-bottom: 50px; min-height: 200px; }
    .signature-box { text-align: right; font-weight: bold; margin-bottom: 40px; font-size: 16px; }
    .signature-box p { margin: 0; padding-top: 60px; display: inline-block; min-width: 200px; text-align: center; }
    .qr-section { margin-top: 20px; text-align: center; }
    .dotted-line { border-top: 2px dotted #333; margin: 15px 0; width: 100%; }
    @media print {
        body { background: white; margin: 0; padding: 0; }
        .page-wrapper { padding: 0; background: white; }
        .page { box-shadow: none; width: 100%; padding: 0; margin: 0; }
        .controls-bar, .app-header, .mobile-nav, footer, .sw-launcher { display: none !important; }
        @page { margin: 0; }
    }
</style>

<?php if(!isset($_GET['print'])): ?>
<div class="controls-bar">
    <a href="<?= vh_e($back_link) ?>" class="btn-action btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    <div style="display:flex; gap:10px;">
        <button onclick="window.print()" class="btn-action btn-print"><i class="fas fa-print"></i> Print</button>
        <?php if ($can_edit): ?>
            <a href="edit_circular.php?id=<?= (int) $circular['id'] ?>" class="btn-action btn-edit"><i class="fas fa-pen"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="page-wrapper">
    <div class="page">
        <div class="header-content">
            <img src="https://www.velhightech.com/LP/logo.png" class="logo">
            <div class="titles">
                <h1>Vel Tech High Tech</h1>
                <h2>Dr. Rangarajan Dr. Sakunthala Engineering College</h2>
                <div class="autonomy">An Autonomous Institution</div>
                <div class="accreditation">
                    (Approved by AICTE, Affiliated to Anna University, Chennai)<br>
                    Accredited by NAAC with 'A' Grade & CGPA 3.27<br>
                    Accredited by NBA (ECE, IT, Biotech & Chem.Engg)
                </div>
            </div>
            <div class="contact">
                <strong>Mobile:</strong> 9789037651<br>
                <strong>Tel:</strong> 044-26840181<br>
                principal@velhightech.com<br>
                www.velhightech.com<br>
                Avadi, Chennai - 600062
            </div>
        </div>

        <div class="main-heading">CIRCULAR</div>

        <div class="meta-info">
            <div>
                <div><strong>Circular No:</strong> <?= vh_e($circular['circular_number']) ?></div>
                <div><strong>Department:</strong> <?= vh_e($circular['department']) ?></div>
            </div>
            <div style="text-align:right;">
                <div><strong>Date:</strong> <?= date('d.m.Y', strtotime((string) $circular['circular_date'])) ?></div>
            </div>
        </div>

        <div class="subject-line">
            <span class="subject-label" style="min-width:50px;">Sub:</span>
            <span><?= vh_e($circular['circular_subject']) ?></span>
        </div>

        <div class="content" id="contentBody">
            <?= (string) $circular['circular_body'] ?>
        </div>

        <div class="signature-box">
            <p>PRINCIPAL</p>
        </div>

        <div class="copy-to-box" style="font-size:14px; margin-bottom:20px;">
            <strong>Copy to:</strong>
            <ul style="list-style:none; padding:0; margin:5px 0 0 0; display:flex; flex-wrap:wrap;">
                <li style="width:33%; margin-bottom:4px;">1. Dean – Academics</li>
                <li style="width:33%; margin-bottom:4px;">2. CoE. Office</li>
                <li style="width:33%; margin-bottom:4px;">3. All Deans</li>
                <li style="width:33%; margin-bottom:4px;">4. All Associate Deans</li>
                <li style="width:33%; margin-bottom:4px;">5. All HODs</li>
                <li style="width:33%; margin-bottom:4px;">6. Info to Faculty/Students</li>
                <li style="width:33%; margin-bottom:4px;">7. Hostel Wardens</li>
                <li style="width:33%; margin-bottom:4px;">8. CFO/Accounts</li>
                <li style="width:33%; margin-bottom:4px;">9. Notice Boards</li>
            </ul>
        </div>

        <div class="qr-section">
            <div class="dotted-line"></div>
            <div id="qrcode" style="display:inline-block;"></div>
            <div style="font-size: 10px; margin-top:5px; font-weight:bold;">SCAN TO VERIFY</div>
        </div>
    </div>
</div>

<script>
    new QRCode(document.getElementById("qrcode"), {
        text: <?= json_encode($qr_content) ?>,
        width: 80, height: 80,
        colorDark : "#000000", colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });
</script>

<?php endif; ?>

<?php
if (!isset($_GET['print'])) {
    $chat_widget_path = find_include_path($include_paths, 'assets/ui/floating_chat_widget.php');
    if ($chat_widget_path) {
        include $chat_widget_path;
    }
}
?>
