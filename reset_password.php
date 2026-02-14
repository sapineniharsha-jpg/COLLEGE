<?php
// reset_password.php
// Consumes password reset token and updates password.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

if (!function_exists('vh_e')) {
    function vh_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('vh_find_include_path')) {
    function vh_find_include_path(array $paths, string $relative): ?string
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
if (!function_exists('looks_hashed_password')) {
    function looks_hashed_password(string $value): bool
    {
        return (bool) preg_match('/^\$2[aby]\$|^\$argon2/i', $value);
    }
}
if (!function_exists('safe_token')) {
    function safe_token($t): string
    {
        $t = trim((string) $t);
        return preg_match('/^[a-f0-9]{32,128}$/i', $t) ? $t : '';
    }
}
if (!function_exists('ensure_password_reset_table')) {
    function ensure_password_reset_table(mysqli $mysqli): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` VARCHAR(50) NOT NULL,
            `user_type` VARCHAR(20) NOT NULL,
            `source_table` VARCHAR(80) NOT NULL,
            `id_column` VARCHAR(80) NOT NULL,
            `password_column` VARCHAR(80) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used` TINYINT(1) NOT NULL DEFAULT 0,
            `used_at` DATETIME DEFAULT NULL,
            `user_name` VARCHAR(150) DEFAULT NULL,
            `user_email` VARCHAR(200) DEFAULT NULL,
            `requested_ip` VARCHAR(64) DEFAULT NULL,
            `requested_user_agent` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_token_hash` (`token_hash`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        return (bool) $mysqli->query($sql);
    }
}

$include_paths = [__DIR__, dirname(__DIR__), rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')];
$db_path = vh_find_include_path($include_paths, 'includes/db.php');
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

$security_path = __DIR__ . '/platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}

$page_title = 'Reset Password | VEL AI Portal';
$message = '';
$message_type = 'info';
$token = safe_token($_GET['token'] ?? $_POST['token'] ?? '');
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

$token_row = null;
if ($token !== '' && ensure_password_reset_table($mysqli)) {
    $token_hash = hash('sha256', $token);
    $stmt = $mysqli->prepare("SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used = 0 AND expires_at >= NOW() ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $token_hash);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $token_row = $res->fetch_assoc();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(false);
    }

    $new_password = (string) ($_POST['new_password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');

    if ($token_row === null) {
        $message = 'Invalid or expired reset token.';
        $message_type = 'danger';
    } elseif (strlen($new_password) < 8) {
        $message = 'Password must be at least 8 characters.';
        $message_type = 'warning';
    } elseif ($new_password !== $confirm_password) {
        $message = 'Password and confirm password do not match.';
        $message_type = 'warning';
    } elseif (!preg_match('/[A-Za-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $message = 'Use at least one letter and one number in password.';
        $message_type = 'warning';
    } else {
        $allowed_tables = ['employee_details1', 'students_login_master', 'students_batch_25_26'];
        $allowed_id_cols = ['ID_NO', 'IDNo', 'id_no'];
        $allowed_pass_cols = ['PASSWORD', 'Password', 'password'];

        $table = (string) ($token_row['source_table'] ?? '');
        $id_col = (string) ($token_row['id_column'] ?? '');
        $pass_col = (string) ($token_row['password_column'] ?? '');
        $user_id = (string) ($token_row['user_id'] ?? '');

        if (!in_array($table, $allowed_tables, true) || !in_array($id_col, $allowed_id_cols, true) || !in_array($pass_col, $allowed_pass_cols, true) || $user_id === '') {
            $message = 'Invalid reset token metadata.';
            $message_type = 'danger';
        } else {
            $mysqli->begin_transaction();
            try {
                $lookupSql = "SELECT `{$pass_col}` AS existing_password FROM `{$table}` WHERE `{$id_col}` = ? LIMIT 1";
                $lk = $mysqli->prepare($lookupSql);
                if (!$lk) {
                    throw new Exception('Unable to verify account.');
                }
                $lk->bind_param("s", $user_id);
                $lk->execute();
                $lkRes = $lk->get_result();
                if (!$lkRes || $lkRes->num_rows === 0) {
                    throw new Exception('Account no longer exists.');
                }
                $existing = (string) (($lkRes->fetch_assoc()['existing_password'] ?? ''));

                // Compatibility mode: if current DB stores plain password, keep same format.
                $store_as_hash = ($existing === '' || looks_hashed_password($existing));
                $final_password = $store_as_hash ? (string) password_hash($new_password, PASSWORD_DEFAULT) : $new_password;

                $updSql = "UPDATE `{$table}` SET `{$pass_col}` = ? WHERE `{$id_col}` = ?";
                $upd = $mysqli->prepare($updSql);
                if (!$upd) {
                    throw new Exception('Unable to update password.');
                }
                $upd->bind_param("ss", $final_password, $user_id);
                if (!$upd->execute()) {
                    throw new Exception($upd->error);
                }

                $usedId = (int) ($token_row['id'] ?? 0);
                $mark = $mysqli->prepare("UPDATE password_reset_tokens SET used = 1, used_at = NOW() WHERE id = ?");
                if ($mark) {
                    $mark->bind_param("i", $usedId);
                    $mark->execute();
                }

                $mysqli->commit();
                $message = 'Password reset successful. You can login now.';
                $message_type = 'success';
                $token_row = null;
            } catch (Throwable $e) {
                $mysqli->rollback();
                $message = 'Unable to reset password: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

$header_path = __DIR__ . '/includes/header.php';
if (file_exists($header_path)) {
    include $header_path;
}
?>
<style>
    .auth-wrap { max-width: 560px; margin: 40px auto; padding: 0 14px; }
    .auth-card {
        background: #fff; border: 1px solid #eef2f7; border-radius: 18px; padding: 22px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
    }
    .auth-title {
        font-size: 1.45rem; font-weight: 800; margin: 0 0 8px;
        background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .muted { color: #64748b; font-size: .92rem; }
    .btn-inst {
        background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
        color: #fff; border: none; border-radius: 12px; font-weight: 700;
    }
    .btn-inst:hover { color: #fff; transform: translateY(-1px); }
</style>

<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-title">Reset Password</h1>
        <p class="muted">Create a new password for your account.</p>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= vh_e($message_type) ?>" style="border-radius:10px;"><?= vh_e($message) ?></div>
        <?php endif; ?>

        <?php if ($token_row !== null): ?>
            <form method="POST" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= vh_e($csrf_token) ?>">
                <input type="hidden" name="token" value="<?= vh_e($token) ?>">
                <div class="col-12">
                    <label class="form-label fw-bold small text-muted">New Password</label>
                    <input type="password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-muted">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                </div>
                <div class="col-12 d-grid">
                    <button class="btn btn-inst py-2">Update Password</button>
                </div>
            </form>
            <div class="mt-3 text-muted" style="font-size:.82rem;">
                Token valid till: <?= vh_e((string) ($token_row['expires_at'] ?? '')) ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning" style="border-radius:10px;">Reset token is invalid or expired.</div>
            <a class="btn btn-outline-secondary" href="/forgot_password.php">Generate new reset link</a>
        <?php endif; ?>

        <div class="mt-3 text-muted" style="font-size:.82rem;">
            Back to <a href="/login.php">Login</a>
        </div>
    </div>
</div>

<?php
$footer_path = __DIR__ . '/includes/footer.php';
if (file_exists($footer_path)) {
    include $footer_path;
}
?>
