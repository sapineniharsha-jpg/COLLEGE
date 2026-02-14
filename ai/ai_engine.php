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
if (!function_exists('ai_table_has_column')) {
    function ai_table_has_column(mysqli $db, string $table, string $column): bool
    {
        static $cache = [];
        $table = trim($table);
        $column = strtolower(trim($column));
        if ($table === '' || $column === '') {
            return false;
        }
        if (!isset($cache[$table])) {
            $cache[$table] = [];
            if (!ai_table_exists($db, $table)) {
                return false;
            }
            $res = $db->query("SHOW COLUMNS FROM {$table}");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cache[$table][strtolower((string) ($row['Field'] ?? ''))] = true;
                }
            }
        }
        return !empty($cache[$table][$column]);
    }
}
if (!function_exists('ai_blank')) {
    function ai_blank($value): bool
    {
        $v = trim((string) $value);
        return $v === '' || strtoupper($v) === 'NULL' || $v === '0';
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
        if (in_array($userType, ['student', 'faculty', 'employee', 'public', 'admin', 'principal', 'hod', 'dean', 'staff', 'class_advisor', 'counsellor', 'ao'], true)) {
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
if (!function_exists('ai_reply_with_audit')) {
    function ai_reply_with_audit(
        mysqli $db,
        string $userId,
        string $userType,
        string $question,
        string $answer,
        string $source,
        string $intent,
        float $confidence,
        bool $success,
        float $startTime
    ): void {
        ai_insert_log($db, $userId, $question, $answer);
        ai_insert_interaction($db, $userId, $userType, $question, $answer, $intent, $confidence);
        ai_upsert_metrics($db, $source, $userType, $success, (microtime(true) - $startTime) * 1000);
        ai_response($answer, $source, $intent, $confidence);
    }
}
if (!function_exists('ai_is_internal_restricted_intent')) {
    function ai_is_internal_restricted_intent(string $question): bool
    {
        $q = strtolower(trim($question));
        if ($q === '') {
            return false;
        }
        $patterns = [
            '/\bbonafide\b/i',
            '/\bcircular(s)?\b/i',
            '/\bday\s*order\b/i',
            '/\btime\s*table\b/i',
            '/\btimetable\b/i',
            '/\battendance\b/i',
            '/\bmark(s)?\b/i',
            '/\bcgpa\b/i',
            '/\bprofile\b/i',
            '/\bmy\s+class(es)?\b/i',
            '/\btoday\s+class(es)?\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('ai_is_bonafide_intent')) {
    function ai_is_bonafide_intent(string $question): bool
    {
        return (bool) preg_match('/\bbonafide\b|\bcertificate\b.*\b(fee|general|internship|project)\b|\bapply\b.*\bcertificate\b/i', $question);
    }
}
if (!function_exists('ai_session_student_tokens')) {
    function ai_session_student_tokens(): array
    {
        $tokens = [];
        foreach (['student_id', 'ID_NO', 'id_no', 'user_id', 'register_no', 'register_number', 'RegisterNo'] as $key) {
            $val = trim((string) ($_SESSION[$key] ?? ''));
            if ($val !== '' && !in_array($val, $tokens, true)) {
                $tokens[] = $val;
            }
        }
        return $tokens;
    }
}
if (!function_exists('ai_fetch_student_record_by_token')) {
    function ai_fetch_student_record_by_token(mysqli $db, string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        if (ai_table_exists($db, 'students_batch_25_26')) {
            $sql = "SELECT * FROM students_batch_25_26 WHERE id_no = ? LIMIT 1";
            $types = "s";
            $params = [$token];
            if (ai_table_has_column($db, 'students_batch_25_26', 'register_no')) {
                $sql = "SELECT * FROM students_batch_25_26 WHERE id_no = ? OR register_no = ? LIMIT 1";
                $types = "ss";
                $params = [$token, $token];
            }
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    return [
                        'source' => 'fresher',
                        'table_name' => 'students_batch_25_26',
                        'id_col' => 'id_no',
                        'student_id' => (string) ($row['id_no'] ?? ''),
                        'register_no' => (string) ($row['register_no'] ?? ''),
                        'student_name' => (string) ($row['student_name'] ?? ''),
                        'department' => (string) ($row['department'] ?? ''),
                        'batch' => (string) ($row['batch'] ?? ''),
                        'gender' => (string) ($row['gender'] ?? ''),
                        'father_name' => (string) ($row['parent_name'] ?? ''),
                        'mother_name' => (string) ($row['mother_name'] ?? ''),
                        'degree_type' => (string) ($row['degree_type'] ?? 'UG'),
                        'section' => (string) ($row['section'] ?? ''),
                        'community' => (string) ($row['community'] ?? ''),
                        'raw' => $row,
                    ];
                }
            }
        }

        if (ai_table_exists($db, 'students_login_master')) {
            $sql = "SELECT * FROM students_login_master WHERE IDNo = ? LIMIT 1";
            $types = "s";
            $params = [$token];
            if (ai_table_has_column($db, 'students_login_master', 'RegisterNo')) {
                $sql = "SELECT * FROM students_login_master WHERE IDNo = ? OR RegisterNo = ? LIMIT 1";
                $types = "ss";
                $params = [$token, $token];
            }
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $row = $res->fetch_assoc();
                    return [
                        'source' => 'senior',
                        'table_name' => 'students_login_master',
                        'id_col' => 'IDNo',
                        'student_id' => (string) ($row['IDNo'] ?? ''),
                        'register_no' => (string) ($row['RegisterNo'] ?? ''),
                        'student_name' => (string) ($row['Name'] ?? ''),
                        'department' => (string) ($row['Dept'] ?? ''),
                        'batch' => (string) ($row['Batch'] ?? ''),
                        'gender' => (string) ($row['Gender'] ?? ''),
                        'father_name' => (string) ($row['fathername'] ?? ''),
                        'mother_name' => (string) ($row['mothername'] ?? ''),
                        'degree_type' => (string) ($row['DegreeType'] ?? 'UG'),
                        'section' => (string) ($row['Section'] ?? ''),
                        'community' => (string) ($row['community'] ?? ''),
                        'raw' => $row,
                    ];
                }
            }
        }

        return null;
    }
}
if (!function_exists('ai_fetch_current_student_record')) {
    function ai_fetch_current_student_record(mysqli $db): ?array
    {
        $tokens = ai_session_student_tokens();
        foreach ($tokens as $token) {
            $student = ai_fetch_student_record_by_token($db, $token);
            if ($student) {
                return $student;
            }
        }
        return null;
    }
}
if (!function_exists('ai_bonafide_required_profile_map')) {
    function ai_bonafide_required_profile_map(): array
    {
        return [
            'student_name' => ['label' => 'Student Name', 'fresher' => 'student_name', 'senior' => 'Name'],
            'register_no' => ['label' => 'Register Number', 'fresher' => 'register_no', 'senior' => 'RegisterNo'],
            'department' => ['label' => 'Department', 'fresher' => 'department', 'senior' => 'Dept'],
            'batch' => ['label' => 'Batch', 'fresher' => 'batch', 'senior' => 'Batch'],
            'gender' => ['label' => 'Gender (Male/Female)', 'fresher' => 'gender', 'senior' => 'Gender'],
            'father_name' => ['label' => "Father Name", 'fresher' => 'parent_name', 'senior' => 'fathername'],
            'mother_name' => ['label' => "Mother Name", 'fresher' => 'mother_name', 'senior' => 'mothername'],
            'degree_type' => ['label' => 'Degree Type (UG/PG)', 'fresher' => 'degree_type', 'senior' => 'DegreeType'],
            'section' => ['label' => 'Section', 'fresher' => 'section', 'senior' => 'Section'],
            'community' => ['label' => 'Community', 'fresher' => 'community', 'senior' => 'community'],
        ];
    }
}
if (!function_exists('ai_bonafide_profile_missing_keys')) {
    function ai_bonafide_profile_missing_keys(array $student): array
    {
        $missing = [];
        $source = (string) ($student['source'] ?? 'senior');
        $raw = (array) ($student['raw'] ?? []);
        foreach (ai_bonafide_required_profile_map() as $key => $meta) {
            $col = (string) ($meta[$source === 'fresher' ? 'fresher' : 'senior'] ?? '');
            if ($col !== '' && !array_key_exists($col, $raw)) {
                continue;
            }
            if (ai_blank($student[$key] ?? '')) {
                $missing[] = $key;
            }
        }
        return $missing;
    }
}
if (!function_exists('ai_bonafide_label')) {
    function ai_bonafide_label(string $fieldKey): string
    {
        $map = ai_bonafide_required_profile_map();
        return (string) ($map[$fieldKey]['label'] ?? $fieldKey);
    }
}
if (!function_exists('ai_bonafide_normalize_gender')) {
    function ai_bonafide_normalize_gender(string $value): ?string
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['m', 'male', 'boy'], true)) {
            return 'Male';
        }
        if (in_array($v, ['f', 'female', 'girl'], true)) {
            return 'Female';
        }
        return null;
    }
}
if (!function_exists('ai_bonafide_update_profile_field')) {
    function ai_bonafide_update_profile_field(mysqli $db, array $student, string $fieldKey, string $value): bool
    {
        $map = ai_bonafide_required_profile_map();
        if (!isset($map[$fieldKey])) {
            return false;
        }
        $source = (string) ($student['source'] ?? 'senior');
        $tableName = (string) ($student['table_name'] ?? '');
        $idCol = (string) ($student['id_col'] ?? '');
        $studentId = (string) ($student['student_id'] ?? '');
        if ($tableName === '' || $idCol === '' || $studentId === '') {
            return false;
        }

        $column = (string) ($map[$fieldKey][$source === 'fresher' ? 'fresher' : 'senior'] ?? '');
        if ($column === '' || !ai_table_has_column($db, $tableName, $column)) {
            return false;
        }

        $clean = trim($value);
        if ($fieldKey === 'gender') {
            $normalized = ai_bonafide_normalize_gender($clean);
            if ($normalized === null) {
                return false;
            }
            $clean = $normalized;
        }
        if ($fieldKey === 'section') {
            $clean = strtoupper($clean);
        }
        if ($clean === '') {
            return false;
        }

        $stmt = $db->prepare("UPDATE {$tableName} SET {$column} = ? WHERE {$idCol} = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ss", $clean, $studentId);
        return (bool) $stmt->execute();
    }
}
if (!function_exists('ai_bonafide_parse_cert_type')) {
    function ai_bonafide_parse_cert_type(string $text): ?string
    {
        $q = strtolower(trim($text));
        if ($q === '') {
            return null;
        }
        if (strpos($q, 'general') !== false) {
            return 'General';
        }
        if (strpos($q, 'fee structure') !== false || preg_match('/\bstructure\b/', $q)) {
            return 'Fee Structure';
        }
        if (strpos($q, 'fee paid') !== false || preg_match('/\bpaid\b/', $q)) {
            return 'Fee Paid';
        }
        if (strpos($q, 'intern') !== false) {
            return 'Internship';
        }
        if (strpos($q, 'project') !== false) {
            return 'Project';
        }
        return null;
    }
}
if (!function_exists('ai_bonafide_current_academic_year')) {
    function ai_bonafide_current_academic_year(): string
    {
        $year = (int) date('Y');
        $month = (int) date('n');
        return ($month >= 6) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
    }
}
if (!function_exists('ai_bonafide_calculate_year_pursuing')) {
    function ai_bonafide_calculate_year_pursuing(string $batch): string
    {
        $start = (int) (explode('-', $batch)[0] ?? date('Y'));
        $year = (int) date('Y');
        $month = (int) date('n');
        $diff = $year - $start + ($month >= 6 ? 1 : 0);
        if ($diff <= 1) {
            return 'I';
        }
        if ($diff === 2) {
            return 'II';
        }
        if ($diff === 3) {
            return 'III';
        }
        if ($diff === 4) {
            return 'IV';
        }
        return 'Completed';
    }
}
if (!function_exists('ai_bonafide_fetch_class_advisor_id')) {
    function ai_bonafide_fetch_class_advisor_id(mysqli $db, string $studentId): string
    {
        if ($studentId === '' || !ai_table_exists($db, 'mentor_mentee')) {
            return '';
        }
        $stmt = $db->prepare("SELECT Employee_ID_No, ClassAdvisorID FROM mentor_mentee WHERE Student_ID_No = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param("s", $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            return trim((string) ($row['Employee_ID_No'] ?: ($row['ClassAdvisorID'] ?? '')));
        }
        return '';
    }
}
if (!function_exists('ai_bonafide_fetch_fee_details')) {
    function ai_bonafide_fetch_fee_details(mysqli $db, string $studentId): ?array
    {
        if ($studentId === '' || !ai_table_exists($db, 'bonafide_fee_details')) {
            return null;
        }
        $stmt = $db->prepare("SELECT * FROM bonafide_fee_details WHERE id_no = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("s", $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
        return null;
    }
}
if (!function_exists('ai_bonafide_fetch_transport')) {
    function ai_bonafide_fetch_transport(mysqli $db, string $studentId): ?array
    {
        if ($studentId === '' || !ai_table_exists($db, 'transport_allocation')) {
            return null;
        }
        $stmt = $db->prepare("SELECT * FROM transport_allocation WHERE id_no = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("s", $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
        return null;
    }
}
if (!function_exists('ai_bonafide_fetch_hostel')) {
    function ai_bonafide_fetch_hostel(mysqli $db, string $studentId): ?array
    {
        if ($studentId === '') {
            return null;
        }
        $hasGirls = ai_table_exists($db, 'hostel_girls_padmavathy');
        $hasBoys = ai_table_exists($db, 'hostel_boys_titans');
        if (!$hasGirls && !$hasBoys) {
            return null;
        }

        if ($hasGirls && $hasBoys) {
            $stmt = $db->prepare(
                "SELECT 'Padmavathy Girls Hostel' AS hostel_name, room_no FROM hostel_girls_padmavathy WHERE id_no = ?
                 UNION ALL
                 SELECT 'Titans Boys Hostel' AS hostel_name, room_no FROM hostel_boys_titans WHERE id_no = ?"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("ss", $studentId, $studentId);
        } elseif ($hasGirls) {
            $stmt = $db->prepare("SELECT 'Padmavathy Girls Hostel' AS hostel_name, room_no FROM hostel_girls_padmavathy WHERE id_no = ?");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("s", $studentId);
        } else {
            $stmt = $db->prepare("SELECT 'Titans Boys Hostel' AS hostel_name, room_no FROM hostel_boys_titans WHERE id_no = ?");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("s", $studentId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
        return null;
    }
}
if (!function_exists('ai_bonafide_generate_request_number')) {
    function ai_bonafide_generate_request_number(int $requestId): string
    {
        return 'REQ' . str_pad((string) $requestId, 6, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('ai_bonafide_create_request')) {
    function ai_bonafide_create_request(mysqli $db, array $student, string $certType, string $purpose): array
    {
        if (!ai_table_exists($db, 'bonafide_requests')) {
            return ['ok' => false, 'message' => 'Bonafide module is unavailable right now.'];
        }
        $studentId = trim((string) ($student['student_id'] ?? ''));
        if ($studentId === '') {
            return ['ok' => false, 'message' => 'Student profile is not available for request creation.'];
        }
        $advisorId = ai_bonafide_fetch_class_advisor_id($db, $studentId);
        if ($advisorId === '') {
            return ['ok' => false, 'message' => 'Class advisor not mapped. Please contact department and then try again in Bonafide module.'];
        }

        $gender = trim((string) ($student['gender'] ?? 'Male'));
        $studentPrefix = (stripos($gender, 'f') === 0) ? 'Ms.' : 'Mr.';
        $fatherName = trim((string) ($student['father_name'] ?? ''));
        $motherName = trim((string) ($student['mother_name'] ?? ''));
        $parentName = $fatherName !== '' ? $fatherName : $motherName;
        $parentPrefix = $fatherName !== '' ? 'Mr.' : 'Mrs.';
        if ($parentName === '') {
            $parentName = 'Parent/Guardian';
            $parentPrefix = 'Mr./Mrs.';
        }

        $transport = ai_bonafide_fetch_transport($db, $studentId);
        $hostel = ai_bonafide_fetch_hostel($db, $studentId);
        $facilityOption = 'None';
        $busZone = 'None';
        $busType = 'None';
        if ($hostel) {
            $facilityOption = 'Hostel';
        } elseif ($transport) {
            $facilityOption = 'Transport';
            $route = strtoupper(trim((string) ($transport['route_no'] ?? 'None')));
            if ($route === '' || $route === 'NONE') {
                $route = 'None';
            }
            $busZone = (strpos($route, 'AC') !== false) ? 'AC' : $route;
            $busType = ($busZone === 'AC') ? 'AC' : (($busZone === 'None') ? 'None' : 'Regular');
        }

        $columns = [];
        $values = [];
        $types = '';
        $addCol = function (string $col, $val) use (&$columns, &$values, &$types, $db): void {
            if (ai_table_has_column($db, 'bonafide_requests', $col)) {
                $columns[] = $col;
                $values[] = (string) $val;
                $types .= 's';
            }
        };

        $addCol('student_id', $studentId);
        $addCol('student_name', (string) ($student['student_name'] ?? ''));
        $addCol('register_number', (string) ($student['register_no'] ?? ''));
        $addCol('department', (string) ($student['department'] ?? ''));
        $addCol('batch', (string) ($student['batch'] ?? ''));
        $addCol('student_prefix', $studentPrefix);
        $addCol('parent_prefix', $parentPrefix);
        $addCol('father_name', $parentName);
        $addCol('gender', $gender);
        $addCol('year_pursuing', ai_bonafide_calculate_year_pursuing((string) ($student['batch'] ?? '')));
        $addCol('program', (string) ($student['department'] ?? ''));
        $addCol('degreetype', (string) ($student['degree_type'] ?? 'UG'));
        $addCol('duration_course', '4 Years');
        $addCol('content_academic_year', ai_bonafide_current_academic_year());
        $addCol('academic_year', '');
        $addCol('purpose', $purpose);
        $addCol('bonafide_type', $certType);
        $addCol('fee_structure', in_array($certType, ['Fee Structure', 'Fee Paid'], true) ? 'Yes' : 'No');
        $addCol('note_details', 'No');
        $addCol('facility_option', $facilityOption);
        $addCol('bus_zone', $busZone);
        $addCol('bus_type', $busType);
        $addCol('status', 'Pending');
        $addCol('advisor_status', 'pending');
        $addCol('hod_status', 'pending');
        $addCol('dean_status', 'pending');
        $addCol('admin_status', 'pending');
        $addCol('request_date', date('Y-m-d H:i:s'));
        $addCol('advisor_id', $advisorId);

        if (empty($columns)) {
            return ['ok' => false, 'message' => 'Unable to map bonafide request columns.'];
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO bonafide_requests (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Failed to prepare bonafide request statement.'];
        }
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            return ['ok' => false, 'message' => 'Bonafide request creation failed.'];
        }

        $requestId = (int) $stmt->insert_id;
        $requestNumber = ai_bonafide_generate_request_number($requestId);
        if (ai_table_has_column($db, 'bonafide_requests', 'request_number')) {
            $upd = $db->prepare("UPDATE bonafide_requests SET request_number = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param("si", $requestNumber, $requestId);
                $upd->execute();
            }
        }

        return ['ok' => true, 'request_id' => $requestId, 'request_number' => $requestNumber];
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

// Continue active bonafide conversational workflow first.
$flow = $_SESSION['ai_bonafide_flow'] ?? null;
if (is_array($flow) && (($flow['type'] ?? '') === 'bonafide')) {
    if (preg_match('/^(cancel|stop|reset|exit)$/i', trim($normalizedQuestion))) {
        unset($_SESSION['ai_bonafide_flow']);
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            'Bonafide workflow cancelled. You can start again anytime by asking "apply bonafide".',
            'workflow',
            'bonafide_cancel',
            1.0,
            true,
            $start
        );
    }

    if ($userType !== 'student') {
        unset($_SESSION['ai_bonafide_flow']);
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            'Bonafide apply workflow is available only for student login.',
            'workflow',
            'bonafide_access',
            1.0,
            true,
            $start
        );
    }

    $studentFlow = ai_fetch_current_student_record($db);
    if (!$studentFlow) {
        unset($_SESSION['ai_bonafide_flow']);
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            'Unable to load your student profile. Please open Bonafide page once and try again.',
            'workflow',
            'bonafide_profile_missing',
            1.0,
            false,
            $start
        );
    }

    $step = (string) ($flow['step'] ?? '');
    if ($step === 'await_profile_field') {
        $missing = $flow['missing'] ?? [];
        $index = (int) ($flow['index'] ?? 0);
        if (!isset($missing[$index])) {
            $missing = ai_bonafide_profile_missing_keys($studentFlow);
            $index = 0;
            $_SESSION['ai_bonafide_flow']['missing'] = $missing;
            $_SESSION['ai_bonafide_flow']['index'] = 0;
        }
        $fieldKey = $missing[$index] ?? '';
        if ($fieldKey === '') {
            $_SESSION['ai_bonafide_flow']['step'] = 'await_cert_type';
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Profile is complete now. Please choose certificate type: General, Fee Structure, Fee Paid, Internship, or Project.",
                'workflow',
                'bonafide_cert_type',
                1.0,
                true,
                $start
            );
        }

        $inputValue = trim($question);
        if (preg_match('/^(hi|hello|hey)$/i', $inputValue)) {
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Please provide " . ai_bonafide_label($fieldKey) . ". You can type 'cancel' to stop this workflow.",
                'workflow',
                'bonafide_profile_prompt',
                1.0,
                true,
                $start
            );
        }

        if ($fieldKey === 'gender' && ai_bonafide_normalize_gender($inputValue) === null) {
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Please enter Gender as Male or Female.",
                'workflow',
                'bonafide_profile_validation',
                1.0,
                true,
                $start
            );
        }

        $updated = ai_bonafide_update_profile_field($db, $studentFlow, $fieldKey, $inputValue);
        if (!$updated) {
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "I could not save " . ai_bonafide_label($fieldKey) . ". Please enter it again.",
                'workflow',
                'bonafide_profile_update_failed',
                1.0,
                false,
                $start
            );
        }

        $studentFlow = ai_fetch_current_student_record($db);
        if (!$studentFlow) {
            unset($_SESSION['ai_bonafide_flow']);
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Profile updated, but I could not re-load your profile. Please restart bonafide apply.",
                'workflow',
                'bonafide_profile_reload_failed',
                1.0,
                false,
                $start
            );
        }
        $remaining = ai_bonafide_profile_missing_keys($studentFlow ?: []);
        if (!empty($remaining)) {
            $_SESSION['ai_bonafide_flow']['missing'] = $remaining;
            $_SESSION['ai_bonafide_flow']['index'] = 0;
            $nextField = $remaining[0];
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Saved. Next, please provide " . ai_bonafide_label($nextField) . ". (" . count($remaining) . " field(s) pending)",
                'workflow',
                'bonafide_profile_prompt',
                1.0,
                true,
                $start
            );
        }

        $_SESSION['ai_bonafide_flow']['step'] = 'await_cert_type';
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            "Profile is complete now. Please choose certificate type: General, Fee Structure, Fee Paid, Internship, or Project.",
            'workflow',
            'bonafide_cert_type',
            1.0,
            true,
            $start
        );
    }

    if ($step === 'await_cert_type') {
        $certType = ai_bonafide_parse_cert_type($question);
        if ($certType === null) {
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Please choose one certificate type: General, Fee Structure, Fee Paid, Internship, or Project.",
                'workflow',
                'bonafide_cert_type',
                1.0,
                true,
                $start
            );
        }

        $fee = ai_bonafide_fetch_fee_details($db, (string) ($studentFlow['student_id'] ?? ''));
        $scholarship = trim((string) ($fee['scholarship_type'] ?? ''));
        if (in_array($certType, ['Fee Structure', 'Fee Paid'], true) && in_array($scholarship, ['7.5', '7.5%'], true)) {
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "For 7.5 scholarship profile, Fee Structure and Fee Paid certificates are restricted. Please choose General, Internship, or Project.",
                'workflow',
                'bonafide_cert_restricted',
                1.0,
                true,
                $start
            );
        }

        $_SESSION['ai_bonafide_flow']['cert_type'] = $certType;
        $_SESSION['ai_bonafide_flow']['step'] = 'await_purpose';
        $feeLine = '';
        if ($certType === 'Fee Structure' && $fee) {
            $feeLine = " Current fee snapshot - Tuition: Rs. " . number_format((float) ($fee['tuition_fees'] ?? 0), 2)
                . ", Other: Rs. " . number_format((float) ($fee['other_fees'] ?? 0), 2) . ".";
        }
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            "Selected certificate type: {$certType}." . $feeLine . " Please enter purpose for this bonafide request.",
            'workflow',
            'bonafide_purpose',
            1.0,
            true,
            $start
        );
    }

    if ($step === 'await_purpose') {
        $purpose = trim($question);
        if (strlen($purpose) < 3) {
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                "Please provide a clear purpose (minimum 3 characters).",
                'workflow',
                'bonafide_purpose_validation',
                1.0,
                true,
                $start
            );
        }

        $certType = (string) ($flow['cert_type'] ?? 'General');
        $result = ai_bonafide_create_request($db, $studentFlow, $certType, $purpose);
        if (!empty($result['ok'])) {
            unset($_SESSION['ai_bonafide_flow']);
            $requestNo = (string) ($result['request_number'] ?? '');
            $answer = "Bonafide request submitted successfully.";
            if ($requestNo !== '') {
                $answer .= " Request ID: {$requestNo}.";
            }
            $answer .= " You can track it in /bonafide.php.";
            ai_reply_with_audit(
                $db,
                $userId,
                $userType,
                $question,
                $answer,
                'workflow',
                'bonafide_submitted',
                1.0,
                true,
                $start
            );
        }

        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            (string) ($result['message'] ?? 'Unable to submit bonafide request now.'),
            'workflow',
            'bonafide_submit_failed',
            1.0,
            false,
            $start
        );
    }
}

// Start new bonafide apply workflow.
if (ai_is_bonafide_intent($normalizedQuestion)) {
    if ($userType === 'public') {
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            "Bonafide apply is an internal student service. Please login as student to continue.",
            'policy',
            'access_policy',
            1.0,
            true,
            $start
        );
    }
    if ($userType !== 'student') {
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            "Bonafide apply workflow is available for student login. Staff/Admin can use /bonafide.php.",
            'policy',
            'access_policy',
            1.0,
            true,
            $start
        );
    }

    $student = ai_fetch_current_student_record($db);
    if (!$student) {
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            "I could not load your student profile. Please login again and try bonafide request.",
            'workflow',
            'bonafide_profile_missing',
            1.0,
            false,
            $start
        );
    }

    $missing = ai_bonafide_profile_missing_keys($student);
    if (!empty($missing)) {
        $_SESSION['ai_bonafide_flow'] = [
            'type' => 'bonafide',
            'step' => 'await_profile_field',
            'missing' => $missing,
            'index' => 0,
            'cert_type' => '',
        ];
        $firstField = $missing[0];
        ai_reply_with_audit(
            $db,
            $userId,
            $userType,
            $question,
            "Your profile is pending for bonafide apply. Please provide " . ai_bonafide_label($firstField) . ". (" . count($missing) . " field(s) pending)",
            'workflow',
            'bonafide_profile_prompt',
            1.0,
            true,
            $start
        );
    }

    $_SESSION['ai_bonafide_flow'] = [
        'type' => 'bonafide',
        'step' => 'await_cert_type',
        'missing' => [],
        'index' => 0,
        'cert_type' => '',
    ];
    ai_reply_with_audit(
        $db,
        $userId,
        $userType,
        $question,
        "Bonafide application started. Please choose certificate type: General, Fee Structure, Fee Paid, Internship, or Project.",
        'workflow',
        'bonafide_cert_type',
        1.0,
        true,
        $start
    );
}

// Public must not see internal operational details.
if ($userType === 'public' && ai_is_internal_restricted_intent($normalizedQuestion)) {
    ai_reply_with_audit(
        $db,
        $userId,
        $userType,
        $question,
        "This information is available only for authenticated students/faculty. Please login to continue.",
        'policy',
        'access_policy',
        1.0,
        true,
        $start
    );
}

// Greeting quick response
if (preg_match('/^(hi|hello|hey|vanakkam|good morning|good afternoon|good evening)\b/i', $normalizedQuestion)) {
    $name = (string) ($_SESSION['name'] ?? $_SESSION['NAME'] ?? 'there');
    $answer = "Hello {$name}. I am VEL AI. You can ask about academics, attendance, circulars, hostel, scholarships, admissions and institutional services.";
    ai_reply_with_audit($db, $userId, $userType, $question, $answer, 'structured', 'greeting', 0.99, true, $start);
}

// Local answer pipeline
$local = null;
if ($userType === 'public') {
    // Public users get only curated QA by sector_access policy.
    $local = ai_db_exact_or_keyword($db, $normalizedQuestion, $userType);
} else {
    $local = ai_structured_response($db, $normalizedQuestion);
    if (!$local) {
        $local = ai_db_exact_or_keyword($db, $normalizedQuestion, $userType);
    }
    if (!$local) {
        $local = ai_knowledge_base_match($db, $normalizedQuestion, $userType);
    }
}
if ($local && !empty($local['answer'])) {
    $answer = (string) $local['answer'];
    ai_reply_with_audit(
        $db,
        $userId,
        $userType,
        $question,
        $answer,
        (string) ($local['source'] ?? 'structured'),
        (string) ($local['intent'] ?? $intent),
        (float) ($local['confidence'] ?? 0.8),
        true,
        $start
    );
}

// Public users are restricted to curated internal data only (no external AI fallback).
if ($userType === 'public') {
    $publicFallback = "I can provide only public generic information here. Please ask admission, courses, fee, campus facilities, or login for internal services.";
    ai_insert_unanswered($db, $question, $userType);
    ai_reply_with_audit($db, $userId, $userType, $question, $publicFallback, 'public_policy', 'public_generic_only', 1.0, true, $start);
}

// External AI fallback for authenticated internal users.
$external = ai_external_answer($normalizedQuestion, $userType);
if ($external && !empty($external['answer'])) {
    $answer = (string) $external['answer'];
    ai_insert_pending_external($db, $question, $answer, $userType, (string) ($external['model'] ?? ''), (float) ($external['confidence'] ?? 0.7));
    ai_reply_with_audit(
        $db,
        $userId,
        $userType,
        $question,
        $answer,
        'external_ai',
        (string) ($external['intent'] ?? 'generative'),
        (float) ($external['confidence'] ?? 0.7),
        true,
        $start
    );
}

// Unanswered fallback + admin review queue
$fallback = "I am currently learning this topic. Your question has been saved for admin review and future answering.";
ai_insert_unanswered($db, $question, $userType);
ai_insert_log($db, $userId, $question, $fallback);
ai_insert_interaction($db, $userId, $userType, $question, $fallback, 'unanswered', 0.0);
ai_upsert_metrics($db, 'fallback', $userType, false, (microtime(true) - $start) * 1000);
ai_response($fallback, 'fallback', 'unanswered', 0.0);
?>
