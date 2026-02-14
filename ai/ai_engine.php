<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('respond')) {
    function respond(string $answer, string $source = 'local')
    {
        echo json_encode([
            'status' => 'success',
            'reply' => $answer,
            'source' => $source,
            'data' => ['answer' => $answer],
        ]);
        exit;
    }
}
if (!function_exists('respond_error')) {
    function respond_error(string $msg)
    {
        echo json_encode(['status' => 'error', 'reply' => $msg, 'message' => $msg]);
        exit;
    }
}

$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
$question = trim((string) ($input['question'] ?? ''));
$userContext = strtolower(trim((string) ($input['user_context'] ?? ($_SESSION['role'] ?? 'public'))));

if ($question === '') {
    respond_error('Please ask a valid question.');
}

$abusive = ['fuck', 'shit', 'bitch', 'asshole', 'madarchod', 'chutiya', 'punda', 'otha', 'myre', 'sule', 'harami'];
$qLower = strtolower($question);
foreach ($abusive as $bad) {
    if (strpos($qLower, $bad) !== false) {
        respond('Please use respectful language. I am here to help with institutional queries.', 'safety');
    }
}

// Greeting lane
if (preg_match('/^(hi|hello|hey|vanakkam|good morning|good afternoon|good evening)\b/i', $question)) {
    $name = (string) ($_SESSION['name'] ?? $_SESSION['NAME'] ?? 'there');
    $msg = "Hello {$name}! I am VEL AI for Vel Tech High Tech. Ask me about admissions, academics, facilities, circulars, attendance or campus services.";
    respond($msg, 'greeting');
}

// Local rule-based answers
$rules = [
    'admission' => "Admissions are open for UG/PG programs. You can apply online through the official website or visit campus admission office for counseling support.",
    'hostel' => "Hostel facilities are available with separate accommodation blocks and essential amenities. For exact fee and room availability, please contact hostel office.",
    'placement' => "Placement support includes training, aptitude preparation, and company drives. You can ask your department placement coordinator for latest company schedules.",
    'transport' => "Transport services operate with multiple routes. Share your bus route number if you need specific route information.",
    'fee' => "Fee details vary by quota/program. Please refer to admissions office or official fee circular for current academic year.",
    'syllabus' => "Syllabus is program and semester specific. Tell me your department and semester to help with syllabus-related guidance.",
    'timetable' => "Timetable is published in the dashboard module. Faculty can update and students can view published schedules.",
    'attendance' => "Attendance is hour-based and tracked through the attendance module. Faculty posts attendance and students can view status.",
    'circular' => "Circulars are managed through Circular Dashboard. You can view latest published circulars from the circular module.",
    'bonafide' => "Bonafide requests are available through the Bonafide module with role-based approval workflow.",
    'counsel' => "Counseling records are maintained in the counseling dashboard and restricted to authorized roles.",
];

foreach ($rules as $k => $ans) {
    if (strpos($qLower, $k) !== false) {
        respond($ans, 'rule_engine');
    }
}

// Optional DB-assisted lookup for faculty/HOD queries
$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
$mysqli = null;
if (file_exists($dbPath)) {
    require_once $dbPath;
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
        $mysqli = $GLOBALS['mysqli'];
    } elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $mysqli = $GLOBALS['conn'];
    }
}

if ($mysqli instanceof mysqli && !$mysqli->connect_error) {
    if (preg_match('/\b(hod|dean|principal|chairman|director)\b/i', $question, $m)) {
        $title = '%' . $m[1] . '%';
        $stmt = $mysqli->prepare("SELECT NAME, DESIGNATION, DEPARTMENT, college_email FROM employee_details1 WHERE DESIGNATION LIKE ? LIMIT 3");
        if ($stmt) {
            $stmt->bind_param("s", $title);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $out = [];
                while ($row = $res->fetch_assoc()) {
                    $line = $row['NAME'] . ' - ' . $row['DESIGNATION'];
                    if (!empty($row['DEPARTMENT'])) {
                        $line .= ' (' . $row['DEPARTMENT'] . ')';
                    }
                    if ($userContext !== 'public' && !empty($row['college_email'])) {
                        $line .= ' | ' . $row['college_email'];
                    }
                    $out[] = $line;
                }
                respond("Relevant contacts:\n- " . implode("\n- ", $out), 'db_contacts');
            }
        }
    }
}

// OpenRouter optional fallback
$apiKey = (string) (getenv('AI_API_KEY') ?: '');
$model = (string) (getenv('AI_MODEL') ?: 'meta-llama/llama-3.3-70b-instruct:free');
if ($apiKey !== '' && function_exists('curl_init')) {
    $messages = [
        ['role' => 'system', 'content' => "You are VEL AI for Vel Tech High Tech Engineering College. Keep answers concise, reliable, and institutional."],
        ['role' => 'user', 'content' => $question],
    ];
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://ai.velhightech.com',
            'X-Title: VEL AI',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 280,
            'temperature' => 0.4,
        ]),
    ]);
    $rawResp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && $rawResp) {
        $j = json_decode($rawResp, true);
        $ans = trim((string) ($j['choices'][0]['message']['content'] ?? ''));
        if ($ans !== '') {
            respond($ans, 'openrouter');
        }
    }
}

respond("I am still learning this topic. Please contact the respective office for exact details, or ask me a more specific question.", 'fallback');
?>
