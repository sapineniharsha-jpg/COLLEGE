<?php
// dashboard/ai_admin.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$role = strtolower(trim((string) ($_SESSION['role'] ?? 'public')));
if ($role !== 'admin') {
    http_response_code(404);
    echo '<h2>404 Not Found</h2>';
    exit;
}

if (!function_exists('vh_e')) {
    function vh_e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('aiadm_table_exists')) {
    function aiadm_table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        return (bool) ($res && $res->num_rows > 0);
    }
}
if (!function_exists('aiadm_ensure_pending')) {
    function aiadm_ensure_pending(mysqli $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS ai_pending_ai_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question MEDIUMTEXT NOT NULL,
            proposed_answer MEDIUMTEXT NOT NULL,
            user_type VARCHAR(50) DEFAULT 'public',
            source_model VARCHAR(120) DEFAULT NULL,
            source_engine VARCHAR(80) DEFAULT 'external_ai',
            confidence FLOAT DEFAULT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            notes TEXT DEFAULT NULL,
            reviewed_by VARCHAR(80) DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
if (!function_exists('aiadm_ensure_qa')) {
    function aiadm_ensure_qa(mysqli $db): void
    {
        if (!aiadm_table_exists($db, 'ai_qa_master')) {
            $db->query("CREATE TABLE IF NOT EXISTS ai_qa_master (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question MEDIUMTEXT NOT NULL,
                answer MEDIUMTEXT NOT NULL,
                category VARCHAR(100) DEFAULT 'general',
                created_by VARCHAR(50) DEFAULT 'System',
                subcategory VARCHAR(100) DEFAULT NULL,
                tags VARCHAR(250) DEFAULT NULL,
                keywords TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                sector_access ENUM('public','student','faculty','all') DEFAULT 'all'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
}
if (!function_exists('aiadm_insert_qa')) {
    function aiadm_insert_qa(mysqli $db, string $question, string $answer, string $category, string $subcategory, string $tags, string $keywords, string $sector, string $createdBy): bool
    {
        aiadm_ensure_qa($db);
        $stmt = $db->prepare("INSERT INTO ai_qa_master
            (question, answer, category, created_by, subcategory, tags, keywords, sector_access, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ssssssss", $question, $answer, $category, $createdBy, $subcategory, $tags, $keywords, $sector);
        return (bool) $stmt->execute();
    }
}

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo 'Missing includes/db.php';
    exit;
}
require_once $dbPath;
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
    echo 'Database unavailable';
    exit;
}

// Security helper optional
$secPath = dirname(__DIR__) . '/platform_security.php';
if (file_exists($secPath)) {
    require_once $secPath;
}
$csrf = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';

aiadm_ensure_pending($mysqli);
aiadm_ensure_qa($mysqli);

$flash = '';
$flashType = 'success';
$adminId = (string) ($_SESSION['user_id'] ?? 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(false);
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'approve_pending') {
        $id = (int) ($_POST['id'] ?? 0);
        $category = trim((string) ($_POST['category'] ?? 'general'));
        $subcategory = trim((string) ($_POST['subcategory'] ?? ''));
        $tags = trim((string) ($_POST['tags'] ?? ''));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));
        $sector = trim((string) ($_POST['sector_access'] ?? 'all'));
        if (!in_array($sector, ['public', 'student', 'faculty', 'all'], true)) {
            $sector = 'all';
        }

        $row = null;
        $s = $mysqli->prepare("SELECT question, proposed_answer, user_type FROM ai_pending_ai_answers WHERE id = ? AND status = 'pending' LIMIT 1");
        if ($s) {
            $s->bind_param("i", $id);
            $s->execute();
            $r = $s->get_result();
            if ($r && $r->num_rows > 0) {
                $row = $r->fetch_assoc();
            }
        }
        if ($row && aiadm_insert_qa(
            $mysqli,
            (string) ($row['question'] ?? ''),
            (string) ($row['proposed_answer'] ?? ''),
            $category,
            $subcategory,
            $tags,
            $keywords !== '' ? $keywords : strtolower(str_replace(' ', ',', trim((string) ($row['question'] ?? '')))),
            $sector,
            $adminId
        )) {
            $u = $mysqli->prepare("UPDATE ai_pending_ai_answers
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), notes = ?
                WHERE id = ?");
            if ($u) {
                $note = trim((string) ($_POST['notes'] ?? ''));
                $u->bind_param("ssi", $adminId, $note, $id);
                $u->execute();
            }
            $flash = 'Pending AI answer approved and added to QA master.';
            $flashType = 'success';
        } else {
            $flash = 'Unable to approve pending answer.';
            $flashType = 'danger';
        }
    } elseif ($action === 'reject_pending') {
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['notes'] ?? 'Rejected by admin'));
        $u = $mysqli->prepare("UPDATE ai_pending_ai_answers
            SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), notes = ?
            WHERE id = ?");
        if ($u) {
            $u->bind_param("ssi", $adminId, $note, $id);
            $u->execute();
            $flash = 'Pending AI answer rejected.';
            $flashType = 'warning';
        }
    } elseif ($action === 'add_manual_qa') {
        $q = trim((string) ($_POST['question'] ?? ''));
        $a = trim((string) ($_POST['answer'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'general'));
        $subcategory = trim((string) ($_POST['subcategory'] ?? ''));
        $tags = trim((string) ($_POST['tags'] ?? ''));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));
        $sector = trim((string) ($_POST['sector_access'] ?? 'all'));
        if ($keywords === '') {
            $keywords = strtolower(str_replace(' ', ',', $q));
        }
        if ($q !== '' && $a !== '' && aiadm_insert_qa($mysqli, $q, $a, $category, $subcategory, $tags, $keywords, $sector, $adminId)) {
            $flash = 'Manual QA entry added.';
            $flashType = 'success';
        } else {
            $flash = 'Unable to add manual QA.';
            $flashType = 'danger';
        }
    } elseif ($action === 'resolve_unanswered') {
        $id = (int) ($_POST['id'] ?? 0);
        $answer = trim((string) ($_POST['answer'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? 'general'));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));
        $sector = trim((string) ($_POST['sector_access'] ?? 'all'));
        $q = '';
        $s = $mysqli->prepare("SELECT question FROM ai_unanswered_questions WHERE id = ? LIMIT 1");
        if ($s) {
            $s->bind_param("i", $id);
            $s->execute();
            $r = $s->get_result();
            if ($r && $r->num_rows > 0) {
                $q = (string) ($r->fetch_assoc()['question'] ?? '');
            }
        }
        if ($q !== '' && $answer !== '') {
            if ($keywords === '') {
                $keywords = strtolower(str_replace(' ', ',', $q));
            }
            if (aiadm_insert_qa($mysqli, $q, $answer, $category, '', '', $keywords, $sector, $adminId)) {
                $d = $mysqli->prepare("DELETE FROM ai_unanswered_questions WHERE id = ?");
                if ($d) {
                    $d->bind_param("i", $id);
                    $d->execute();
                }
                $flash = 'Unanswered question resolved and moved to QA master.';
                $flashType = 'success';
            } else {
                $flash = 'Unable to resolve unanswered question.';
                $flashType = 'danger';
            }
        }
    }
}

$pendingRows = [];
$resPending = $mysqli->query("SELECT * FROM ai_pending_ai_answers WHERE status = 'pending' ORDER BY created_at DESC LIMIT 150");
if ($resPending) {
    $pendingRows = $resPending->fetch_all(MYSQLI_ASSOC);
}

$unansweredRows = [];
if (aiadm_table_exists($mysqli, 'ai_unanswered_questions')) {
    $resUn = $mysqli->query("SELECT * FROM ai_unanswered_questions ORDER BY created_at DESC LIMIT 150");
    if ($resUn) {
        $unansweredRows = $resUn->fetch_all(MYSQLI_ASSOC);
    }
}

$recentQa = [];
if (aiadm_table_exists($mysqli, 'ai_qa_master')) {
    $resQa = $mysqli->query("SELECT id, question, category, sector_access, updated_at FROM ai_qa_master ORDER BY updated_at DESC LIMIT 80");
    if ($resQa) {
        $recentQa = $resQa->fetch_all(MYSQLI_ASSOC);
    }
}

$header = dirname(__DIR__) . '/includes/header.php';
if (file_exists($header)) {
    include $header;
}
?>
<style>
    .ai-admin-wrap { max-width: 1280px; margin: 0 auto; padding: 24px; }
    .banner {
        background: #fff; border: 1px solid #eef2f7; border-radius: 18px; padding: 20px;
        margin-bottom: 16px; box-shadow: 0 8px 22px rgba(0,0,0,0.04);
    }
    .banner h1 {
        margin: 0; font-size: 1.6rem; font-weight: 900;
        background: linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .card {
        background: #fff; border: 1px solid #eef2f7; border-radius: 16px; padding: 16px;
        box-shadow: 0 8px 22px rgba(0,0,0,0.04);
    }
    .card h3 { margin: 0 0 10px; font-size: 1rem; color: #334155; }
    .mono { white-space: pre-wrap; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 10px; font-size: .85rem; max-height: 180px; overflow: auto; }
    .row { display: grid; gap: 8px; margin-top: 10px; }
    .inp, .sel, .txt {
        width: 100%; border: 1px solid #dbe1e8; border-radius: 10px; padding: 9px 10px; font-size: .9rem;
    }
    .btn {
        border: none; border-radius: 10px; padding: 10px 12px; color: #fff; font-weight: 800; cursor: pointer;
        background: linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);
    }
    .btn.warn { background: #f59e0b; }
    .btn.mini { padding: 8px 10px; font-size: .8rem; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eef2f7; text-align: left; padding: 8px; font-size: .85rem; vertical-align: top; }
    th { color: #64748b; text-transform: uppercase; font-size: .74rem; letter-spacing: .5px; }
    .flash { margin-bottom: 12px; border-radius: 10px; padding: 10px 12px; font-weight: 600; }
    .flash.success { background: #dcfce7; color: #166534; }
    .flash.warning { background: #fef3c7; color: #92400e; }
    .flash.danger { background: #fee2e2; color: #991b1b; }
    @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
</style>

<div class="ai-admin-wrap">
    <div class="banner">
        <h1><i class="fas fa-brain"></i> VEL AI Admin Console</h1>
        <p style="margin:6px 0 0; color:#64748b;">Review unanswered queries, approve external AI answers, and maintain QA master by role sector (public/student/faculty/all).</p>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="flash <?= vh_e($flashType) ?>"><?= vh_e($flash) ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h3>Pending External AI Answers (Needs Admin Approval)</h3>
            <?php if (empty($pendingRows)): ?>
                <p style="color:#64748b;">No pending external answers.</p>
            <?php else: ?>
                <?php foreach ($pendingRows as $p): ?>
                    <div style="border:1px solid #eef2f7; border-radius:12px; padding:10px; margin-bottom:10px;">
                        <div style="font-size:.78rem; color:#64748b; margin-bottom:6px;">
                            #<?= (int) $p['id'] ?> | <?= vh_e($p['user_type']) ?> | <?= vh_e($p['source_model']) ?> | <?= vh_e($p['created_at']) ?>
                        </div>
                        <div class="mono"><b>Q:</b> <?= vh_e($p['question']) ?></div>
                        <div class="mono" style="margin-top:8px;"><b>A:</b> <?= vh_e($p['proposed_answer']) ?></div>
                        <form method="POST" class="row">
                            <input type="hidden" name="_csrf" value="<?= vh_e($csrf) ?>">
                            <input type="hidden" name="action" value="approve_pending">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input class="inp" name="category" placeholder="Category (default: general)" value="general">
                            <input class="inp" name="subcategory" placeholder="Subcategory (optional)">
                            <input class="inp" name="tags" placeholder="Tags (comma separated)">
                            <input class="inp" name="keywords" placeholder="Keywords (comma separated)">
                            <select class="sel" name="sector_access">
                                <option value="all">all</option>
                                <option value="public">public</option>
                                <option value="student">student</option>
                                <option value="faculty">faculty</option>
                            </select>
                            <textarea class="txt" name="notes" rows="2" placeholder="Review note (optional)"></textarea>
                            <button class="btn mini">Approve and Add to QA Master</button>
                        </form>
                        <form method="POST" class="row">
                            <input type="hidden" name="_csrf" value="<?= vh_e($csrf) ?>">
                            <input type="hidden" name="action" value="reject_pending">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <textarea class="txt" name="notes" rows="2" placeholder="Rejection reason"></textarea>
                            <button class="btn warn mini">Reject</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Unanswered Questions (Manual Resolution)</h3>
            <?php if (empty($unansweredRows)): ?>
                <p style="color:#64748b;">No unanswered items.</p>
            <?php else: ?>
                <?php foreach ($unansweredRows as $u): ?>
                    <div style="border:1px solid #eef2f7; border-radius:12px; padding:10px; margin-bottom:10px;">
                        <div style="font-size:.78rem; color:#64748b; margin-bottom:6px;">
                            #<?= (int) $u['id'] ?> | <?= vh_e($u['user_type']) ?> | <?= vh_e($u['created_at']) ?>
                        </div>
                        <div class="mono"><b>Q:</b> <?= vh_e($u['question']) ?></div>
                        <form method="POST" class="row">
                            <input type="hidden" name="_csrf" value="<?= vh_e($csrf) ?>">
                            <input type="hidden" name="action" value="resolve_unanswered">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <textarea class="txt" name="answer" rows="3" placeholder="Write approved answer" required></textarea>
                            <input class="inp" name="category" value="general" placeholder="Category">
                            <input class="inp" name="keywords" placeholder="Keywords">
                            <select class="sel" name="sector_access">
                                <option value="all">all</option>
                                <option value="public">public</option>
                                <option value="student">student</option>
                                <option value="faculty">faculty</option>
                            </select>
                            <button class="btn mini">Resolve and Add to QA Master</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid" style="margin-top:14px;">
        <div class="card">
            <h3>Manual QA Add</h3>
            <form method="POST" class="row">
                <input type="hidden" name="_csrf" value="<?= vh_e($csrf) ?>">
                <input type="hidden" name="action" value="add_manual_qa">
                <textarea class="txt" name="question" rows="2" placeholder="Question" required></textarea>
                <textarea class="txt" name="answer" rows="3" placeholder="Answer" required></textarea>
                <input class="inp" name="category" value="general" placeholder="Category">
                <input class="inp" name="subcategory" placeholder="Subcategory">
                <input class="inp" name="tags" placeholder="Tags">
                <input class="inp" name="keywords" placeholder="Keywords">
                <select class="sel" name="sector_access">
                    <option value="all">all</option>
                    <option value="public">public</option>
                    <option value="student">student</option>
                    <option value="faculty">faculty</option>
                </select>
                <button class="btn">Add to QA Master</button>
            </form>
        </div>

        <div class="card">
            <h3>Recent QA Master Entries</h3>
            <?php if (empty($recentQa)): ?>
                <p style="color:#64748b;">No QA entries.</p>
            <?php else: ?>
                <div style="max-height:380px; overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Question</th>
                                <th>Category</th>
                                <th>Sector</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentQa as $q): ?>
                                <tr>
                                    <td><?= (int) $q['id'] ?></td>
                                    <td><?= vh_e(strlen((string) $q['question']) > 120 ? substr((string) $q['question'], 0, 117) . '...' : (string) $q['question']) ?></td>
                                    <td><?= vh_e($q['category']) ?></td>
                                    <td><?= vh_e($q['sector_access']) ?></td>
                                    <td><?= vh_e($q['updated_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$footer = dirname(__DIR__) . '/includes/footer.php';
if (file_exists($footer)) {
    include $footer;
}
?>
