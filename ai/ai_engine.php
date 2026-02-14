<?php
// ai/ai_engine.php
// Local-first institutional AI pipeline with admin approval queue.

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('ai_json')) {
    function ai_json(array $payload): void
    {
        echo json_encode($payload);
        exit;
    }
}
if (!function_exists('ai_response')) {
    function ai_response(string $reply, string $source, string $intent = 'general', float $confidence = 0.0): void
    {
        ai_json([
            'status' => 'success',
            'reply' => $reply,
            'source' => $source,
            'intent' => $intent,
            'confidence' => $confidence,
            'data' => ['answer' => $reply],
        ]);
    }
}
if (!function_exists('ai_error')) {
    function ai_error(string $message): void
    {
        ai_json(['status' => 'error', 'message' => $message, 'reply' => $message]);
    }
}
if (!function_exists('ai_table_exists')) {
    function ai_table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        return (bool) ($res && $res->num_rows > 0);
    }
}
if (!function_exists('ai_now')) {
    function ai_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
if (!function_exists('ai_safe_user_type')) {
    function ai_safe_user_type(string $userType): string
    {
        $userType = strtolower(trim($userType));
        if (in_array($userType, ['student', 'faculty', 'employee', 'public', 'admin', 'principal', 'hod', 'dean'], true)) {
            return $userType;
        }
        return 'public';
    }
}
if (!function_exists('ai_sector_access_values')) {
    function ai_sector_access_values(string $userType): array
    {
        $t = ai_safe_user_type($userType);
        if ($t === 'public') {
            return ['public', 'all'];
        }
        if (in_array($t, ['student'], true)) {
            return ['public', 'student', 'all'];
        }
        return ['public', 'student', 'faculty', 'all'];
    }
}
if (!function_exists('ai_preferred_sector')) {
    function ai_preferred_sector(string $userType): string
    {
        $t = ai_safe_user_type($userType);
        if ($t === 'student') {
            return 'student';
        }
        if ($t === 'public') {
            return 'public';
        }
        return 'faculty';
    }
}
if (!function_exists('ai_interaction_user_type')) {
    function ai_interaction_user_type(string $userType): string
    {
        $t = ai_safe_user_type($userType);
        if ($t === 'student') {
            return 'student';
        }
        if ($t === 'public') {
            return 'public';
        }
        return 'employee';
    }
}
if (!function_exists('ai_apply_synonyms')) {
    function ai_apply_synonyms(mysqli $db, string $text): string
    {
        if (!ai_table_exists($db, 'ai_synonyms')) {
            return $text;
        }
        $res = $db->query("SELECT keyword, mapped_keyword FROM ai_synonyms");
        if (!$res) {
            return $text;
        }
        $out = $text;
        while ($row = $res->fetch_assoc()) {
            $k = trim((string) ($row['keyword'] ?? ''));
            $m = trim((string) ($row['mapped_keyword'] ?? ''));
            if ($k === '' || $m === '') {
                continue;
            }
            $out = preg_replace('/\b' . preg_quote($k, '/') . '\b/i', $m, $out);
        }
        return $out;
    }
}
if (!function_exists('ai_get_pattern_intent')) {
    function ai_get_pattern_intent(mysqli $db, string $text): string
    {
        if (!ai_table_exists($db, 'ai_patterns')) {
            return 'general';
        }
        $res = $db->query("SELECT pattern_text, intent FROM ai_patterns");
        if (!$res) {
            return 'general';
        }
        foreach ($res as $row) {
            $pattern = trim((string) ($row['pattern_text'] ?? ''));
            $intent = trim((string) ($row['intent'] ?? 'general'));
            if ($pattern !== '' && @preg_match('/' . $pattern . '/i', $text)) {
                return $intent !== '' ? $intent : 'general';
            }
        }
        return 'general';
    }
}
if (!function_exists('ai_ensure_pending_table')) {
    function ai_ensure_pending_table(mysqli $db): void
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
if (!function_exists('ai_insert_pending_external')) {
    function ai_insert_pending_external(mysqli $db, string $question, string $answer, string $userType, string $model, float $confidence = 0.0): void
    {
        ai_ensure_pending_table($db);
        $stmt = $db->prepare("INSERT INTO ai_pending_ai_answers
            (question, proposed_answer, user_type, source_model, confidence, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        if ($stmt) {
            $stmt->bind_param("ssssd", $question, $answer, $userType, $model, $confidence);
            $stmt->execute();
        }
    }
}
if (!function_exists('ai_insert_unanswered')) {
    function ai_insert_unanswered(mysqli $db, string $question, string $userType): void
    {
        if (!ai_table_exists($db, 'ai_unanswered_questions')) {
            return;
        }
        $stmt = $db->prepare("INSERT INTO ai_unanswered_questions (question, user_type, created_at) VALUES (?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("ss", $question, $userType);
            $stmt->execute();
        }
    }
}
if (!function_exists('ai_insert_log')) {
    function ai_insert_log(mysqli $db, string $userId, string $message, string $response): void
    {
        if (!ai_table_exists($db, 'ai_logs')) {
            return;
        }
        $stmt = $db->prepare("INSERT INTO ai_logs (user_id, message, response, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("sss", $userId, $message, $response);
            $stmt->execute();
        }
    }
}
if (!function_exists('ai_insert_interaction')) {
    function ai_insert_interaction(mysqli $db, string $userId, string $userType, string $question, string $answer, string $intent, float $confidence): void
    {
        if (!ai_table_exists($db, 'ai_interactions')) {
            return;
        }
        $stmt = $db->prepare("INSERT INTO ai_interactions (user_id, user_type, user_message, ai_response, intent, confidence, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $logUserType = ai_interaction_user_type($userType);
            $stmt->bind_param("sssssd", $userId, $logUserType, $question, $answer, $intent, $confidence);
            $stmt->execute();
        }
    }
}
if (!function_exists('ai_upsert_metrics')) {
    function ai_upsert_metrics(mysqli $db, string $source, string $userType, bool $success, float $responseMs = 0.0): void
    {
        if (!ai_table_exists($db, 'ai_metrics')) {
            return;
        }
        $today = date('Y-m-d');
        $row = null;
        $sel = $db->prepare("SELECT id, total_queries, successful_responses, local_responses, ai_responses, avg_response_time, user_types
            FROM ai_metrics WHERE date = ? LIMIT 1");
        if ($sel) {
            $sel->bind_param("s", $today);
            $sel->execute();
            $res = $sel->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
            }
        }

        $userTypes = [];
        if ($row && !empty($row['user_types'])) {
            $decoded = json_decode((string) $row['user_types'], true);
            if (is_array($decoded)) {
                $userTypes = $decoded;
            }
        }
        $userTypes[$userType] = (int) ($userTypes[$userType] ?? 0) + 1;
        $userTypesJson = json_encode($userTypes);

        if (!$row) {
            $total = 1;
            $ok = $success ? 1 : 0;
            $local = in_array($source, ['db_exact', 'db_keyword', 'knowledge_base', 'structured'], true) ? 1 : 0;
            $ai = in_array($source, ['external_ai'], true) ? 1 : 0;
            $avg = $responseMs;
            $ins = $db->prepare("INSERT INTO ai_metrics
                (date, total_queries, successful_responses, local_responses, ai_responses, avg_response_time, user_types, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($ins) {
                $ins->bind_param("siiiids", $today, $total, $ok, $local, $ai, $avg, $userTypesJson);
                $ins->execute();
            }
            return;
        }

        $total = (int) $row['total_queries'] + 1;
        $ok = (int) $row['successful_responses'] + ($success ? 1 : 0);
        $local = (int) $row['local_responses'] + (in_array($source, ['db_exact', 'db_keyword', 'knowledge_base', 'structured'], true) ? 1 : 0);
        $ai = (int) $row['ai_responses'] + (in_array($source, ['external_ai'], true) ? 1 : 0);
        $prevAvg = (float) ($row['avg_response_time'] ?? 0);
        $avg = (($prevAvg * ($total - 1)) + $responseMs) / max($total, 1);

        $upd = $db->prepare("UPDATE ai_metrics
            SET total_queries = ?, successful_responses = ?, local_responses = ?, ai_responses = ?,
                avg_response_time = ?, user_types = ?
            WHERE id = ?");
        if ($upd) {
            $id = (int) $row['id'];
            $upd->bind_param("iiiidsi", $total, $ok, $local, $ai, $avg, $userTypesJson, $id);
            $upd->execute();
        }
    }
}
if (!function_exists('ai_db_exact_or_keyword')) {
    function ai_db_exact_or_keyword(mysqli $db, string $question, string $userType): ?array
    {
        if (!ai_table_exists($db, 'ai_qa_master')) {
            return null;
        }
        $sector = ai_sector_access_values($userType);
        $sectorCsv = "'" . implode("','", array_map([$db, 'real_escape_string'], $sector)) . "'";
        $preferred = ai_preferred_sector($userType);
        $orderCase = "CASE
            WHEN sector_access = '" . $db->real_escape_string($preferred) . "' THEN 0
            WHEN sector_access = 'public' THEN 1
            WHEN sector_access = 'all' THEN 2
            WHEN sector_access = 'student' THEN 3
            WHEN sector_access = 'faculty' THEN 3
            ELSE 9 END";

        $stmt = $db->prepare("SELECT answer, category FROM ai_qa_master
            WHERE question = ? AND sector_access IN ($sectorCsv)
            ORDER BY {$orderCase}, id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $question);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                return [
                    'answer' => (string) ($row['answer'] ?? ''),
                    'source' => 'db_exact',
                    'intent' => (string) ($row['category'] ?? 'general'),
                    'confidence' => 0.98,
                ];
            }
        }

        $like = '%' . $question . '%';
        $stmt2 = $db->prepare("SELECT answer, category FROM ai_qa_master
            WHERE (question LIKE ? OR keywords LIKE ? OR tags LIKE ?)
              AND sector_access IN ($sectorCsv)
            ORDER BY {$orderCase}, updated_at DESC, id DESC
            LIMIT 1");
        if ($stmt2) {
            $stmt2->bind_param("sss", $like, $like, $like);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2 && $res2->num_rows > 0) {
                $row2 = $res2->fetch_assoc();
                return [
                    'answer' => (string) ($row2['answer'] ?? ''),
                    'source' => 'db_keyword',
                    'intent' => (string) ($row2['category'] ?? 'general'),
                    'confidence' => 0.88,
                ];
            }
        }

        return null;
    }
}
if (!function_exists('ai_knowledge_base_match')) {
    function ai_knowledge_base_match(mysqli $db, string $question, string $userType): ?array
    {
        if (!ai_table_exists($db, 'ai_knowledge_base')) {
            return null;
        }
        $sector = ai_sector_access_values($userType);
        $sectorCsv = "'" . implode("','", array_map([$db, 'real_escape_string'], $sector)) . "'";
        $preferred = ai_preferred_sector($userType);
        $orderCase = "CASE
            WHEN user_type_access = '" . $db->real_escape_string($preferred) . "' THEN 0
            WHEN user_type_access = 'public' THEN 1
            WHEN user_type_access = 'all' THEN 2
            ELSE 9 END";
        $like = '%' . $question . '%';
        $stmt = $db->prepare("SELECT response_text, category FROM ai_knowledge_base
            WHERE question_pattern LIKE ? AND user_type_access IN ($sectorCsv)
            ORDER BY {$orderCase}, id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $like);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                return [
                    'answer' => (string) ($row['response_text'] ?? ''),
                    'source' => 'knowledge_base',
                    'intent' => (string) ($row['category'] ?? 'general'),
                    'confidence' => 0.84,
                ];
            }
        }
        return null;
    }
}
if (!function_exists('ai_structured_response')) {
    function ai_structured_response(mysqli $db, string $question): ?array
    {
        $q = strtolower($question);
        // Scholarships
        if (strpos($q, 'scholarship') !== false && ai_table_exists($db, 'ai_scholarships')) {
            $res = $db->query("SELECT scholarship_name, category FROM ai_scholarships ORDER BY id DESC LIMIT 12");
            if ($res && $res->num_rows > 0) {
                $lines = [];
                while ($row = $res->fetch_assoc()) {
                    $lines[] = '- ' . $row['scholarship_name'] . ' (' . $row['category'] . ')';
                }
                return [
                    'answer' => "Available scholarships:\n" . implode("\n", $lines),
                    'source' => 'structured',
                    'intent' => 'scholarships',
                    'confidence' => 0.9,
                ];
            }
        }

        // Hostel fee
        if ((strpos($q, 'hostel') !== false || strpos($q, 'mess') !== false) && ai_table_exists($db, 'ai_hostel_fees')) {
            $res = $db->query("SELECT hostel_type, fee FROM ai_hostel_fees ORDER BY id ASC");
            if ($res && $res->num_rows > 0) {
                $lines = [];
                while ($row = $res->fetch_assoc()) {
                    $fee = is_numeric($row['fee']) ? number_format((float) $row['fee']) : (string) $row['fee'];
                    $lines[] = '- ' . $row['hostel_type'] . ': Rs. ' . $fee;
                }
                return [
                    'answer' => "Hostel fee details:\n" . implode("\n", $lines),
                    'source' => 'structured',
                    'intent' => 'hostel_fee',
                    'confidence' => 0.9,
                ];
            }
        }

        // Vision mission
        if ((strpos($q, 'vision') !== false || strpos($q, 'mission') !== false) && ai_table_exists($db, 'ai_vision_mission')) {
            $stmt = $db->prepare("SELECT vision, mission FROM ai_vision_mission WHERE category = 'Institution' LIMIT 1");
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    $answer = "Institution Vision:\n" . (string) ($row['vision'] ?? '') . "\n\nInstitution Mission:\n" . (string) ($row['mission'] ?? '');
                    return [
                        'answer' => $answer,
                        'source' => 'structured',
                        'intent' => 'vision_mission',
                        'confidence' => 0.92,
                    ];
                }
            }
        }
        return null;
    }
}
if (!function_exists('ai_external_answer')) {
    function ai_external_answer(string $question, string $userType): ?array
    {
        $apiKey = (string) (getenv('AI_API_KEY') ?: '');
        $model = (string) (getenv('AI_MODEL') ?: 'meta-llama/llama-3.3-70b-instruct:free');
        if ($apiKey === '' || !function_exists('curl_init')) {
            return null;
        }

        $systemPrompt = "You are VEL AI for Vel Tech High Tech Dr.Rangarajan Dr.Sakunthala Engineering College.
Answer accurately and briefly. Do not invent confidential or unverified institutional data.
If unsure, clearly mention uncertainty.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $question . "\nUser type: " . $userType],
        ];

        $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $apiKey,
                "Content-Type: application/json",
                "HTTP-Referer: https://ai.velhightech.com",
                "X-Title: VEL AI",
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 320,
                'temperature' => 0.4,
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$raw) {
            return null;
        }
        $json = json_decode($raw, true);
        $answer = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        if ($answer === '') {
            return null;
        }
        return [
            'answer' => $answer,
            'source' => 'external_ai',
            'intent' => 'generative',
            'confidence' => 0.72,
            'model' => $model,
        ];
    }
}

$dbPath = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = dirname(__DIR__) . '/includes/db.php';
}
if (!file_exists($dbPath)) {
    ai_error('Database bootstrap not found.');
}
require_once $dbPath;

$db = null;
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} elseif (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
}
if (!$db || $db->connect_error) {
    ai_error('Database connection unavailable.');
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
$question = trim((string) ($payload['question'] ?? ''));
$userType = ai_safe_user_type((string) ($payload['user_context'] ?? ($_SESSION['role'] ?? 'public')));
$userId = (string) ($_SESSION['user_id'] ?? 'PUBLIC');

if ($question === '') {
    ai_error('Please ask a valid question.');
}

$start = microtime(true);
$qLower = strtolower($question);
$abusiveWords = ['fuck', 'shit', 'asshole', 'madarchod', 'bhenchod', 'chutiya', 'punda', 'otha', 'myre', 'sule'];
foreach ($abusiveWords as $w) {
    if (strpos($qLower, $w) !== false) {
        $_SESSION['ai_abuse_count'] = (int) ($_SESSION['ai_abuse_count'] ?? 0) + 1;
        if ($_SESSION['ai_abuse_count'] >= 3) {
            $_SESSION['ai_ban_time'] = time() + (24 * 60 * 60);
            $msg = "Policy violation limit reached. Access blocked for 24 hours.";
            ai_insert_log($db, $userId, $question, $msg);
            ai_insert_interaction($db, $userId, $userType, $question, $msg, 'safety_block', 1.0);
            ai_upsert_metrics($db, 'safety_block', $userType, false, (microtime(true) - $start) * 1000);
            ai_response($msg, 'safety_block', 'safety_block', 1.0);
        }
        $left = 3 - (int) $_SESSION['ai_abuse_count'];
        $msg = "Please use professional language. Warning " . $_SESSION['ai_abuse_count'] . "/3. Remaining warnings: {$left}.";
        ai_insert_log($db, $userId, $question, $msg);
        ai_insert_interaction($db, $userId, $userType, $question, $msg, 'safety_warning', 1.0);
        ai_upsert_metrics($db, 'safety_warning', $userType, true, (microtime(true) - $start) * 1000);
        ai_response($msg, 'safety_warning', 'safety_warning', 1.0);
    }
}
if ((int) ($_SESSION['ai_ban_time'] ?? 0) > time()) {
    $msg = "Access temporarily blocked for 24 hours due to repeated abuse.";
    ai_response($msg, 'safety_block', 'safety_block', 1.0);
}

$normalizedQuestion = ai_apply_synonyms($db, $question);
$intent = ai_get_pattern_intent($db, $normalizedQuestion);

// Greeting quick response
if (preg_match('/^(hi|hello|hey|vanakkam|good morning|good afternoon|good evening)\b/i', $normalizedQuestion)) {
    $name = (string) ($_SESSION['name'] ?? $_SESSION['NAME'] ?? 'there');
    $answer = "Hello {$name}. I am VEL AI. You can ask about academics, attendance, circulars, hostel, scholarships, admissions and institutional services.";
    ai_insert_log($db, $userId, $question, $answer);
    ai_insert_interaction($db, $userId, $userType, $question, $answer, 'greeting', 0.99);
    ai_upsert_metrics($db, 'structured', $userType, true, (microtime(true) - $start) * 1000);
    ai_response($answer, 'structured', 'greeting', 0.99);
}

// Local answer pipeline
$local = ai_structured_response($db, $normalizedQuestion);
if (!$local) {
    $local = ai_db_exact_or_keyword($db, $normalizedQuestion, $userType);
}
if (!$local) {
    $local = ai_knowledge_base_match($db, $normalizedQuestion, $userType);
}
if ($local && !empty($local['answer'])) {
    $answer = (string) $local['answer'];
    ai_insert_log($db, $userId, $question, $answer);
    ai_insert_interaction($db, $userId, $userType, $question, $answer, (string) ($local['intent'] ?? $intent), (float) ($local['confidence'] ?? 0.8));
    ai_upsert_metrics($db, (string) ($local['source'] ?? 'structured'), $userType, true, (microtime(true) - $start) * 1000);
    ai_response($answer, (string) ($local['source'] ?? 'structured'), (string) ($local['intent'] ?? $intent), (float) ($local['confidence'] ?? 0.8));
}

// External AI fallback
$external = ai_external_answer($normalizedQuestion, $userType);
if ($external && !empty($external['answer'])) {
    $answer = (string) $external['answer'];
    ai_insert_pending_external($db, $question, $answer, $userType, (string) ($external['model'] ?? ''), (float) ($external['confidence'] ?? 0.7));
    ai_insert_log($db, $userId, $question, $answer);
    ai_insert_interaction($db, $userId, $userType, $question, $answer, (string) ($external['intent'] ?? 'generative'), (float) ($external['confidence'] ?? 0.7));
    ai_upsert_metrics($db, 'external_ai', $userType, true, (microtime(true) - $start) * 1000);
    ai_response($answer, 'external_ai', (string) ($external['intent'] ?? 'generative'), (float) ($external['confidence'] ?? 0.7));
}

// Unanswered fallback + admin review queue
$fallback = "I am currently learning this topic. Your question has been saved for admin review and future answering.";
ai_insert_unanswered($db, $question, $userType);
ai_insert_log($db, $userId, $question, $fallback);
ai_insert_interaction($db, $userId, $userType, $question, $fallback, 'unanswered', 0.0);
ai_upsert_metrics($db, 'fallback', $userType, false, (microtime(true) - $start) * 1000);
ai_response($fallback, 'fallback', 'unanswered', 0.0);
?>
