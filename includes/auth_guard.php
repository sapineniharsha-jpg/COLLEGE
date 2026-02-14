<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login(array $allowed_roles = []): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    if (!empty($allowed_roles)) {
        $role = strtolower((string) ($_SESSION['role'] ?? 'public'));
        if (!in_array($role, $allowed_roles, true)) {
            http_response_code(403);
            echo '<h2>403 Forbidden</h2><p>You do not have permission to access this page.</p>';
            exit;
        }
    }
}
?>
