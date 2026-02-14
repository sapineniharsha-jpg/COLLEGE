<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$clientId = (string) (getenv('GOOGLE_CLIENT_ID') ?: '');
$clientSecret = (string) (getenv('GOOGLE_CLIENT_SECRET') ?: '');
$defaultRedirect = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/google_oauth.php';
$redirectUri = (string) (getenv('GOOGLE_REDIRECT_URL') ?: $defaultRedirect);

if ($clientId === '' || $clientSecret === '') {
    header('Location: /login.php?err=Google+login+not+configured');
    exit;
}

if (!isset($_GET['code'])) {
    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
}

$tokenReq = [
    'code' => (string) $_GET['code'],
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($tokenReq),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$tokenRaw = curl_exec($ch);
$tokenHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tokenErr = curl_error($ch);
curl_close($ch);

if ($tokenErr || $tokenHttp !== 200 || !$tokenRaw) {
    header('Location: /login.php?err=Google+token+exchange+failed');
    exit;
}

$tokenData = json_decode($tokenRaw, true);
$accessToken = (string) ($tokenData['access_token'] ?? '');
if ($accessToken === '') {
    header('Location: /login.php?err=Invalid+Google+token+response');
    exit;
}

$ch2 = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch2, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$profileRaw = curl_exec($ch2);
$profileHttp = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$profileErr = curl_error($ch2);
curl_close($ch2);

if ($profileErr || $profileHttp !== 200 || !$profileRaw) {
    header('Location: /login.php?err=Google+profile+fetch+failed');
    exit;
}

$profile = json_decode($profileRaw, true);
$googleSub = (string) ($profile['sub'] ?? '');
$name = (string) ($profile['name'] ?? 'Google User');
$email = (string) ($profile['email'] ?? '');
$avatar = (string) ($profile['picture'] ?? '');
$publicId = $googleSub !== '' ? ('GOOGLE_' . $googleSub) : ('GOOGLE_' . md5($email . microtime(true)));

$_SESSION['user_id'] = $publicId;
$_SESSION['ID_NO'] = $publicId;
$_SESSION['NAME'] = $name;
$_SESSION['name'] = $name;
$_SESSION['email'] = $email;
$_SESSION['avatar'] = $avatar;
$_SESSION['role'] = 'public';
$_SESSION['DEPARTMENT'] = 'Guest';
$_SESSION['dept'] = 'Guest';
$_SESSION['google_login'] = true;
$_SESSION['logged_in'] = true;

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (file_exists($dbPath)) {
    require_once $dbPath;
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        foreach (['conn', 'con', 'db', 'connection'] as $candidate) {
            if (isset($$candidate) && $$candidate instanceof mysqli) {
                $mysqli = $$candidate;
                break;
            }
        }
    }
    if (isset($mysqli) && ($mysqli instanceof mysqli) && !$mysqli->connect_error) {
        $mysqli->query("CREATE TABLE IF NOT EXISTS ai_public_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(50) UNIQUE,
            name VARCHAR(150) NOT NULL,
            mobile VARCHAR(20) NOT NULL DEFAULT '',
            email VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_access TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $up = $mysqli->prepare("INSERT INTO ai_public_users (public_id, name, email, last_access)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), last_access = NOW()");
        if ($up) {
            $up->bind_param("sss", $publicId, $name, $email);
            $up->execute();
        }
    }
}

header('Location: /velai.php');
exit;
?>
