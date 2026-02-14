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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$message = '';
$msg_type = '';
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('vh_require_csrf_or_exit')) {
    vh_require_csrf_or_exit(false);
}

// Fetch Existing Data
if ($id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM circulars WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $circular = $result->fetch_assoc();

    if (!$circular) {
        die("Circular not found.");
    }
} else {
    die("Invalid ID.");
}

// Handle Update Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $circular_date = trim((string) ($_POST['circular_date'] ?? ''));
    $circular_subject = trim((string) ($_POST['circular_subject'] ?? ''));
    $circular_body = (string) ($_POST['circular_body'] ?? '');
    $department = trim((string) ($_POST['department'] ?? ''));

    $signed_pdf_path = $circular['signed_pdf_path']; // Keep old path by default
    if (isset($_FILES['signed_pdf']) && $_FILES['signed_pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/circulars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower((string) pathinfo((string) $_FILES['signed_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $filePath = $uploadDir . str_replace('/', '_', (string) $circular['circular_number']) . '_signed.pdf';
            if (move_uploaded_file($_FILES['signed_pdf']['tmp_name'], $filePath)) {
                $signed_pdf_path = $filePath;
            }
        } else {
            $message = "Only PDF files are allowed.";
            $msg_type = "danger";
        }
    }

    if ($msg_type !== 'danger') {
        $updateStmt = $mysqli->prepare("UPDATE circulars SET circular_date=?, circular_subject=?, circular_body=?, department=?, signed_pdf_path=?, updated_by=? WHERE id=?");
        $updated_by = (string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? 'SYSTEM');
        $updateStmt->bind_param("ssssssi", $circular_date, $circular_subject, $circular_body, $department, $signed_pdf_path, $updated_by, $id);

        if ($updateStmt->execute()) {
            header("Location: view_circular.php?id=" . $id);
            exit();
        } else {
            $message = "Error updating: " . $updateStmt->error;
            $msg_type = "danger";
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
        background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.5); border-radius: 24px; padding: 25px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08); margin-bottom: 30px;
    }
    .insta-text { background: var(--inst-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 800; }
    .saas-card {
        background: #fff; border-radius: 24px; padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.04);
    }
    .form-label { font-weight: 700; font-size: 0.85rem; color: #64748b; margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-control, .form-select {
        width: 100%; padding: 12px 15px; border-radius: 12px; border: 1px solid #e2e8f0;
        background: #f8fafc; font-size: 0.95rem; transition: 0.3s;
    }
    .form-control:focus { border-color: #bc1888; background: #fff; outline: none; }
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
            <h1 style="margin:0; font-size:1.5rem;" class="insta-text">Edit Circular</h1>
            <p style="margin:5px 0 0; color:#64748b; font-size:0.9rem;"><?= vh_e($circular['circular_number']) ?></p>
        </div>
        <div>
            <a href="manage_circulars.php" class="btn-back"><i class="fas fa-times"></i> Cancel</a>
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
                    <input type="date" name="circular_date" class="form-control" value="<?= vh_e($circular['circular_date']) ?>" required>
                </div>
                <div>
                    <label class="form-label">Department</label>
                    <select name="department" class="form-select" required>
                        <option value="<?= vh_e($circular['department']) ?>" selected><?= vh_e($circular['department']) ?> (Current)</option>
                        <option>Principal Administration</option><option>Academics</option><option>Examination Cell</option>
                        <option>Quality Assurance Cell</option><option>Administration</option><option>Research and Development Cell</option>
                        <option>Library</option><option>Sports</option><option>NCC</option><option>CCA</option><option>All</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <label class="form-label">Subject</label>
                <input type="text" name="circular_subject" class="form-control" value="<?= vh_e($circular['circular_subject']) ?>" required>
            </div>

            <div style="margin-bottom:25px;">
                <label class="form-label">Body Content</label>
                <textarea name="circular_body" id="editor1"><?= vh_e($circular['circular_body']) ?></textarea>
            </div>

            <div style="margin-bottom:30px; background:#f8fafc; padding:20px; border-radius:12px; border:1px solid #e2e8f0;">
                <label class="form-label">Update PDF (Optional)</label>
                <input type="file" name="signed_pdf" class="form-control" accept=".pdf">
                <?php if(!empty($circular['signed_pdf_path'])): ?>
                    <small style="display:block; margin-top:8px; color:#166534; font-weight:600;"><i class="fas fa-check-circle"></i> Current PDF Available</small>
                <?php endif; ?>
            </div>

            <button class="btn-insta">Update Circular</button>
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
