<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$name = (string) ($_SESSION['name'] ?? $_SESSION['NAME'] ?? 'User');
$role = strtolower((string) ($_SESSION['role'] ?? 'public'));
$avatar = strtoupper(substr($name, 0, 1));
if ($avatar === '') {
    $avatar = 'U';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>VEL AI - Institutional Assistant</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#bc1888">
    <script src="/assets/js/pwa-init.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --insta-gradient: linear-gradient(45deg, #405de6, #5851db, #833ab4, #c13584, #e1306c, #fd1d1d);
            --glass-bg: rgba(255,255,255,.88);
            --user-msg: linear-gradient(135deg, #833ab4, #fd1d1d);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Inter", system-ui, sans-serif;
            min-height: 100vh;
            background: var(--insta-gradient);
            background-size: 220% 220%;
            animation: bgMove 14s ease infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }
        @keyframes bgMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .chat-shell {
            width: min(1240px, 100%);
            height: min(92vh, 900px);
            background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.3);
            border-radius: 20px;
            backdrop-filter: blur(12px);
            display: grid;
            grid-template-columns: 280px 1fr;
            overflow: hidden;
            box-shadow: 0 22px 58px rgba(0,0,0,.25);
        }
        .side {
            background: rgba(255,255,255,.72);
            border-right: 1px solid rgba(255,255,255,.5);
            padding: 18px 14px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .brand { display: flex; align-items: center; gap: 8px; font-weight: 900; color: #111827; }
        .brand i {
            width: 32px; height: 32px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--insta-gradient); color: #fff;
        }
        .user {
            background: #fff; border-radius: 14px; padding: 10px;
            display: flex; gap: 10px; align-items: center;
            border: 1px solid #eef2f7;
        }
        .av {
            width: 40px; height: 40px; border-radius: 999px;
            background: var(--insta-gradient); color: #fff;
            display: inline-flex; align-items: center; justify-content: center; font-weight: 800;
        }
        .chips .title { font-size: .74rem; color: #6b7280; font-weight: 700; text-transform: uppercase; margin: 4px 0; }
        .chip {
            margin: 8px 0; width: 100%; text-align: left; border: 1px solid #eceff3;
            background: #fff; border-radius: 10px; padding: 10px; color: #374151; cursor: pointer;
            font-weight: 600;
        }
        .chip:hover { border-color: #c13584; }
        .side-links { margin-top: auto; display: grid; gap: 8px; }
        .side-links a {
            text-decoration: none; font-weight: 700; border-radius: 10px; padding: 10px;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .go-dash { background: #f3f4f6; color: #1f2937; }
        .logout { background: #fff1f2; color: #dc2626; }

        .main {
            background: var(--glass-bg);
            display: flex; flex-direction: column;
            position: relative;
        }
        .head {
            padding: 12px 16px; border-bottom: 1px solid #ebedf1;
            display: flex; align-items: center; justify-content: space-between;
            background: rgba(255,255,255,.7);
        }
        .status { font-size: .8rem; color: #10b981; font-weight: 700; }
        .msgs {
            flex: 1; overflow-y: auto; padding: 16px;
            display: flex; flex-direction: column; gap: 10px;
        }
        .msg {
            max-width: 80%;
            border-radius: 14px;
            padding: 10px 12px;
            font-size: .92rem;
            line-height: 1.45;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            white-space: pre-wrap;
        }
        .msg.ai { align-self: flex-start; background: #fff; color: #1f2937; border-bottom-left-radius: 4px; }
        .msg.user { align-self: flex-end; background: var(--user-msg); color: #fff; border-bottom-right-radius: 4px; }
        .typing { display: none; align-self: flex-start; font-size: .85rem; color: #6b7280; }
        .input {
            padding: 12px; border-top: 1px solid #ebedf1;
            background: rgba(255,255,255,.88);
            display: flex; gap: 8px;
        }
        .input input {
            flex: 1; border: 1px solid #dbe1e8; border-radius: 999px;
            padding: 10px 14px; font-size: .95rem;
        }
        .input button {
            border: none; border-radius: 999px; padding: 10px 14px;
            color: #fff; font-weight: 800; cursor: pointer; background: var(--insta-gradient);
        }
        @media (max-width: 900px) {
            .chat-shell { grid-template-columns: 1fr; height: 100vh; border-radius: 0; }
            .side { display: none; }
        }
    </style>
</head>
<body>
<div class="chat-shell">
    <aside class="side">
        <div class="brand"><i class="fas fa-brain"></i> VEL AI</div>
        <div class="user">
            <span class="av"><?= htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') ?></span>
            <div>
                <div style="font-weight:800; font-size:.9rem; color:#111827;"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:.78rem; color:#6b7280;">Role: <?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="chips">
            <div class="title">Suggested prompts</div>
            <button class="chip" onclick="sendSuggestion('Tell me about admission process')"><i class="fas fa-graduation-cap"></i> Admission process</button>
            <button class="chip" onclick="sendSuggestion('What are hostel facilities?')"><i class="fas fa-building"></i> Hostel facilities</button>
            <button class="chip" onclick="sendSuggestion('How are placements at Vel Tech High Tech?')"><i class="fas fa-briefcase"></i> Placements</button>
            <button class="chip" onclick="sendSuggestion('Give me principal office contact details')"><i class="fas fa-address-book"></i> Principal contact</button>
        </div>
        <div class="side-links">
            <a class="go-dash" href="/dashboard/dashboard.php"><i class="fas fa-table-columns"></i> Dashboard</a>
            <a class="logout" href="/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="head">
            <div style="font-weight:800; color:#111827;"><i class="fas fa-robot"></i> Institutional AI Assistant</div>
            <div class="status"><i class="fas fa-circle"></i> Online</div>
        </div>
        <div class="msgs" id="messages">
            <div class="msg ai">Hello <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> 👋
I am VEL AI. Ask anything about admissions, academics, facilities, events, or institutional information.</div>
            <div class="typing" id="typing">VEL AI is typing...</div>
        </div>
        <div class="input">
            <input id="q" type="text" placeholder="Ask something..." autocomplete="off">
            <button onclick="sendMsg()"><i class="fas fa-paper-plane"></i> Send</button>
        </div>
    </main>
</div>

<script>
const box = document.getElementById('messages');
const input = document.getElementById('q');
const typing = document.getElementById('typing');

function addMsg(text, type) {
    const d = document.createElement('div');
    d.className = 'msg ' + type;
    d.textContent = text;
    box.insertBefore(d, typing);
    box.scrollTop = box.scrollHeight;
}

function sendSuggestion(s) {
    input.value = s;
    sendMsg();
}

async function sendMsg() {
    const q = input.value.trim();
    if (!q) return;
    addMsg(q, 'user');
    input.value = '';
    typing.style.display = 'block';
    box.scrollTop = box.scrollHeight;
    try {
        const res = await fetch('/ai/ai_engine.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({question: q, user_context: '<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>'})
        });
        const data = await res.json();
        typing.style.display = 'none';
        const text = (data && data.data && data.data.answer) ? data.data.answer :
                     (data && data.reply) ? data.reply :
                     'Sorry, I could not process that request.';
        addMsg(text, 'ai');
    } catch (e) {
        typing.style.display = 'none';
        addMsg('Connection error. Please try again.', 'ai');
    }
}

input.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendMsg(); });
</script>
</body>
</html>
