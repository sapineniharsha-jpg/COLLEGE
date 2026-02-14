<?php
// Shared lightweight security helpers for all modules.

if (!function_exists('vh_get_csrf_token')) {
    function vh_get_csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['_vh_csrf_token'])) {
            try {
                $_SESSION['_vh_csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $_SESSION['_vh_csrf_token'] = hash('sha256', uniqid((string) mt_rand(), true));
            }
        }
        return $_SESSION['_vh_csrf_token'];
    }
}

if (!function_exists('vh_read_csrf_token_from_request')) {
    function vh_read_csrf_token_from_request() {
        if (isset($_POST['_csrf'])) {
            return trim((string) $_POST['_csrf']);
        }
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return trim((string) $_SERVER['HTTP_X_CSRF_TOKEN']);
        }
        if (isset($_GET['_csrf'])) {
            return trim((string) $_GET['_csrf']);
        }
        return '';
    }
}

if (!function_exists('vh_verify_csrf_token')) {
    function vh_verify_csrf_token() {
        $sessionToken = vh_get_csrf_token();
        $requestToken = vh_read_csrf_token_from_request();
        return $requestToken !== '' && hash_equals($sessionToken, $requestToken);
    }
}

if (!function_exists('vh_require_csrf_or_exit')) {
    function vh_require_csrf_or_exit($as_json = false) {
        if (vh_verify_csrf_token()) {
            return;
        }

        if ($as_json) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        } else {
            if (!headers_sent()) {
                http_response_code(403);
            }
            echo 'Invalid CSRF token.';
        }
        exit();
    }
}

if (!function_exists('vh_e')) {
    function vh_e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
