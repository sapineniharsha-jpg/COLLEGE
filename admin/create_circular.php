<?php
// =========================================================
// 1. BUSINESS LOGIC (PRESERVED + HARDENED)
// =========================================================
ob_start();
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

// Auth Check
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$allowed_roles = ['admin', 'principal', 'dean', 'dean_academics'];
if (!in_array($role, $allowed_roles, true)) {
    http_response_code(404);
    die('404 Not Found');
}

$message = '';
$msg_type = '';
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(false);
    }

    // --- Numbering Logic ---
    $currentMonth = date('M');
    $academicYear = (date('m') >= 6) ? date('Y') . '-' . (date('Y') + 1) : (date('Y') - 1) . '-' . date('Y');
    $monthPrefix = "VTHT/{$academicYear}/Cir/{$currentMonth}/";

    $count = 0;
    $likePrefix = $monthPrefix . '%';
    $count_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM circulars WHERE circular_number LIKE ?");
    if ($count_stmt) {
        $count_stmt->bind_param("s", $likePrefix);
        $count_stmt->execute();
        $count_res = $count_stmt->get_result();
        if ($count_res && ($count_row = $count_res->fetch_assoc())) {
            $count = (int) ($count_row['count'] ?? 0);
        }
    }
    $circular_number = $monthPrefix . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);

    // Inputs
    $circular_date = trim((string) ($_POST['circular_date'] ?? ''));
    $circular_subject = trim((string) ($_POST['circular_subject'] ?? ''));
    $circular_body = (string) ($_POST['circular_body'] ?? ''); // CKEditor Data
    $department = trim((string) ($_POST['department'] ?? ''));
    $priority = trim((string) ($_POST['priority'] ?? 'Medium'));

    $allowed_priorities = ['Low', 'Medium', 'High', 'Urgent'];
    if (!in_array($priority, $allowed_priorities, true)) {
        $priority = 'Medium';
    }

    if ($circular_date === '' || $circular_subject === '' || $circular_body === '' || $department === '') {
        $message = 'Please fill all required fields.';
        $msg_type = 'danger';
    } else {
        // PDF Upload
        $signed_pdf_path = null;
        if (isset($_FILES['signed_pdf']) && $_FILES['signed_pdf']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/circulars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = strtolower((string) pathinfo((string) $_FILES['signed_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $message = 'Only PDF files are allowed for signed document.';
                $msg_type = 'danger';
            } else {
                $safeName = str_replace('/', '_', $circular_number) . '_signed.pdf';
                $filePath = $uploadDir . $safeName;
                if (move_uploaded_file($_FILES['signed_pdf']['tmp_name'], $filePath)) {
                    $signed_pdf_path = $filePath;
                }
            }
        }

        if ($msg_type !== 'danger') {
            // Insert
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $verification_url = $scheme . '://' . $host . '/verify_circular.php?no=' . urlencode($circular_number);

            $stmt = $mysqli->prepare(
                "INSERT INTO circulars
                (circular_number, circular_date, circular_subject, circular_body, department, priority, verification_url, signed_pdf_path, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Published', ?)"
            );
            $created_by = (string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? 'SYSTEM');
            $stmt->bind_param(
                "sssssssss",
                $circular_number,
                $circular_date,
                $circular_subject,
                $circular_body,
                $department,
                $priority,
                $verification_url,
                $signed_pdf_path,
                $created_by
            );

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                header("Location: view_circular.php?id=" . (int) $new_id);
                exit();
            } else {
                $message = "Error: " . $stmt->error;
                $msg_type = "danger";
            }
        }
    }
}

// =========================================================
// 2. PORTAL UI INTEGRATION
// =========================================================
$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<style>
    .pwa-container { max-width: 900px; margin: 0 auto; padding: 25px; min-height: 80vh; }
    .dash-banner {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.5);
        border-radius: 24px; padding: 25px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }
    .insta-text {
        background: var(--inst-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 800;
    }
    .saas-card {
        background: #fff; border-radius: 24px; padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04);
    }
    .form-label { font-weight: 700; font-size: 0.85rem; color: #64748b; margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-control, .form-select {
        width: 100%; padding: 12px 15px; border-radius: 12px; border: 1px solid #e2e8f0;
        background: #f8fafc; font-size: 0.95rem; transition: 0.3s;
    }
    .form-control:focus, .form-select:focus {
        border-color: #bc1888; background: #fff; box-shadow: 0 0 0 3px rgba(188, 24, 136, 0.1); outline: none;
    }
    .btn-insta {
        background: var(--inst-grad); color: white; border: none; padding: 14px 30px;
        border-radius: 50px; font-weight: 700; cursor: pointer; width: 100%; font-size: 1rem;
        box-shadow: 0 4px 15px rgba(188, 24, 136, 0.3); transition: 0.3s;
    }
    .btn-insta:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(188, 24, 136, 0.4); }
    .btn-back {
        text-decoration: none; color: #64748b; font-weight: 600; font-size: 0.9rem;
        padding: 8px 16px; background: #f1f5f9; border-radius: 20px; transition: 0.2s;
    }
    .btn-back:hover { background: #e2e8f0; color: #1e293b; }
    .ck-editor__editable { min-height: 300px; }
    .row-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    @media(max-width: 768px) { .row-grid { grid-template-columns: 1fr; } }
</style>

<script src="https://cdn.ckeditor.com/4.22.1/full/ckeditor.js"></script>

<main class="pwa-container">

    <div class="dash-banner">
        <div>
            <h1 style="margin:0; font-size:1.8rem;" class="insta-text">Create Circular</h1>
            <p style="margin:5px 0 0; color:#64748b;">New Official Announcement</p>
        </div>
        <div>
            <a href="manage_circulars.php" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert alert-<?= vh_e($msg_type) ?>" style="border-radius:12px; margin-bottom:20px; padding:15px; background:<?= $msg_type=='danger'?'#fee2e2':'#dcfce7' ?>; color:<?= $msg_type=='danger'?'#991b1b':'#166534' ?>;">
            <?= vh_e($message) ?>
        </div>
    <?php endif; ?>

    <div class="saas-card">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= vh_e($csrf_token) ?>">

            <div class="row-grid">
                <div>
                    <label class="form-label">Date</label>
                    <input type="date" name="circular_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <option>Medium</option><option>High</option><option>Urgent</option><option>Low</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <label class="form-label">Department</label>
                <select name="department" class="form-select" required>
                    <option value="">Select Department</option>
                    <option>Principal Administration</option><option>Academics</option><option>Examination Cell</option>
                    <option>Quality Assurance Cell</option><option>Administration</option><option>Research and Development Cell</option>
                    <option>Library</option><option>Sports</option><option>NCC</option><option>CCA</option><option>All</option>
                </select>
            </div>

            <div style="margin-bottom:20px;">
                <label class="form-label">Subject</label>
                <input type="text" name="circular_subject" class="form-control" required placeholder="Enter circular subject...">
            </div>

            <div style="margin-bottom:25px;">
                <label class="form-label">Body Content</label>
                <textarea name="circular_body" id="editor1" class="ckeditor" required></textarea>
            </div>

            <div style="margin-bottom:30px; background:#f8fafc; padding:20px; border-radius:12px; border:1px solid #e2e8f0;">
                <label class="form-label">Upload Signed PDF</label>
                <input type="file" name="signed_pdf" class="form-control" accept=".pdf">
                <small style="display:block; margin-top:5px; color:#94a3b8;">Optional attachment for download.</small>
            </div>

            <button class="btn-insta">Generate & Publish Circular</button>
        </form>
    </div>

</main>

<script>
    CKEDITOR.replace('editor1', {
        height: 400,
        toolbar: 'Full',
        allowedContent: true,
        extraPlugins: 'image,colorbutton,font,justify'
    });
</script>

<?php
$chat_widget_path = find_include_path($include_paths, 'assets/ui/floating_chat_widget.php');
if ($chat_widget_path) {
    include $chat_widget_path;
}
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
