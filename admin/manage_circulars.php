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

$success_msg = "";
$error_msg = "";
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('vh_require_csrf_or_exit')) {
    vh_require_csrf_or_exit(false);
}

// Handle Delete
if (isset($_POST['delete_id'])) {
    $id = (int) $_POST['delete_id'];

    // Get file path
    $stmt = $mysqli->prepare("SELECT signed_pdf_path FROM circulars WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $path = (string) ($row['signed_pdf_path'] ?? '');
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    // Delete DB entries
    $del_stmt = $mysqli->prepare("DELETE FROM circulars WHERE id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("i", $id);
        $del_stmt->execute();
    }
    $noti_stmt = $mysqli->prepare("DELETE FROM notifications WHERE circular_id = ?");
    if ($noti_stmt) {
        $noti_stmt->bind_param("i", $id);
        $noti_stmt->execute();
    }

    $success_msg = "Circular deleted successfully.";
}

// Handle Send
if (isset($_POST['send_circular'])) {
    $circular_id = (int) $_POST['circular_id'];
    $send_stmt = $mysqli->prepare("UPDATE circulars SET status='Sent' WHERE id=?");
    if ($send_stmt) {
        $send_stmt->bind_param("i", $circular_id);
        $send_stmt->execute();
    }
    $success_msg = "Circular sent to selected recipients!";
}

// Fetch All
$query = "SELECT * FROM circulars ORDER BY circular_date DESC, id DESC";
$result = $mysqli->query($query);

// =========================================================
// 2. PORTAL INTEGRATION
// =========================================================
$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<style>
    .pwa-container { max-width: 1200px; margin: 0 auto; padding: 25px; min-height: 80vh; }
    .dash-banner {
        background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.5); border-radius: 24px; padding: 25px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08); margin-bottom: 30px;
    }
    .insta-text { background: var(--inst-grad); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 800; }
    .saas-card {
        background: #fff; border-radius: 20px; border: none; overflow: hidden;
        box-shadow: 0 5px 20px rgba(0,0,0,0.03);
    }
    .table thead { background: #f8f9fa; }
    .table th { font-weight: 700; color: #64748b; text-transform: uppercase; font-size: 0.8rem; padding: 15px; border-bottom: 1px solid #eee; }
    .table td { vertical-align: middle; padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; }
    .table-hover tbody tr:hover { background-color: #fff0f5; }
    .btn-insta {
        background: var(--inst-grad); color: white; border: none; padding: 10px 25px;
        border-radius: 50px; font-weight: 700; cursor: pointer; text-decoration: none;
        box-shadow: 0 4px 15px rgba(188, 24, 136, 0.3); transition: 0.3s; display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-insta:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(188, 24, 136, 0.4); color: white; }
    .btn-icon {
        width: 32px; height: 32px; border-radius: 50%; display: inline-flex;
        align-items: center; justify-content: center; border: 1px solid #eee;
        color: #555; background: white; transition: 0.2s; text-decoration: none; font-size: 0.9rem; margin-right: 5px;
    }
    .btn-icon:hover { background: #f0f0f0; color: #000; }
    .btn-icon.delete:hover { background: #fee2e2; color: #dc2626; border-color: #fee2e2; }
    .badge-soft { padding: 5px 12px; border-radius: 8px; font-weight: 600; font-size: 0.7rem; }
    .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
    .form-select { border-radius: 10px; padding: 10px; }
    @media(max-width: 768px) {
        .pwa-container { padding: 15px; padding-bottom: 100px; }
        .dash-banner { flex-direction: column; text-align: center; gap: 15px; }
    }
</style>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<main class="pwa-container">
    <div class="dash-banner">
        <div>
            <h1 style="margin:0; font-size:1.8rem;" class="insta-text">Circular Dashboard</h1>
            <p style="margin:5px 0 0; color:#64748b;">Manage & Distribute Announcements</p>
        </div>
        <a href="create_circular.php" class="btn-insta">
            <i class="fas fa-plus"></i> Create New
        </a>
    </div>

    <?php if($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" style="border-radius:15px; margin-bottom:20px;">
            <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="saas-card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Ref No</th>
                        <th>Date</th>
                        <th style="width:30%;">Subject</th>
                        <th>Dept</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result && $result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold text-primary">
                                <?= htmlspecialchars((string) $row['circular_number'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= date('d M Y', strtotime((string) $row['circular_date'])) ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars(substr(strip_tags((string) $row['circular_subject']), 0, 50), ENT_QUOTES, 'UTF-8') ?>...</div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string) $row['department'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <?php if(!empty($row['signed_pdf_path'])): ?>
                                    <span class="badge bg-success">PDF Signed</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Digital Only</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex">
                                    <a href="view_circular.php?id=<?= (int) $row['id'] ?>" class="btn-icon" title="View"><i class="fas fa-eye"></i></a>
                                    <a href="view_circular.php?id=<?= (int) $row['id'] ?>&print=true" target="_blank" class="btn-icon" title="Print"><i class="fas fa-print"></i></a>
                                    <a href="edit_circular.php?id=<?= (int) $row['id'] ?>" class="btn-icon" title="Edit"><i class="fas fa-pen"></i></a>
                                    <button onclick='openSendModal(<?= (int) $row['id'] ?>, <?= json_encode((string) $row['circular_number']) ?>)' class="btn-icon text-success" title="Send"><i class="fas fa-paper-plane"></i></button>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this circular?');">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int) $row['id'] ?>">
                                        <button class="btn-icon delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No circulars found. Create one to get started!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="modal fade" id="sendModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">📤 Send Circular</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <div class="modal-body p-4">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="circular_id" id="modalCircularId">
            <div class="alert alert-info">Selected: <strong id="modalCircularNo"></strong></div>

            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">RECIPIENT GROUP</label>
                <select name="recipient_type" class="form-select" required>
                    <option value="students">Students Only</option>
                    <option value="faculty">Faculty Only</option>
                    <option value="both">Both Students & Faculty</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">DISTRIBUTION METHOD</label>
                <select name="send_method" class="form-select">
                    <option value="all">All</option>
                    <option value="batches">Specific Batches</option>
                    <option value="departments">Specific Departments</option>
                </select>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            <button type="submit" name="send_circular" class="btn-insta">Send Now</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openSendModal(id, number) {
        document.getElementById('modalCircularId').value = id;
        document.getElementById('modalCircularNo').innerText = number;
        var myModal = new bootstrap.Modal(document.getElementById('sendModal'));
        myModal.show();
    }
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
