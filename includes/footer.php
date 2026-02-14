<?php
// Shared SaaS PWA footer shell
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
        ];
        return $map[$role] ?? $role;
    }
}
if (!function_exists('vh_is_active_nav')) {
    function vh_is_active_nav(string $itemPath, string $currentUriPath, string $currentPage): bool
    {
        $itemPath = parse_url($itemPath, PHP_URL_PATH) ?: $itemPath;
        $itemBase = basename($itemPath);
        return ($itemPath === $currentUriPath) || ($itemBase !== '' && $itemBase === $currentPage);
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
            case '/attendance_selection.php':
                $candidates = ['/attendance_selection.php', '/dashboard/attendance.php'];
                break;
            case '/mentor_hub.php':
                $candidates = ['/mentor_hub.php', '/dashboard/mentor_hub.php'];
                break;
            case '/class_advisor_manager.php':
                $candidates = ['/class_advisor_manager.php', '/dashboard/class_advisor_manager.php'];
                break;
            case '/bonafide.php':
                $candidates = ['/bonafide.php', '/bonafide/bonafide.php'];
                break;
            case '/admin/view_circular.php':
                $candidates = ['/admin/view_circular.php', '/admin/circulars.php'];
                break;
            case '/logout.php':
                $candidates = ['/logout.php', '/dashboard/logout.php', '/bonafide/logout.php'];
                break;
            case '/forgot_password.php':
                $candidates = ['/forgot_password.php', '/auth/forgot_password.php'];
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
$user_role = vh_normalize_role_local((string) ($_SESSION['role'] ?? 'public'));

$doc_root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
vh_include_if_exists([
    $doc_root !== '' ? $doc_root . '/dashboard/role_page_map.php' : '',
    dirname(__DIR__) . '/dashboard/role_page_map.php',
    __DIR__ . '/../dashboard/role_page_map.php',
]);

$default_pages = [
    ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-home'],
    ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
];
$role_pages = function_exists('vh_pages_for_role') ? vh_pages_for_role($user_role) : $default_pages;
if (empty($role_pages)) {
    $role_pages = $default_pages;
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

$sitemap_pages = array_slice($role_pages, 0, 8);
$mobile_pages = array_slice($role_pages, 0, 4);
if (count($mobile_pages) < 4) {
    $extras = [
        ['label' => 'Search', 'path' => '/dashboard/search.php', 'icon' => 'fas fa-search'],
        ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
        ['label' => 'Logout', 'path' => '/logout.php', 'icon' => 'fas fa-sign-out-alt'],
    ];
    foreach ($extras as $extra) {
        $extra['path'] = vh_resolve_nav_path((string) ($extra['path'] ?? '#'));
        $exists = false;
        foreach ($mobile_pages as $item) {
            if (($item['path'] ?? '') === $extra['path']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $mobile_pages[] = $extra;
        }
        if (count($mobile_pages) >= 4) {
            break;
        }
    }
}
$mobile_pages = array_slice($mobile_pages, 0, 4);
?>
<style>
    :root {
        --insta-primary: #bc1888;
        --inst-grad: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
        --text-dark: #1e293b;
        --text-gray: #64748b;
    }

    .saas-footer {
        background: #ffffff;
        border-top: 1px solid #f1f5f9;
        padding: 56px 20px 34px;
        margin-top: auto;
        color: var(--text-gray);
        position: relative;
        z-index: 10;
    }
    .footer-content {
        max-width: 1400px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1.4fr 1fr 1fr 1fr;
        gap: 34px;
    }
    .footer-col h4 {
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-dark);
        margin: 0 0 16px;
        text-transform: uppercase;
        letter-spacing: .4px;
    }
    .footer-brand h2 {
        margin: 0 0 10px;
        background: var(--inst-grad);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        font-weight: 900;
        font-size: 1.7rem;
    }
    .footer-desc {
        font-size: .9rem;
        line-height: 1.6;
        margin: 0 0 15px;
        max-width: 300px;
    }
    .footer-links { list-style: none; margin: 0; padding: 0; }
    .footer-links li { margin-bottom: 11px; }
    .footer-links a {
        color: var(--text-gray);
        text-decoration: none;
        font-size: .92rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: .2s;
    }
    .footer-links a:hover { color: var(--insta-primary); transform: translateX(4px); }
    .contact-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 11px;
        font-size: .9rem;
    }
    .contact-item i { color: var(--insta-primary); margin-top: 4px; }
    .social-links { display: flex; gap: 12px; margin-top: 14px; }
    .social-btn {
        width: 34px; height: 34px; border-radius: 50%;
        background: #f1f5f9; color: #1f2937; text-decoration: none;
        display: inline-flex; align-items: center; justify-content: center;
        transition: .2s;
    }
    .social-btn:hover { background: var(--insta-primary); color:#fff; transform: translateY(-2px); }
    .footer-bottom {
        max-width: 1400px;
        margin: 30px auto 0;
        padding-top: 22px;
        border-top: 1px solid #f1f5f9;
        text-align: center;
        font-size: .83rem;
        font-weight: 600;
    }

    .mobile-nav {
        display: none;
        position: fixed;
        left: 0;
        bottom: 0;
        width: 100%;
        height: 68px;
        background: rgba(255, 255, 255, 0.88);
        backdrop-filter: blur(12px);
        border-top: 1px solid rgba(0, 0, 0, 0.06);
        box-shadow: 0 -6px 18px rgba(0, 0, 0, 0.06);
        z-index: 9999;
        justify-content: space-around;
        align-items: center;
        padding-bottom: env(safe-area-inset-bottom);
    }
    .mn-item {
        text-decoration: none;
        color: #94a3b8;
        display: flex;
        flex-direction: column;
        align-items: center;
        font-size: .67rem;
        font-weight: 700;
        transition: .2s;
        width: 24%;
    }
    .mn-item i { font-size: 1.25rem; margin-bottom: 4px; }
    .mn-item.active { color: var(--insta-primary); }

    @media (max-width: 992px) {
        .footer-content { grid-template-columns: 1fr 1fr; }
        .saas-footer { padding-bottom: 95px; }
        .mobile-nav { display: flex; }
    }
    @media (max-width: 640px) {
        .footer-content { grid-template-columns: 1fr; text-align: center; }
        .footer-desc { margin-left: auto; margin-right: auto; }
        .contact-item, .footer-links a { justify-content: center; }
        .social-links { justify-content: center; }
    }
</style>

<footer class="saas-footer">
    <div class="footer-content">
        <div class="footer-col">
            <div class="footer-brand">
                <h2>Vel Tech High Tech</h2>
            </div>
            <p class="footer-desc">
                Dr. Rangarajan Dr. Sakunthala Engineering College. Responsive SaaS PWA portal for digital academics and governance.
            </p>
            <div class="contact-item">
                <i class="fas fa-map-marker-alt"></i>
                <span>#60, Avadi - Vel Tech Road, Vel Nagar, Avadi, Chennai - 600062.</span>
            </div>
            <div class="social-links">
                <a href="https://www.facebook.com/VelTechCollege" class="social-btn" target="_blank" rel="noopener"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.instagram.com/velhightech/" class="social-btn" target="_blank" rel="noopener"><i class="fab fa-instagram"></i></a>
                <a href="https://www.linkedin.com" class="social-btn" target="_blank" rel="noopener"><i class="fab fa-linkedin-in"></i></a>
                <a href="https://www.youtube.com/@VELTECHAVADI" class="social-btn" target="_blank" rel="noopener"><i class="fab fa-youtube"></i></a>
            </div>
        </div>

        <div class="footer-col">
            <h4>Sitemap</h4>
            <ul class="footer-links">
                <?php foreach ($sitemap_pages as $it): ?>
                    <li>
                        <a href="<?= vh_e((string) ($it['path'] ?? '#')) ?>">
                            <i class="fas fa-chevron-right" style="font-size:.7em"></i>
                            <?= vh_e((string) ($it['label'] ?? 'Link')) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Resources</h4>
            <ul class="footer-links">
                <li><a href="/dashboard/analytics.php"><i class="fas fa-chart-pie"></i> Analytics</a></li>
                <li><a href="/dashboard/topic_coverage.php"><i class="fas fa-book-open"></i> Topic Coverage</a></li>
                <li><a href="/dashboard/seminar_hall_booking.php"><i class="fas fa-door-open"></i> Seminar Hall Booking</a></li>
                <li><a href="/bonafide.php"><i class="fas fa-file-signature"></i> Bonafide Request</a></li>
                <li><a href="/dashboard/timetable.php"><i class="fas fa-table"></i> Academic Planner</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h4>Contact</h4>
            <div class="contact-item"><i class="fas fa-phone-alt"></i><span>+91 44 2684 0181</span></div>
            <div class="contact-item"><i class="fas fa-envelope"></i><span>principal@velhightech.com</span></div>
            <div class="contact-item"><i class="fas fa-globe"></i><span>www.velhightech.com</span></div>
            <div style="margin-top:12px; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
                <iframe
                    src="https://www.google.com/maps?q=Vel%20Tech%20High%20Tech%20Dr.Rangarajan%20Dr.Sakunthala%20Engineering%20College&output=embed"
                    width="100%" height="110" style="border:0;" loading="lazy"></iframe>
            </div>
        </div>
    </div>

    <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> Vel Tech High Tech Dr. Rangarajan Dr. Sakunthala Engineering College. All Rights Reserved.</p>
    </div>
</footer>

<div class="mobile-nav">
    <?php foreach ($mobile_pages as $it):
        $path = (string) ($it['path'] ?? '#');
        $label = (string) ($it['label'] ?? 'Link');
        $icon = (string) ($it['icon'] ?? 'fas fa-circle');
        $active = vh_is_active_nav($path, $current_uri_path, $current_page) ? 'active' : '';
    ?>
        <a href="<?= vh_e($path) ?>" class="mn-item <?= $active ?>">
            <i class="<?= vh_e($icon) ?>"></i>
            <?= vh_e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php
$chat_widget_path = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/assets/ui/floating_chat_widget.php';
if ($chat_widget_path !== '' && file_exists($chat_widget_path)) {
    require_once $chat_widget_path;
}
?>

</body>
</html>
