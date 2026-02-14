<?php
// Shared SaaS PWA header shell
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('vh_e')) {
    function vh_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('vh_include_if_exists')) {
    function vh_include_if_exists(array $paths): bool
    {
        foreach ($paths as $p) {
            if (is_string($p) && $p !== '' && file_exists($p)) {
                require_once $p;
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('vh_normalize_role_local')) {
    function vh_normalize_role_local(string $role): string
    {
        $role = strtolower(trim($role));
        $map = [
            'dean_academic' => 'dean_academics',
            'a.o' => 'ao',
            'administrative officer' => 'ao',
            'administrative_officer' => 'ao',
            'counselor' => 'counsellor',
            'class advisor' => 'class_advisor',
            'classadvisor' => 'class_advisor',
            'advisor' => 'class_advisor',
        ];
        return $map[$role] ?? $role;
    }
}
if (!function_exists('vh_is_active_nav')) {
    function vh_is_active_nav(string $itemPath, string $currentUriPath, string $currentPage): bool
    {
        $itemPath = parse_url($itemPath, PHP_URL_PATH) ?: $itemPath;
        $itemBase = basename($itemPath);
        if ($itemPath === $currentUriPath) {
            return true;
        }
        if ($itemBase !== '' && $itemBase === $currentPage) {
            return true;
        }
        return false;
    }
}
if (!function_exists('vh_resolve_nav_path')) {
    function vh_resolve_nav_path(string $path): string
    {
        $parts = parse_url($path);
        $rawPath = $parts['path'] ?? $path;
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $candidates = [$rawPath];
        switch ($rawPath) {
            case '/dashboard/dashboard.php':
                $candidates = ['/dashboard/dashboard.php', '/dashboard.php'];
                break;
            case '/profile.php':
                $candidates = ['/profile.php', '/dashboard/profile.php'];
                break;
            case '/dashboard/profile.php':
                $candidates = ['/dashboard/profile.php', '/profile.php'];
                break;
            case '/attendance_selection.php':
                $candidates = ['/attendance_selection.php', '/dashboard/attendance.php'];
                break;
            case '/dashboard/attendance.php':
                $candidates = ['/dashboard/attendance.php', '/attendance_selection.php', '/attendance.php'];
                break;
            case '/mentor_hub.php':
                $candidates = ['/mentor_hub.php', '/dashboard/mentor_hub.php'];
                break;
            case '/dashboard/mentor_hub.php':
                $candidates = ['/dashboard/mentor_hub.php', '/mentor_hub.php'];
                break;
            case '/class_advisor_manager.php':
                $candidates = ['/class_advisor_manager.php', '/dashboard/class_advisor_manager.php'];
                break;
            case '/dashboard/class_advisor_manager.php':
                $candidates = ['/dashboard/class_advisor_manager.php', '/class_advisor_manager.php'];
                break;
            case '/bonafide.php':
                $candidates = ['/bonafide.php', '/bonafide/bonafide.php'];
                break;
            case '/bonafide/bonafide.php':
                $candidates = ['/bonafide/bonafide.php', '/bonafide.php'];
                break;
            case '/admin/view_circular.php':
                $candidates = ['/admin/view_circular.php', '/admin/circulars.php'];
                break;
            case '/logout.php':
                $candidates = ['/logout.php', '/dashboard/logout.php', '/bonafide/logout.php'];
                break;
            case '/dashboard/logout.php':
                $candidates = ['/dashboard/logout.php', '/logout.php', '/bonafide/logout.php'];
                break;
            case '/forgot_password.php':
                $candidates = ['/forgot_password.php', '/auth/forgot_password.php'];
                break;
            case '/auth/forgot_password.php':
                $candidates = ['/auth/forgot_password.php', '/forgot_password.php'];
                break;
            case '/velai.php':
                $candidates = ['/velai.php', '/ai/velai.php'];
                break;
            case '/ai/velai.php':
                $candidates = ['/ai/velai.php', '/velai.php'];
                break;
        }

        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot !== '') {
            foreach ($candidates as $candidate) {
                if (file_exists($docRoot . $candidate)) {
                    return $candidate . $query;
                }
            }
        }
        return $candidates[0] . $query;
    }
}

$current_uri_path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$current_page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$user_role_raw = (string) ($_SESSION['role'] ?? 'public');
$user_role = vh_normalize_role_local($user_role_raw);
$user_name = (string) ($_SESSION['NAME'] ?? $_SESSION['user_name'] ?? 'Guest');
$user_initial = strtoupper(substr(trim($user_name), 0, 1));
if ($user_initial === '') {
    $user_initial = 'G';
}

$site_name = 'VEL AI Portal';
$page_title = isset($page_title) && trim((string) $page_title) !== '' ? (string) $page_title : $site_name;
$page_description = isset($page_description) && trim((string) $page_description) !== ''
    ? (string) $page_description
    : 'Vel AI e-Governance SaaS PWA portal for academics, attendance, analytics and administration.';
$canonical_url = isset($canonical_url) && trim((string) $canonical_url) !== ''
    ? (string) $canonical_url
    : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($current_uri_path ?: '/'));

$doc_root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
vh_include_if_exists([
    $doc_root !== '' ? $doc_root . '/dashboard/role_page_map.php' : '',
    dirname(__DIR__) . '/dashboard/role_page_map.php',
    __DIR__ . '/../dashboard/role_page_map.php',
]);

$default_role_pages = [
    ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
    ['label' => 'VEL AI', 'path' => '/velai.php', 'icon' => 'fas fa-brain'],
    ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
];
$role_pages = function_exists('vh_pages_for_role') ? vh_pages_for_role($user_role) : $default_role_pages;
if (empty($role_pages)) {
    $role_pages = $default_role_pages;
}
$resolved_role_pages = [];
foreach ($role_pages as $pageItem) {
    $itemPath = (string) ($pageItem['path'] ?? '#');
    if ($itemPath !== '' && $itemPath !== '#') {
        $pageItem['path'] = vh_resolve_nav_path($itemPath);
    }
    $resolved_role_pages[] = $pageItem;
}
$role_pages = $resolved_role_pages;

$home_url = vh_resolve_nav_path('/dashboard/dashboard.php');
$profile_url = vh_resolve_nav_path('/profile.php');
$logout_url = vh_resolve_nav_path('/logout.php');
$velai_url = vh_resolve_nav_path('/velai.php');

$top_nav_limit = 7;
$top_nav_items = array_slice($role_pages, 0, $top_nav_limit);

$ga_measurement_id = (string) (getenv('GA_MEASUREMENT_ID') ?: ($_ENV['GA_MEASUREMENT_ID'] ?? ''));
$gtm_id = (string) (getenv('GOOGLE_TAG_MANAGER_ID') ?: ($_ENV['GOOGLE_TAG_MANAGER_ID'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="description" content="<?= vh_e($page_description) ?>">
    <link rel="canonical" href="<?= vh_e($canonical_url) ?>">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/img/logo.png">

    <meta property="og:title" content="<?= vh_e($page_title) ?>">
    <meta property="og:description" content="<?= vh_e($page_description) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= vh_e($canonical_url) ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">

    <title><?= vh_e($page_title) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <?php if ($gtm_id !== ''): ?>
    <script>
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?= vh_e($gtm_id) ?>');
    </script>
    <?php endif; ?>

    <?php if ($ga_measurement_id !== ''): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= vh_e($ga_measurement_id) ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '<?= vh_e($ga_measurement_id) ?>');
    </script>
    <?php endif; ?>

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "CollegeOrUniversity",
      "name": "Vel Tech High Tech Dr. Rangarajan Dr. Sakunthala Engineering College",
      "url": "https://ai.velhightech.com",
      "sameAs": [
        "https://www.facebook.com/VelTechCollege",
        "https://www.instagram.com/velhightech/",
        "https://www.youtube.com/@VELTECHAVADI"
      ],
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "#60, Avadi - Vel Tech Road, Vel Nagar",
        "addressLocality": "Chennai",
        "postalCode": "600062",
        "addressCountry": "IN"
      }
    }
    </script>

    <style>
        :root {
            --inst-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
            --inst-primary: #bc1888;
            --glass-bg: rgba(255, 255, 255, 0.92);
            --body-bg: #fafafa;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --nav-height: 66px;
            --shadow-soft: 0 6px 28px rgba(15, 23, 42, 0.05);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { width: 100%; overflow-x: hidden; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--body-bg);
            color: var(--text-dark);
            padding-top: var(--nav-height);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .app-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: var(--nav-height);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 22px;
            background: var(--glass-bg);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: var(--shadow-soft);
        }
        .app-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--inst-grad);
            opacity: 0.8;
        }

        .left-head { display: flex; align-items: center; gap: 14px; min-width: 190px; }
        .mobile-toggle {
            display: none;
            font-size: 1.25rem;
            border: none;
            background: transparent;
            color: var(--text-dark);
            cursor: pointer;
        }
        .logo-area {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 1.2rem;
            font-weight: 800;
            background: var(--inst-grad);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .desk-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow-x: auto;
            scrollbar-width: none;
            max-width: calc(100vw - 430px);
        }
        .desk-nav::-webkit-scrollbar { display: none; }
        .nav-link {
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 8px 14px;
            border-radius: 999px;
            white-space: nowrap;
            transition: .2s ease;
        }
        .nav-link i { margin-right: 6px; font-size: 0.84rem; }
        .nav-link:hover { background: #f3f4f6; }
        .nav-link.active {
            background: var(--inst-grad);
            color: #fff;
            box-shadow: 0 8px 18px rgba(188, 24, 136, 0.25);
        }

        .header-actions { display: flex; align-items: center; gap: 14px; min-width: 180px; justify-content: flex-end; }
        .ai-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--inst-grad);
            color: #fff;
            font-weight: 700;
            font-size: 0.8rem;
            box-shadow: 0 8px 18px rgba(188, 24, 136, 0.25);
        }
        .ai-pill i { font-size: 0.78rem; }
        .notif-icon {
            position: relative;
            color: #374151;
            text-decoration: none;
            font-size: 1.1rem;
        }
        .notif-dot {
            position: absolute;
            top: -2px;
            right: -3px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ef4444;
            border: 2px solid #fff;
        }
        .user-mini {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 2px solid rgba(188, 24, 136, 0.25);
            background: #fff;
            color: #374151;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .logout-mini { color: #ef4444; text-decoration: none; font-size: 1.1rem; }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.38);
            backdrop-filter: blur(2px);
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: .25s;
        }
        .sidebar-overlay.active { opacity: 1; visibility: visible; }
        .sidebar {
            position: fixed;
            top: 0;
            left: -300px;
            width: 290px;
            max-width: 85vw;
            height: 100%;
            z-index: 2001;
            background: #fff;
            border-right: 1px solid #f1f5f9;
            box-shadow: 8px 0 30px rgba(0, 0, 0, 0.1);
            transition: .35s cubic-bezier(.2, .8, .2, 1);
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
        }
        .sidebar.active { left: 0; }
        .side-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 12px;
        }
        .user-round {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 800;
            background: var(--inst-grad);
        }
        .side-links { overflow-y: auto; padding-right: 4px; }
        .side-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #334155;
            padding: 11px 12px;
            border-radius: 12px;
            font-size: 0.92rem;
            font-weight: 600;
            margin-bottom: 4px;
            transition: .2s;
        }
        .side-link i { color: var(--inst-primary); width: 18px; text-align: center; }
        .side-link:hover,
        .side-link.active { background: #fff1f6; color: #be185d; }

        .side-footer { margin-top: auto; padding-top: 14px; border-top: 1px solid #f1f5f9; }
        .logout-side {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #ef4444;
            background: #fff5f5;
            padding: 10px 12px;
            border-radius: 12px;
            font-weight: 700;
        }

        @media (max-width: 1100px) {
            .desk-nav { max-width: calc(100vw - 340px); }
        }
        @media (max-width: 992px) {
            .mobile-toggle { display: inline-flex; }
            .desk-nav, .header-actions { display: none; }
            .app-header { padding: 0 16px; }
        }
    </style>
</head>
<body>
<?php if ($gtm_id !== ''): ?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= vh_e($gtm_id) ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php endif; ?>

<header class="app-header">
    <div class="left-head">
        <button class="mobile-toggle" type="button" aria-label="Open Menu" onclick="vhToggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a class="logo-area" href="<?= vh_e($home_url) ?>">
            <i class="fas fa-shield-alt"></i> VEL AI
        </a>
    </div>

    <nav class="desk-nav" aria-label="Primary navigation">
        <?php foreach ($top_nav_items as $item):
            $path = (string) ($item['path'] ?? '#');
            $label = (string) ($item['label'] ?? 'Link');
            $icon = (string) ($item['icon'] ?? 'fas fa-circle');
            $active = vh_is_active_nav($path, $current_uri_path, $current_page) ? 'active' : '';
        ?>
            <a href="<?= vh_e($path) ?>" class="nav-link <?= $active ?>">
                <i class="<?= vh_e($icon) ?>"></i><?= vh_e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="header-actions">
        <a class="ai-pill" href="<?= vh_e($velai_url) ?>" title="Open VEL AI">
            <i class="fas fa-brain"></i> AI
        </a>
        <a class="notif-icon" href="javascript:void(0)" aria-label="Notifications">
            <i class="far fa-bell"></i><span class="notif-dot"></span>
        </a>
        <a class="user-mini" href="<?= vh_e($profile_url) ?>" title="<?= vh_e($user_name) ?>">
            <?= vh_e($user_initial) ?>
        </a>
        <a class="logout-mini" href="<?= vh_e($logout_url) ?>" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</header>

<div class="sidebar-overlay" onclick="vhToggleSidebar()"></div>
<aside class="sidebar" id="vhSidebar">
    <div class="side-profile">
        <span class="user-round"><?= vh_e($user_initial) ?></span>
        <div>
            <div style="font-weight:700; font-size:0.95rem;"><?= vh_e($user_name) ?></div>
            <div style="font-size:0.78rem; color:#64748b;"><?= vh_e(ucfirst(str_replace('_', ' ', $user_role))) ?></div>
        </div>
    </div>
    <div class="side-links">
        <?php foreach ($role_pages as $item):
            $path = (string) ($item['path'] ?? '#');
            $label = (string) ($item['label'] ?? 'Link');
            $icon = (string) ($item['icon'] ?? 'fas fa-circle');
            $active = vh_is_active_nav($path, $current_uri_path, $current_page) ? 'active' : '';
        ?>
            <a href="<?= vh_e($path) ?>" class="side-link <?= $active ?>">
                <i class="<?= vh_e($icon) ?>"></i> <?= vh_e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="side-footer">
        <a class="logout-side" href="<?= vh_e($logout_url) ?>"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</aside>

<script>
function vhToggleSidebar() {
    document.getElementById('vhSidebar').classList.toggle('active');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}
</script>
