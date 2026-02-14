<?php
// velai.php
// Institutional AI interface with vision/mission + daily kural.

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

if (!function_exists('vh_e')) {
    function vh_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('table_exists')) {
    function table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        return (bool) ($res && $res->num_rows > 0);
    }
}
if (!function_exists('safe_fetch_first')) {
    function safe_fetch_first(mysqli $db, string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
        return null;
    }
}
if (!function_exists('mapDeptToCategory')) {
    function mapDeptToCategory($code)
    {
        $code = strtolower(trim((string) $code));
        if ($code === '') {
            return null;
        }
        if (strpos($code, 'structural') !== false) {
            return 'Structural';
        }
        if (strpos($code, 'aiml') !== false || strpos($code, 'machine learning') !== false || $code === 'ml' || strpos($code, 'cse-aiml') !== false || strpos($code, 'cseaiml') !== false) {
            return 'CSE-AIML';
        }
        if (strpos($code, 'aids') !== false || strpos($code, 'ai&ds') !== false || strpos($code, 'ai & ds') !== false || strpos($code, 'data science') !== false || strpos($code, 'artificial intelligence') !== false) {
            return 'AI&DS';
        }
        if (strpos($code, 'cse') !== false || strpos($code, 'computer science') !== false || strpos($code, 'computer engg') !== false || strpos($code, 'computer engineering') !== false) {
            return 'CSE';
        }
        if (strpos($code, 'ece') !== false || strpos($code, 'electronics') !== false) {
            return 'ECE';
        }
        if (strpos($code, 'mech') !== false || strpos($code, 'mechanical') !== false) {
            return 'Mechanical';
        }
        if (strpos($code, 'civil') !== false) {
            return 'Civil';
        }
        if ($code === 'it' || strpos($code, 'information technology') !== false || strpos($code, 'b.tech- it') !== false) {
            return 'IT';
        }
        if (strpos($code, 'bio') !== false || strpos($code, 'biotech') !== false) {
            return 'Biotechnology';
        }
        if (strpos($code, 'chem') !== false || strpos($code, 'chemical') !== false) {
            return 'Chemical';
        }
        if (strpos($code, 'mba') !== false) {
            return 'MBA';
        }
        if (strpos($code, 's&h') !== false || $code === 'sh' || strpos($code, 'science and humanities') !== false || strpos($code, 'first year') !== false) {
            return 'Science and Humanities';
        }
        return null;
    }
}

// Optional config include
$configPath = __DIR__ . '/includes/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

// DB include
$dbPath = __DIR__ . '/includes/db.php';
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo 'Missing includes/db.php';
    exit;
}
require_once $dbPath;

$dbConn = null;
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $dbConn = $mysqli;
} elseif (isset($conn) && $conn instanceof mysqli) {
    $dbConn = $conn;
} elseif (isset($con) && $con instanceof mysqli) {
    $dbConn = $con;
} elseif (isset($connection) && $connection instanceof mysqli) {
    $dbConn = $connection;
} elseif (isset($GLOBALS['db']) && is_object($GLOBALS['db']) && method_exists($GLOBALS['db'], 'getConnection')) {
    $candidate = $GLOBALS['db']->getConnection();
    if ($candidate instanceof mysqli) {
        $dbConn = $candidate;
    }
}
if (!$dbConn) {
    foreach ($GLOBALS as $value) {
        if ($value instanceof mysqli) {
            $dbConn = $value;
            break;
        }
    }
}
if (!$dbConn || $dbConn->connect_error) {
    http_response_code(500);
    echo 'Database unavailable.';
    exit;
}
$db = $dbConn;

$user_id = (string) ($_SESSION['user_id'] ?? '');
$user_name = (string) ($_SESSION['name'] ?? $_SESSION['NAME'] ?? 'Guest');

// 1) Institution Vision/Mission
$inst_data = ['vision' => 'Institution vision not configured.', 'mission' => 'Institution mission not configured.'];
if (table_exists($db, 'ai_vision_mission')) {
    $inst = safe_fetch_first($db, "SELECT vision, mission FROM ai_vision_mission WHERE category = 'Institution' LIMIT 1");
    if ($inst) {
        $inst_data = $inst;
    }
}

// 2) Resolve department from user profile
$dept_raw_name = '';
$dept_key = null;

if (table_exists($db, 'employee_details')) {
    $row = safe_fetch_first($db, "SELECT DEPARTMENT FROM employee_details WHERE ID_NO = ? LIMIT 1", "s", [$user_id]);
    if ($row && !empty($row['DEPARTMENT'])) {
        $dept_raw_name = (string) $row['DEPARTMENT'];
    }
}
if ($dept_raw_name === '' && table_exists($db, 'employee_details1')) {
    $row = safe_fetch_first($db, "SELECT DEPARTMENT FROM employee_details1 WHERE ID_NO = ? LIMIT 1", "s", [$user_id]);
    if ($row && !empty($row['DEPARTMENT'])) {
        $dept_raw_name = (string) $row['DEPARTMENT'];
    }
}
if ($dept_raw_name === '' && table_exists($db, 'students_login_master')) {
    $row = safe_fetch_first($db, "SELECT Dept FROM students_login_master WHERE IDNo = ? LIMIT 1", "s", [$user_id]);
    if ($row && !empty($row['Dept'])) {
        $dept_raw_name = (string) $row['Dept'];
    }
}
if ($dept_raw_name === '' && table_exists($db, 'students_batch_25_26')) {
    $row = safe_fetch_first($db, "SELECT department FROM students_batch_25_26 WHERE id_no = ? LIMIT 1", "s", [$user_id]);
    if ($row && !empty($row['department'])) {
        $dept_raw_name = (string) $row['department'];
    }
}

if ($dept_raw_name !== '') {
    $mapped = mapDeptToCategory($dept_raw_name);
    $dept_key = $mapped ?: trim($dept_raw_name);
}

// 3) Department vision/mission
$dept_data = null;
if ($dept_key && table_exists($db, 'ai_vision_mission')) {
    $dept_data = safe_fetch_first($db, "SELECT vision, mission FROM ai_vision_mission WHERE category = ? LIMIT 1", "s", [$dept_key]);
}

// 4) Daily Tirukkural
$daily_kural = null;
$today_date = date('Y-m-d');
if (table_exists($db, 'tirukkural') && table_exists($db, 'user_daily_kural')) {
    $qk = $db->prepare("SELECT t.kural_no, t.section, t.tamil, t.english
        FROM user_daily_kural u
        JOIN tirukkural t ON u.kural_no = t.kural_no
        WHERE u.user_id = ? AND u.shown_date = ?
        LIMIT 1");
    if ($qk) {
        $qk->bind_param("ss", $user_id, $today_date);
        $qk->execute();
        $rk = $qk->get_result();
        if ($rk && $rk->num_rows > 0) {
            $daily_kural = $rk->fetch_assoc();
        }
    }
    if (!$daily_kural) {
        $rand_k = $db->query("SELECT * FROM tirukkural ORDER BY RAND() LIMIT 1");
        if ($rand_k && $row_k = $rand_k->fetch_assoc()) {
            $daily_kural = $row_k;
            $ins_k = $db->prepare("INSERT INTO user_daily_kural (user_id, kural_no, shown_date) VALUES (?, ?, ?)");
            if ($ins_k) {
                $kNo = (int) ($row_k['kural_no'] ?? 0);
                $ins_k->bind_param("sis", $user_id, $kNo, $today_date);
                $ins_k->execute();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>VelAI - Intelligent Assistant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#bc1888">
    <script src="/assets/js/pwa-init.js" defer></script>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="VTHT Portal">
    <link rel="apple-touch-icon" href="/assets/images/icon-192.png">
    <style>
        :root {
            --insta-gradient: linear-gradient(45deg, #405de6, #5851db, #833ab4, #c13584, #e1306c, #fd1d1d);
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --user-msg-bg: linear-gradient(135deg, #833ab4, #c13584);
            --ai-msg-bg: #ffffff;
        }
        * { box-sizing: border-box; outline: none; }
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            height: 100vh;
            width: 100vw;
            background: var(--insta-gradient);
            background-size: 200% 200%;
            animation: gradientBG 15s ease infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .chat-container {
            width: 95%;
            max-width: 1200px;
            height: 90vh;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.37);
            display: flex;
            overflow: hidden;
            position: relative;
        }
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.6);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            transition: transform 0.3s ease;
            z-index: 10;
        }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 2rem; color: #333; }
        .brand i {
            font-size: 1.8rem;
            background: var(--insta-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .brand h2 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .user-card {
            background: rgba(255, 255, 255, 0.8);
            padding: 15px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 2rem;
            border: 1px solid rgba(255,255,255,0.5);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .avatar {
            width: 45px; height: 45px; border-radius: 50%;
            background: var(--insta-gradient); color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 1.2rem;
        }
        .suggestions h4 {
            color: #555; font-size: 0.75rem; text-transform: uppercase;
            letter-spacing: 1px; margin-bottom: 1rem; font-weight: 600;
        }
        .chip {
            display: flex; align-items: center; gap: 10px; padding: 12px;
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid transparent; border-radius: 12px;
            margin-bottom: 8px; cursor: pointer; font-size: 0.9rem;
            color: #333; transition: all 0.2s;
        }
        .chip:hover {
            background: white; border-color: #c13584;
            transform: translateX(4px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .chip i { color: #c13584; }
        .logout {
            margin-top: auto; color: #fd1d1d; text-decoration: none;
            display: flex; align-items: center; gap: 10px; font-weight: 600;
            padding: 12px; border-radius: 12px; background: rgba(255,255,255,0.5);
            transition: background 0.2s;
        }
        .logout:hover { background: white; }
        .main-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: rgba(255, 255, 255, 0.9);
            position: relative;
        }
        .watermark-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            height: 90%;
            pointer-events: none;
            z-index: 0;
            opacity: 0.15;
            user-select: none;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .vm-table { width: 100%; border-collapse: collapse; color: #000; margin-bottom: 1.6rem; }
        .vm-table th, .vm-table td {
            border: 2px solid #333;
            padding: 12px;
            vertical-align: middle;
            text-align: left;
            font-size: 0.82rem;
            line-height: 1.45;
        }
        .vm-table th {
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.92rem;
            border-bottom-width: 3px;
        }
        .vm-col-header { width: 50%; }
        .section-header {
            margin: 0 0 8px 0;
            color: #555;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 800;
            align-self: flex-start;
        }
        .chat-header {
            padding: 1rem 2rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.5);
            backdrop-filter: blur(5px);
            z-index: 2;
        }
        .status-dot {
            height: 8px; width: 8px; background: #10b981;
            border-radius: 50%; display: inline-block;
            margin-right: 5px; box-shadow: 0 0 5px #10b981;
        }
        .messages-area {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            scroll-behavior: smooth;
            z-index: 1;
        }
        .msg {
            max-width: 75%;
            padding: 1rem 1.4rem;
            border-radius: 18px;
            font-size: 0.95rem;
            line-height: 1.6;
            position: relative;
            animation: slideIn 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .msg.ai {
            align-self: flex-start;
            background: var(--ai-msg-bg);
            color: #333;
            border-bottom-left-radius: 4px;
        }
        .msg.user {
            align-self: flex-end;
            background: var(--user-msg-bg);
            color: white;
            border-bottom-right-radius: 4px;
        }
        .input-wrapper {
            padding: 1.5rem 2rem;
            background: rgba(255,255,255,0.9);
            border-top: 1px solid rgba(0,0,0,0.05);
            z-index: 2;
        }
        .input-group {
            display: flex; gap: 10px; background: #f0f2f5;
            padding: 6px 6px 6px 20px; border-radius: 30px;
            border: 1px solid transparent; transition: all 0.2s;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .input-group:focus-within {
            background: white; border-color: #c13584;
            box-shadow: 0 0 0 3px rgba(193, 53, 132, 0.1);
        }
        .input-field {
            flex: 1; border: none; background: transparent;
            font-size: 1rem; font-family: inherit;
        }
        .btn-send {
            width: 45px; height: 45px; border-radius: 50%;
            border: none; background: var(--insta-gradient);
            color: white; cursor: pointer; display: flex;
            align-items: center; justify-content: center;
            transition: transform 0.2s;
            box-shadow: 0 4px 10px rgba(193, 53, 132, 0.3);
        }
        .typing {
            align-self: flex-start; background: white; padding: 12px 20px;
            border-radius: 20px; border-bottom-left-radius: 4px;
            display: none; margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .dot {
            width: 6px; height: 6px; background: #c13584;
            border-radius: 50%; display: inline-block;
            animation: bounce 1.4s infinite ease-in-out; margin: 0 2px;
        }
        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1); }
        }
        .dot:nth-child(1) { animation-delay: -0.32s; }
        .dot:nth-child(2) { animation-delay: -0.16s; }
        @media (max-width: 768px) {
            .chat-container { width: 100%; height: 100%; border-radius: 0; border: none; }
            .sidebar {
                position: absolute; height: 100%;
                background: rgba(255,255,255,0.95); backdrop-filter: blur(15px);
                transform: translateX(-100%); box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            }
            .sidebar.active { transform: translateX(0); }
            .mobile-toggle { display: block; cursor: pointer; font-size: 1.2rem; color: #333; }
            .msg { max-width: 85%; }
            .chat-header, .input-wrapper { padding: 1rem; }
            .watermark-content { width: 95%; }
            .vm-table th { font-size: 0.76rem; }
            .vm-table td { font-size: 0.68rem; padding: 8px; }
        }
        @media (min-width: 769px) { .mobile-toggle { display: none; } }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="sidebar" id="sidebar">
            <div class="brand">
                <i class="fas fa-robot"></i>
                <h2>Vel AI</h2>
            </div>
            <div class="user-card">
                <div class="avatar"><?= vh_e(strtoupper(substr($user_name, 0, 1))) ?></div>
                <div>
                    <div style="font-weight: 600; font-size: 0.95rem; color:#333;"><?= vh_e($user_name) ?></div>
                    <div style="font-size: 0.75rem; color: #666;">
                        <i class="fas fa-check-circle" style="color: #10b981;"></i> Authenticated
                    </div>
                </div>
            </div>
            <div class="suggestions">
                <h4>Suggested Topics</h4>
                <div class="chip" onclick="sendSuggestion('What courses are offered?')">
                    <i class="fas fa-graduation-cap"></i> Courses Offered
                </div>
                <div class="chip" onclick="sendSuggestion('Tell me about hostel facilities')">
                    <i class="fas fa-building"></i> Hostel Details
                </div>
                <div class="chip" onclick="sendSuggestion('What is the admission process?')">
                    <i class="fas fa-file-alt"></i> Admission Process
                </div>
                <div class="chip" onclick="sendSuggestion('How are the placements?')">
                    <i class="fas fa-briefcase"></i> Placements
                </div>
            </div>
            <a href="/auth/logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
        </div>

        <div class="main-chat">
            <div class="chat-header">
                <div style="display:flex; align-items:center; gap:15px;">
                    <i class="fas fa-bars mobile-toggle" onclick="toggleSidebar()"></i>
                    <div>
                        <h3 style="margin:0; font-size:1.1rem; color:#333;">Vel High Tech Assistant</h3>
                        <span style="font-size:0.8rem; color:#666;">
                            <span class="status-dot"></span>Online • Institutional AI
                        </span>
                    </div>
                </div>
            </div>

            <div class="watermark-content">
                <?php if ($daily_kural): ?>
                <div class="kural-box" style="width:100%; background:rgba(255,255,255,0.7); padding:12px; border-radius:12px; border-left:5px solid #e1306c; margin-bottom:16px; box-shadow:0 4px 10px rgba(0,0,0,0.05);">
                    <div style="font-size:0.76rem; font-weight:700; color:#e1306c; text-transform:uppercase; margin-bottom:5px;">
                        Daily Wisdom (Kural #<?= (int) ($daily_kural['kural_no'] ?? 0) ?>) • <?= vh_e($daily_kural['section'] ?? '') ?>
                    </div>
                    <div style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:4px;"><?= nl2br(vh_e($daily_kural['tamil'] ?? '')) ?></div>
                    <div style="font-size:0.82rem; font-style:italic; color:#555;"><?= vh_e($daily_kural['english'] ?? '') ?></div>
                </div>
                <?php endif; ?>
                <div class="section-header">INSTITUTION</div>
                <table class="vm-table">
                    <thead>
                        <tr>
                            <th class="vm-col-header">VISION</th>
                            <th class="vm-col-header">MISSION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= nl2br(vh_e($inst_data['vision'] ?? '')) ?></td>
                            <td><?= nl2br(vh_e($inst_data['mission'] ?? '')) ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ($dept_data): ?>
                <div class="section-header">DEPARTMENT: <?= vh_e(strtoupper((string) $dept_key)) ?></div>
                <table class="vm-table">
                    <thead>
                        <tr>
                            <th class="vm-col-header">VISION</th>
                            <th class="vm-col-header">MISSION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= nl2br(vh_e($dept_data['vision'] ?? '')) ?></td>
                            <td><?= nl2br(vh_e($dept_data['mission'] ?? '')) ?></td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="messages-area" id="messagesBox">
                <div class="msg ai">
                    Hello <strong><?= vh_e($user_name) ?></strong>!<br><br>
                    I am Vel AI. I can assist you with:<br>
                    - Campus details and maps<br>
                    - Hostel fee and facilities<br>
                    - Faculty contacts and department info
                </div>
                <div class="typing" id="typingLoader"><span class="dot"></span><span class="dot"></span><span class="dot"></span></div>
            </div>

            <div class="input-wrapper">
                <div class="input-group">
                    <input type="text" id="userIn" class="input-field" placeholder="Ask something..." autocomplete="off">
                    <button class="btn-send" onclick="sendMsg()"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const input = document.getElementById('userIn');
        const box = document.getElementById('messagesBox');
        const loader = document.getElementById('typingLoader');

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }
        function sendSuggestion(text) {
            input.value = text;
            sendMsg();
            if (window.innerWidth <= 768) toggleSidebar();
        }
        async function sendMsg() {
            const text = input.value.trim();
            if (!text) return;
            addBubble(text, 'user');
            input.value = '';
            loader.style.display = 'block';
            box.scrollTop = box.scrollHeight;
            try {
                const res = await fetch('/ai/ai_engine.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        question: text,
                        user_context: '<?= vh_e((string) ($_SESSION['role'] ?? 'public')) ?>'
                    })
                });
                const data = await res.json();
                loader.style.display = 'none';
                let replyText = '';
                if (data.status === 'success' && data.data && data.data.answer) {
                    replyText = data.data.answer;
                } else if (data.reply) {
                    replyText = data.reply;
                } else {
                    replyText = "I couldn't find an answer to that. Please try rephrasing.";
                }
                addBubble(replyText, 'ai');
            } catch (err) {
                loader.style.display = 'none';
                addBubble("Connection error. Please try again.", 'ai');
            }
        }
        function addBubble(content, type) {
            const div = document.createElement('div');
            div.className = `msg ${type}`;
            div.innerHTML = String(content).replace(/\n/g, '<br>');
            box.insertBefore(div, loader);
            box.scrollTop = box.scrollHeight;
        }
        input.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendMsg(); });
    </script>
</body>
</html>
