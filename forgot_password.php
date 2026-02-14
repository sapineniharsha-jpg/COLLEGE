<?php
// forgot_password.php
// Secure password reset request page.

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
if (!function_exists('safe_date_input')) {
    function safe_date_input($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
if (!function_exists('find_account_for_reset')) {
    function find_account_for_reset(mysqli $mysqli, string $identifier, string $userType, string $dob = ''): ?array
    {
        $identifier = trim($identifier);
        $userType = strtolower(trim($userType));
        $dob = safe_date_input($dob);
        if ($identifier === '') {
            return null;
        }

        $staffQueries = [
            "SELECT ID_NO AS user_id, NAME AS user_name, COALESCE(college_email, EMAIL) AS user_email, date_of_birth AS dob, PASSWORD AS existing_password
             FROM employee_details1
             WHERE ID_NO = ? OR college_email = ? OR EMAIL = ?
             LIMIT 1",
        ];
        $studentQueries = [
            "SELECT IDNo AS user_id, Name AS user_name, college_email AS user_email, DateofBirth AS dob, Password AS existing_password
             FROM students_login_master
             WHERE IDNo = ? OR RegisterNo = ? OR college_email = ?
             LIMIT 1",
            "SELECT id_no AS user_id, student_name AS user_name, college_email AS user_email, dob AS dob, password AS existing_password
             FROM students_batch_25_26
             WHERE id_no = ? OR register_no = ? OR college_email = ?
             LIMIT 1",
        ];

        if (in_array($userType, ['staff', 'employee'], true)) {
            $pool = [['queries' => $staffQueries, 'table' => 'employee_details1', 'id_col' => 'ID_NO', 'pass_col' => 'PASSWORD', 'type' => 'staff']];
        } elseif ($userType === 'student') {
            $pool = [['queries' => $studentQueries, 'table' => null, 'id_col' => null, 'pass_col' => null, 'type' => 'student']];
        } else {
            $pool = [
                ['queries' => $staffQueries, 'table' => 'employee_details1', 'id_col' => 'ID_NO', 'pass_col' => 'PASSWORD', 'type' => 'staff'],
                ['queries' => $studentQueries, 'table' => null, 'id_col' => null, 'pass_col' => null, 'type' => 'student'],
            ];
        }

        foreach ($pool as $group) {
            foreach ($group['queries'] as $sql) {
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param("sss", $identifier, $identifier, $identifier);
                $stmt->execute();
                $res = $stmt->get_result();
                if (!$res || $res->num_rows === 0) {
                    continue;
                }
                $row = $res->fetch_assoc();
                if ($dob !== '') {
                    $dbDob = safe_date_input($row['dob'] ?? '');
                    if ($dbDob === '' || $dbDob !== $dob) {
                        continue;
                    }
                }
                if ($group['table'] === 'employee_details1') {
                    return [
                        'user_id' => (string) ($row['user_id'] ?? ''),
                        'user_name' => (string) ($row['user_name'] ?? ''),
                        'user_email' => (string) ($row['user_email'] ?? ''),
                        'source_table' => 'employee_details1',
                        'id_column' => 'ID_NO',
                        'password_column' => 'PASSWORD',
                        'user_type' => 'staff',
                        'existing_password' => (string) ($row['existing_password'] ?? ''),
                    ];
                }

                // For student group, infer source by selected columns in SQL
                if (strpos($sql, 'students_login_master') !== false) {
                    return [
                        'user_id' => (string) ($row['user_id'] ?? ''),
                        'user_name' => (string) ($row['user_name'] ?? ''),
                        'user_email' => (string) ($row['user_email'] ?? ''),
                        'source_table' => 'students_login_master',
                        'id_column' => 'IDNo',
                        'password_column' => 'Password',
                        'user_type' => 'student',
                        'existing_password' => (string) ($row['existing_password'] ?? ''),
                    ];
                }
                if (strpos($sql, 'students_batch_25_26') !== false) {
                    return [
                        'user_id' => (string) ($row['user_id'] ?? ''),
                        'user_name' => (string) ($row['user_name'] ?? ''),
                        'user_email' => (string) ($row['user_email'] ?? ''),
                        'source_table' => 'students_batch_25_26',
                        'id_column' => 'id_no',
                        'password_column' => 'password',
                        'user_type' => 'student',
                        'existing_password' => (string) ($row['existing_password'] ?? ''),
                    ];
                }
            }
        }
        return null;
    }
}

$page_title = 'Forgot Password | VEL AI Portal';
$message = '';
$message_type = 'info';
$reset_link = '';
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(false);
    }

    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $user_type = trim((string) ($_POST['user_type'] ?? 'auto'));
    $dob = safe_date_input($_POST['dob'] ?? '');

    if (!ensure_password_reset_table($mysqli)) {
        $message = 'Unable to prepare password reset table.';
        $message_type = 'danger';
    } elseif ($identifier === '') {
        $message = 'Please enter ID / Register No / Email.';
        $message_type = 'warning';
    } else {
        $account = find_account_for_reset($mysqli, $identifier, $user_type, $dob);
        if (!$account) {
            $message = 'Account not found (or date of birth mismatch).';
            $message_type = 'danger';
        } else {
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $token = hash('sha256', uniqid((string) mt_rand(), true));
            }
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() + (30 * 60));
            $requested_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $requested_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $stmt = $mysqli->prepare("INSERT INTO password_reset_tokens
                (user_id, user_type, source_table, id_column, password_column, token_hash, expires_at, user_name, user_email, requested_ip, requested_user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                "sssssssssss",
                $account['user_id'],
                $account['user_type'],
                $account['source_table'],
                $account['id_column'],
                $account['password_column'],
                $token_hash,
                $expires_at,
                $account['user_name'],
                $account['user_email'],
                $requested_ip,
                $requested_agent
            );

            if ($stmt->execute()) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $reset_link = $scheme . '://' . $host . '/reset_password.php?token=' . urlencode($token);
                $message = 'Reset link generated successfully. Use the link below within 30 minutes.';
                $message_type = 'success';
            } else {
                $message = 'Unable to generate reset token.';
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
    .small-note { font-size: .8rem; color: #64748b; }
    .link-box { background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:10px; word-break:break-all; }
</style>

<div class="auth-wrap">
    <div class="auth-card">
        <h1 class="auth-title">Forgot Password</h1>
        <p class="muted">Verify your account and generate a secure reset link.</p>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= vh_e($message_type) ?>" style="border-radius:10px;"><?= vh_e($message) ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
            <input type="hidden" name="_csrf" value="<?= vh_e($csrf_token) ?>">
            <div class="col-12">
                <label class="form-label fw-bold small text-muted">User Type</label>
                <select name="user_type" class="form-select">
                    <option value="auto">Auto Detect</option>
                    <option value="student">Student</option>
                    <option value="staff">Staff</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-bold small text-muted">ID / Register No / Email</label>
                <input type="text" name="identifier" class="form-control" required placeholder="Enter ID No / Register No / Email">
            </div>
            <div class="col-12">
                <label class="form-label fw-bold small text-muted">Date of Birth (Optional but recommended)</label>
                <input type="date" name="dob" class="form-control">
            </div>
            <div class="col-12 d-grid">
                <button class="btn btn-inst py-2">Generate Reset Link</button>
            </div>
        </form>

        <?php if ($reset_link !== ''): ?>
            <div class="mt-3">
                <div class="small-note fw-bold mb-1">Reset URL:</div>
                <div class="link-box"><a href="<?= vh_e($reset_link) ?>"><?= vh_e($reset_link) ?></a></div>
                <div class="small-note mt-2">For security, this link expires in 30 minutes and can be used once.</div>
            </div>
        <?php endif; ?>

        <div class="mt-3 small-note">
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
