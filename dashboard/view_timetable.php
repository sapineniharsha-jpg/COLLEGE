<?php
// view_timetable.php — FINAL PRODUCTION VERSION
// Enhanced UI, Print Logic, and Security
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    echo 'Database connection not initialized.';
    exit();
}

$security_path = __DIR__ . '/../platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}

// Role-based access gate
if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}
$user_role = strtoupper(trim((string) ($_SESSION['role'] ?? 'USER')));
$allowed_roles = ['ADMIN', 'PRINCIPAL', 'DEAN', 'DEAN_ACADEMICS', 'HOD', 'FACULTY', 'STUDENT', 'VC'];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(404);
    echo '404 Not Found';
    exit();
}

/* ======================================================
   1. VALIDATE INPUT & SECURITY
====================================================== */
if (!isset($_GET['id']) || !ctype_digit((string) $_GET['id'])) {
    die("Invalid Request");
}
$master_id = (int) $_GET['id'];

if (!function_exists('e')) {
    function e($str)
    {
        if (function_exists('vh_e')) {
            return vh_e($str);
        }
        return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/* ======================================================
   2. FETCH TIMETABLE MASTER
====================================================== */
$stmt = $mysqli->prepare("SELECT * FROM timetable_master WHERE id = ?");
$stmt->bind_param("i", $master_id);
$stmt->execute();
$master = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$master) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h3>Timetable Not Found</h3><p>The requested schedule does not exist or has been deleted.</p></div>");
}

/* ======================================================
   3. FETCH SUBJECT METADATA
====================================================== */
$stmtSub = $mysqli->prepare("SELECT * FROM timetable_subjects WHERE master_id = ?");
$stmtSub->bind_param("i", $master_id);
$stmtSub->execute();
$subRes = $stmtSub->get_result();

$subject_meta = [];
while ($s = $subRes->fetch_assoc()) {
    $subject_meta[$s['subject_code']] = $s;
}
$stmtSub->close();

/* ======================================================
   4. FETCH TIMETABLE GRID DETAILS
====================================================== */
$stmtDet = $mysqli->prepare("SELECT * FROM timetable_details WHERE master_id = ? ORDER BY day, hour");
$stmtDet->bind_param("i", $master_id);
$stmtDet->execute();
$res = $stmtDet->get_result();

/* ======================================================
   5. HELPER — STABLE PASTEL COLOR GENERATOR
====================================================== */
if (!function_exists('pastelColor')) {
    function pastelColor(string $seed): string
    {
        $hash = md5($seed);
        $r = (hexdec(substr($hash, 0, 2)) + 230) / 2.0;
        $g = (hexdec(substr($hash, 2, 2)) + 230) / 2.0;
        $b = (hexdec(substr($hash, 4, 2)) + 230) / 2.0;
        return "rgb(" . (int) $r . "," . (int) $g . "," . (int) $b . ")";
    }
}

/* ======================================================
   6. BUILD GRID + SUBJECT SUMMARY
====================================================== */
$grid = [];
$subjects_summary = [];

while ($row = $res->fetch_assoc()) {
    $grid[$row['day']][$row['hour']] = $row;
    $code = $row['subject_code'];
    $meta = $subject_meta[$code] ?? [];

    if (!isset($subjects_summary[$code])) {
        $category = 'THEORY';
        if (isset($row['type']) && $row['type'] === 'SPEC') {
            $category = 'SPECIAL';
        } elseif (isset($meta['category']) && $meta['category'] === 'P') {
            $category = 'PRACTICAL';
        }

        $subjects_summary[$code] = [
            'code'      => $code,
            'name'      => $meta['name'] ?? $row['subject_name'],
            'L'         => $meta['L'] ?? 0,
            'T'         => $meta['T'] ?? 0,
            'P'         => $meta['P'] ?? 0,
            'C'         => $meta['credits'] ?? 0,
            'category'  => $category,
            'color'     => pastelColor($code . "SALT"),
            'hours'     => 0,
            'faculty'   => [],
        ];
    }

    $fid = $row['faculty_id'] ?: 'NA';
    $fname = $row['faculty_name'] ?: 'Staff';
    $subjects_summary[$code]['faculty'][$fid] = ['name' => $fname, 'id' => $fid];
    $subjects_summary[$code]['hours']++;
}

/* ======================================================
   7. GROUP SUBJECTS FOR SUMMARY
====================================================== */
$grouped = ['THEORY' => [], 'PRACTICAL' => [], 'SPECIAL' => []];
foreach ($subjects_summary as $s) {
    $cat = $s['category'];
    if (!isset($grouped[$cat])) {
        $grouped['THEORY'][] = $s;
    } else {
        $grouped[$cat][] = $s;
    }
}

/* ======================================================
   8. CONFIGURATION
====================================================== */
$periods = [
    1 => "08:45 - 09:35", 2 => "09:35 - 10:25",
    3 => "10:45 - 11:35", 4 => "11:35 - 12:25",
    5 => "01:25 - 02:15", 6 => "02:15 - 03:05",
    7 => "03:05 - 03:55", 8 => "03:55 - 04:45",
];
$days = [1 => 'MON', 2 => 'TUE', 3 => 'WED', 4 => 'THU', 5 => 'FRI'];

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --insta-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
        --primary-dark: #1e293b;
        --border-color: #cbd5e1;
        --bg-soft: #f8fafc;
    }

    body { background: var(--bg-soft); font-family: 'Outfit', sans-serif; }

    .view-container {
        max-width: 1400px; margin: 30px auto; background: #fff;
        padding: 40px; border-radius: 24px; box-shadow: 0 10px 40px -10px rgba(0,0,0,0.1);
        border: 1px solid rgba(255,255,255,0.5);
    }

    .tt-header {
        display: flex; justify-content: space-between; align-items: flex-end;
        border-bottom: 3px solid #bc1888; padding-bottom: 20px; margin-bottom: 30px;
    }
    .inst-title { font-size: 2rem; font-weight: 900; background: var(--insta-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; line-height: 1.2; }
    .inst-sub { font-size: 0.95rem; font-weight: 600; color: #64748b; margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }

    .meta-box { text-align: right; }
    .meta-tag {
        display: inline-block; background: #f1f5f9; padding: 6px 12px; border-radius: 8px;
        font-size: 0.85rem; font-weight: 700; color: #334155; margin-left: 5px;
        border: 1px solid #e2e8f0;
    }

    .grid-wrap { overflow-x: auto; padding-bottom: 10px; }
    .view-table { width: 100%; border-collapse: collapse; table-layout: fixed; min-width: 900px; }
    .view-table th { background: #1e293b; color: #fff; padding: 12px; font-size: 0.85rem; text-transform: uppercase; border: 1px solid #334155; font-weight: 700; }
    .view-table td { border: 1px solid #cbd5e1; height: 90px; vertical-align: middle; padding: 4px; transition: 0.2s; position: relative; }

    .day-col {
        background: #f8fafc; font-weight: 800; color: #bc1888;
        writing-mode: vertical-rl; transform: rotate(180deg); width: 45px;
        text-align: center; letter-spacing: 2px;
    }

    .cell-box {
        height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;
    }
    .c-code { font-weight: 800; font-size: 0.95rem; color: #0f172a; line-height: 1.2; }
    .c-sub { font-size: 0.7rem; color: #475569; font-weight: 600; text-transform: uppercase; line-height: 1.1; margin: 3px 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .c-fac { font-size: 0.75rem; color: #bc1888; font-weight: 700; background: rgba(255,255,255,0.8); padding: 2px 6px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

    .lab-dot { position: absolute; top: 5px; right: 5px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; box-shadow: 0 0 0 2px white; }

    .summary-box { margin-top: 40px; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .sum-table { width: 100%; border-collapse: collapse; }
    .sum-table th { background: #f8fafc; color: #334155; font-size: 0.8rem; text-align: left; padding: 12px 15px; text-transform: uppercase; font-weight: 800; }
    .sum-table td { padding: 10px 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; color: #475569; vertical-align: middle; }
    .cat-head { background: #e2e8f0; font-weight: 900; font-size: 0.8rem; padding: 8px 15px; color: #1e293b; letter-spacing: 1px; }

    .signatures { display: flex; justify-content: space-between; margin-top: 80px; padding: 0 50px; }
    .sign-line { width: 220px; border-top: 2px solid #334155; text-align: center; font-size: 0.9rem; font-weight: 700; padding-top: 8px; color: #1e293b; }

    .fab-print {
        position: fixed; bottom: 40px; right: 40px;
        background: var(--insta-grad); color: white;
        padding: 15px 35px; border-radius: 50px; font-weight: 800; font-size: 1rem;
        box-shadow: 0 10px 30px rgba(188, 24, 136, 0.4);
        border: none; cursor: pointer; display: flex; align-items: center; gap: 10px;
        transition: transform 0.2s; z-index: 999;
    }
    .fab-print:hover { transform: translateY(-5px) scale(1.05); }

    @media print {
        @page { size: landscape; margin: 5mm; }
        body { background: #fff; padding: 0; font-family: 'Times New Roman', serif; }
        .view-container { box-shadow: none; border: none; width: 100%; max-width: 100%; margin: 0; padding: 0; }
        .fab-print, header, footer, .no-print { display: none !important; }
        .grid-cell { height: 60px; }
        .inst-title { -webkit-text-fill-color: #000; color: #000; background: none; }
        th { background: #eee !important; color: #000 !important; }
        .c-fac { color: #000 !important; font-weight: bold; background: none; box-shadow: none; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        tr, td, div { page-break-inside: avoid; }
    }
</style>

<button class="fab-print no-print" onclick="window.print()">
    <i class="fas fa-file-pdf"></i> Download PDF
</button>

<div class="view-container">

    <div class="tt-header">
        <div>
            <div class="inst-title">Vel Tech High Tech</div>
            <div class="inst-sub">Dr.Rangarajan Dr.Sakunthala Engineering College</div>
            <div style="margin-top:15px; font-weight:800; font-size:1.4rem; color:#1e293b;">
                DEPARTMENT OF <?= e($master['department']) ?>
            </div>
        </div>
        <div class="meta-box">
            <div style="font-size:1.2rem; font-weight:800; color:#bc1888; margin-bottom:8px;">
                <?= e($master['program']) ?> - <?= e($master['section']) ?>
            </div>
            <div>
                <span class="meta-tag">BATCH: <?= e($master['batch']) ?></span>
                <span class="meta-tag">SEM: <?= e($master['semester']) ?></span>
            </div>
            <?php if (!empty($master['class_advisor'])): ?>
                <div style="font-size:0.85rem; font-weight:600; color:#64748b; margin-top:8px;">
                    Class Advisor: <span style="color:#1e293b;"><?= e($master['class_advisor']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-wrap">
        <table class="view-table">
            <thead>
                <tr>
                    <th style="width:40px;">Day</th>
                    <?php foreach ($periods as $i => $t): ?>
                        <th>
                            <div style="font-size:1rem;">HOUR <?= e((string) $i) ?></div>
                            <div style="font-size:0.7rem; opacity:0.8; font-weight:500;"><?= e($t) ?></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php for ($d = 1; $d <= 5; $d++): ?>
                <tr>
                    <td class="day-col"><?= e($days[$d]) ?></td>
                    <?php for ($h = 1; $h <= 8; $h++):
                        $c = $grid[$d][$h] ?? null;
                    ?>
                    <td style="background: <?= $c ? e($subjects_summary[$c['subject_code']]['color']) : '#fff' ?>;">
                        <?php if ($c): ?>
                            <?php if (($subjects_summary[$c['subject_code']]['category'] ?? '') === 'PRACTICAL'): ?>
                                <div class="lab-dot"></div>
                            <?php endif; ?>
                            <div class="cell-box">
                                <div class="c-code"><?= e($c['subject_code']) ?></div>
                                <div class="c-sub" title="<?= e($c['subject_name']) ?>"><?= e($c['subject_name']) ?></div>
                                <div class="c-fac"><?= e($c['faculty_name']) ?></div>
                                <?php if (($c['group_name'] ?? 'ENTIRE') !== 'ENTIRE'): ?>
                                    <div style="font-size:0.6rem; background:#1e293b; color:#fff; padding:1px 4px; border-radius:3px; margin-top:2px;">
                                        <?= e($c['group_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="cell-box" style="opacity:0.3; color:#cbd5e1; font-size:1.5rem;">&times;</div>
                        <?php endif; ?>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <div class="summary-box">
        <table class="sum-table">
            <thead>
                <tr>
                    <th width="5%" style="text-align:center;">#</th>
                    <th width="15%">Course Code</th>
                    <th width="35%">Course Title</th>
                    <th width="15%" style="text-align:center;">L - T - P - C</th>
                    <th width="20%">Faculty Name</th>
                    <th width="10%" style="text-align:center;">Hrs/Wk</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $i = 1;
                foreach (['THEORY', 'PRACTICAL', 'SPECIAL'] as $cat):
                    if (empty($grouped[$cat])) {
                        continue;
                    }
                ?>
                    <tr><td colspan="6" class="cat-head"><?= e($cat) ?> COURSES</td></tr>
                    <?php foreach ($grouped[$cat] as $s):
                        $facList = [];
                        foreach ($s['faculty'] as $f) {
                            $idPart = ($f['id'] !== 'NA' && strpos((string) $f['id'], 'MAN') === false) ? " <span style='font-size:0.75rem; color:#94a3b8;'>(" . e($f['id']) . ")</span>" : "";
                            $facList[] = e($f['name']) . $idPart;
                        }
                    ?>
                    <tr>
                        <td style="text-align:center; font-weight:600;"><?= e((string) $i++) ?></td>
                        <td style="font-weight:700; color:#334155;"><?= e($s['code']) ?></td>
                        <td style="font-weight:600;"><?= e($s['name']) ?></td>
                        <td style="text-align:center; font-weight:700; font-size:0.85rem; color:#1e293b;">
                            <?= e((string) $s['L']) ?> - <?= e((string) $s['T']) ?> - <?= e((string) $s['P']) ?> - <?= e((string) $s['C']) ?>
                        </td>
                        <td><?= implode('<br>', $facList) ?></td>
                        <td style="text-align:center; font-weight:800; background:#f1f5f9; color:#bc1888;"><?= e((string) $s['hours']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="signatures">
        <div class="sign-line">Timetable In-charge</div>
        <div class="sign-line">HOD / <?= e($master['department']) ?></div>
        <div class="sign-line">Principal</div>
    </div>

    <div style="text-align:center; font-size:0.75rem; color:#94a3b8; margin-top:30px;">
        Generated via Academic Planning System v2.0 • <?= e(date('d M Y, h:i A')) ?>
    </div>

</div>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
