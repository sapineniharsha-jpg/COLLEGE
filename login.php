<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CAPTCHA regenerated every load
$num1 = random_int(1, 9);
$num2 = random_int(1, 9);
$_SESSION['captcha_code'] = $num1 + $num2;

$login_error = trim((string) ($_GET['err'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>VEL AI - Institutional Login</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#bc1888">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="/assets/images/icon-192.png">
    <script src="/assets/js/pwa-init.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --insta-gradient: linear-gradient(45deg, #405de6, #5851db, #833ab4, #c13584, #e1306c, #fd1d1d);
            --glass-bg: rgba(255, 255, 255, 0.16);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Inter", system-ui, sans-serif;
            background: var(--insta-gradient);
            background-size: 220% 220%;
            animation: bgMove 14s ease infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        @keyframes bgMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .portal {
            width: min(1100px, 100%);
            border-radius: 22px;
            padding: 26px;
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            box-shadow: 0 15px 45px rgba(0,0,0,.2);
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 18px;
            color: #fff;
        }
        .brand h1 { margin: 0 0 8px; font-size: 2.4rem; }
        .brand p { margin: 0 0 16px; opacity: .9; line-height: 1.5; }
        .feature { margin: 9px 0; display: flex; gap: 10px; align-items: center; font-size: .95rem; }
        .feature i { width: 26px; height: 26px; border-radius: 999px; background: rgba(255,255,255,.18); display: inline-flex; align-items: center; justify-content: center; }

        .cards { display: grid; gap: 12px; }
        .role-card {
            background: rgba(255,255,255,.96);
            color: #111827;
            border-radius: 14px;
            padding: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 2px solid transparent;
            transition: .2s;
        }
        .role-card:hover { transform: translateY(-2px); border-color: #c13584; }
        .r-left { display: flex; gap: 12px; align-items: center; }
        .r-icon {
            width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
            background: #f3f4f6; color: #4b5563; font-size: 1.2rem;
        }
        .role-card.ai .r-icon { background: var(--insta-gradient); color: #fff; }
        .r-title { font-weight: 800; }
        .r-sub { color: #6b7280; font-size: .82rem; }

        .modal {
            position: fixed; inset: 0; z-index: 1000;
            display: none; align-items: center; justify-content: center;
            background: rgba(0,0,0,.58);
            padding: 14px;
        }
        .modal.active { display: flex; }
        .window {
            width: min(430px, 100%);
            border-radius: 18px;
            background: #fff;
            color: #111827;
            box-shadow: 0 20px 50px rgba(0,0,0,.3);
            padding: 20px;
            position: relative;
        }
        .close { position: absolute; right: 14px; top: 12px; font-size: 1.1rem; color: #6b7280; border: none; background: transparent; cursor: pointer; }
        .w-title { margin: 0; font-size: 1.4rem; font-weight: 800; background: var(--insta-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .w-sub { margin: 4px 0 14px; color: #6b7280; font-size: .9rem; }

        .fg { margin-bottom: 10px; position: relative; }
        .fg i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .in {
            width: 100%; padding: 12px 12px 12px 38px; border-radius: 12px; border: 1px solid #e5e7eb; font-size: .95rem;
        }
        .in:focus { outline: none; border-color: #9333ea; box-shadow: 0 0 0 3px rgba(147, 51, 234, 0.1); }
        .captcha {
            border: 1px solid #e5e7eb; border-radius: 12px; background: #f9fafb;
            padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px;
        }
        .eq { font-family: monospace; font-size: 1.1rem; font-weight: 700; background: #fff; border: 1px dashed #d1d5db; border-radius: 8px; padding: 6px 10px; }
        .cap-input { width: 72px; text-align: center; border: none; border-bottom: 2px solid #cbd5e1; background: transparent; font-weight: 700; padding: 4px; }
        .cap-input:focus { outline: none; border-bottom-color: #9333ea; }
        .btn {
            width: 100%; border: none; border-radius: 12px; padding: 12px 14px; font-size: .95rem; font-weight: 800; color: #fff;
            background: var(--insta-gradient); cursor: pointer;
        }
        .btn:disabled { opacity: .7; cursor: not-allowed; }
        .row { display: grid; gap: 10px; }
        .line { margin: 12px 0; border-top: 1px solid #e5e7eb; position: relative; }
        .line span {
            position: absolute; left: 50%; top: 0; transform: translate(-50%, -50%);
            background: #fff; color: #9ca3af; font-size: .75rem; padding: 0 10px;
        }
        .g-btn {
            width: 100%; border: 1px solid #d1d5db; border-radius: 12px; padding: 10px 12px;
            background: #fff; display: inline-flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer;
        }
        .small-links { margin-top: 10px; text-align: right; font-size: .85rem; }
        .small-links a { color: #7c3aed; text-decoration: none; }
        .error { margin-bottom: 10px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 10px; padding: 10px; font-size: .88rem; }
        @media (max-width: 900px) { .portal { grid-template-columns: 1fr; } .brand { text-align: center; } }
    </style>
</head>
<body>
    <div class="portal">
        <div class="brand">
            <h1>VEL AI Portal</h1>
            <p>Institutional AI + eGovernance platform. Student & faculty login with role privileges, public AI access by mobile/Google.</p>
            <div class="feature"><i class="fas fa-brain"></i> <span>VEL AI assistant in all user logins</span></div>
            <div class="feature"><i class="fas fa-layer-group"></i> <span>Responsive SaaS PWA design</span></div>
            <div class="feature"><i class="fas fa-user-shield"></i> <span>Role-based access & secured modules</span></div>
        </div>
        <div class="cards">
            <div class="role-card" onclick="openModal('institutional')">
                <div class="r-left">
                    <span class="r-icon"><i class="fas fa-university"></i></span>
                    <div>
                        <div class="r-title">Institutional Login</div>
                        <div class="r-sub">Students (VH...) and Faculty (HTS...)</div>
                    </div>
                </div>
                <i class="fas fa-chevron-right"></i>
            </div>
            <div class="role-card ai" onclick="openModal('public')">
                <div class="r-left">
                    <span class="r-icon"><i class="fas fa-brain"></i></span>
                    <div>
                        <div class="r-title">Chat with VEL AI</div>
                        <div class="r-sub">Public access with mobile + name / Google</div>
                    </div>
                </div>
                <i class="fas fa-chevron-right"></i>
            </div>
        </div>
    </div>

    <div class="modal" id="mainModal" onclick="if(event.target===this)closeModal()">
        <div class="window">
            <button class="close" onclick="closeModal()" aria-label="Close"><i class="fas fa-times"></i></button>
            <h2 class="w-title" id="mTitle">Login</h2>
            <div class="w-sub" id="mSub">Enter your credentials</div>
            <?php if ($login_error !== ''): ?>
                <div class="error"><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form id="authForm" class="row" onsubmit="handleLogin(event)">
                <input type="hidden" id="userRole" name="role" value="institutional">
                <div class="fg">
                    <i class="fas fa-id-card" id="idIcon"></i>
                    <input class="in" id="idInput" name="user_id" placeholder="ID / Mobile" required>
                </div>
                <div class="fg" id="nameGroup" style="display:none;">
                    <i class="fas fa-user"></i>
                    <input class="in" id="nameInput" name="public_name" placeholder="Your Name">
                </div>
                <div class="fg" id="passwordGroup">
                    <i class="fas fa-lock"></i>
                    <input class="in" type="password" id="passwordInput" name="password" placeholder="Password">
                </div>
                <div class="captcha">
                    <span class="eq"><?= $num1 ?> + <?= $num2 ?></span>
                    <span>=</span>
                    <input class="cap-input" type="number" name="captcha" placeholder="?" required>
                    <button type="button" class="close" style="position:static; font-size:.95rem;" onclick="window.location.reload()" title="Refresh captcha">
                        <i class="fas fa-rotate"></i>
                    </button>
                </div>
                <button id="submitBtn" class="btn">Enter Portal <i class="fas fa-arrow-right"></i></button>
                <div class="small-links" id="forgotWrap"><a href="/forgot_password.php">Forgot Password?</a></div>
                <div id="googleWrap" style="display:none;">
                    <div class="line"><span>OR</span></div>
                    <button type="button" class="g-btn" onclick="window.location.href='/auth/google_oauth.php'">
                        <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" width="18" alt="Google"> Continue with Google
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const cfg = {
            institutional: {title: 'Institutional Login', sub: 'Student/Faculty ID + Password', showPass: true, showName: false, showGoogle: false, icon: 'fa-university'},
            public: {title: 'Public VEL AI Access', sub: 'Enter mobile number and name', showPass: false, showName: true, showGoogle: true, icon: 'fa-mobile-screen-button'}
        };
        let currentRole = 'institutional';

        function openModal(role) {
            currentRole = role;
            const ui = cfg[role];
            document.getElementById('mTitle').innerText = ui.title;
            document.getElementById('mSub').innerText = ui.sub;
            document.getElementById('userRole').value = role;
            document.getElementById('idIcon').className = 'fas ' + ui.icon;
            document.getElementById('nameGroup').style.display = ui.showName ? 'block' : 'none';
            document.getElementById('passwordGroup').style.display = ui.showPass ? 'block' : 'none';
            document.getElementById('googleWrap').style.display = ui.showGoogle ? 'block' : 'none';
            document.getElementById('forgotWrap').style.display = ui.showPass ? 'block' : 'none';
            const nameInput = document.getElementById('nameInput');
            const pwdInput = document.getElementById('passwordInput');
            if (ui.showName) nameInput.setAttribute('required', 'required'); else nameInput.removeAttribute('required');
            if (ui.showPass) pwdInput.setAttribute('required', 'required'); else pwdInput.removeAttribute('required');
            document.getElementById('mainModal').classList.add('active');
        }
        function closeModal() { document.getElementById('mainModal').classList.remove('active'); }

        async function handleLogin(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('submitBtn');
            const prev = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

            const fd = new FormData(document.getElementById('authForm'));
            const endpoint = currentRole === 'public' ? '/auth/public_ai_entry.php' : '/auth/student_faculty_login.php';
            try {
                const res = await fetch(endpoint, {method: 'POST', body: fd});
                const data = await res.json();
                if (data.success) {
                    window.location.href = data.redirect || '/dashboard/dashboard.php';
                    return;
                }
                alert(data.message || 'Login failed');
            } catch (err) {
                alert('Network or server error.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = prev;
            }
        }
    </script>
</body>
</html>
