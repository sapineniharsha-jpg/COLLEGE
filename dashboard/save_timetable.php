<?php
// save_timetable.php - Fully Enhanced & 500 Error Fixed
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$include_paths = [__DIR__ . '/..', dirname(__DIR__)];
if (!function_exists('find_include_path')) {
    function find_include_path(array $paths, $relative)
    {
        foreach ($paths as $base) {
            $full = rtrim($base, '/') . '/' . ltrim($relative, '/');
            if (file_exists($full)) {
                return $full;
            }
        }
        return null;
    }
}

$db_path = find_include_path($include_paths, 'includes/db.php');
if (!$db_path) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Missing include: db.php']);
    exit();
}
require_once $db_path;

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    foreach (['conn', 'con', 'db', 'connection'] as $candidate) {
        if (isset($$candidate) && $$candidate instanceof mysqli) {
            $mysqli = $$candidate;
            break;
        }
    }
}

$security_path = __DIR__ . '/../platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json');

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
        throw new Exception('Database connection failure.');
    }

    if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
        throw new Exception('Session Expired.');
    }

    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(true);
    }

    if (!isset($_POST['json_data'])) {
        throw new Exception("No data received (json_data is missing).");
    }

    $data = json_decode((string) $_POST['json_data'], true);
    if (!is_array($data)) {
        throw new Exception("Invalid JSON data format.");
    }

    $passed_edit_id = (isset($_POST['edit_id']) && $_POST['edit_id'] !== 'new' && $_POST['edit_id'] !== 'null')
        ? (int) $_POST['edit_id']
        : 0;

    $batch   = strtoupper(trim((string) ($data['batch'] ?? '')));
    $sem     = (int) ($data['sem'] ?? 0);
    $program = strtoupper(trim((string) ($data['program'] ?? '')));
    $dept    = strtoupper(trim((string) ($data['department'] ?? '')));
    $intake  = (int) ($data['intake'] ?? 0);
    $status  = isset($data['status']) ? strtoupper(trim((string) $data['status'])) : 'PUBLISHED';

    $ca_name = isset($data['class_advisor']) ? strtoupper(trim((string) $data['class_advisor'])) : '';
    $ca_id   = isset($data['class_advisor_id']) ? strtoupper(trim((string) $data['class_advisor_id'])) : '';

    $user_role = strtoupper(trim((string) ($_SESSION['role'] ?? 'USER')));
    $session_dept = strtoupper(trim((string) ($_SESSION['DEPARTMENT'] ?? '')));

    if ($user_role !== 'ADMIN' && $user_role !== 'HOD') {
        throw new Exception("Unauthorized Access.");
    }

    if ($user_role === 'HOD') {
        if ($session_dept !== 'S&H' && $session_dept !== 'SCIENCE AND HUMANITIES') {
            if ($dept !== $session_dept) {
                throw new Exception("Security Violation: You cannot save data for " . $dept);
            }
        }
    }

    if ($batch === '' || $program === '' || $dept === '' || $sem <= 0) {
        throw new Exception('Invalid timetable configuration.');
    }

    if (!in_array($status, ['DRAFT', 'PUBLISHED'], true)) {
        throw new Exception("Invalid status value.");
    }

    if (!isset($data['sections']) || !is_array($data['sections'])) {
        throw new Exception('Sections data missing.');
    }

    if (!isset($data['subjects_meta']) || !is_array($data['subjects_meta'])) {
        $data['subjects_meta'] = [];
    }

    $mysqli->begin_transaction();

    $stmt_del_course = $mysqli->prepare("DELETE FROM timetable_course_details WHERE batch=? AND dept=?");
    $stmt_del_course->bind_param("ss", $batch, $dept);
    $stmt_del_course->execute();
    $stmt_del_course->close();

    if (!empty($data['subjects_meta'])) {
        $stmt_course = $mysqli->prepare("
            INSERT INTO timetable_course_details
            (batch, dept, sub_code, sub_name, l_credit, t_credit, p_credit, c_credit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($data['subjects_meta'] as $code => $meta) {
            $s_code = strtoupper((string) $code);
            $s_name = strtoupper(trim((string) ($meta['name'] ?? '')));
            $l_cr   = (int) ($meta['L'] ?? 0);
            $t_cr   = (int) ($meta['T'] ?? 0);
            $p_cr   = (int) ($meta['P'] ?? 0);
            $c_cr   = (int) ($meta['credits'] ?? 0);

            if ($s_code === '') {
                continue;
            }
            $stmt_course->bind_param("ssssiiii", $batch, $dept, $s_code, $s_name, $l_cr, $t_cr, $p_cr, $c_cr);
            $stmt_course->execute();
        }
        $stmt_course->close();
    }

    $unique_code_out = "";

    foreach ($data['sections'] as $secData) {
        if (!is_array($secData)) {
            continue;
        }
        $secName = strtoupper(trim((string) ($secData['sec_name'] ?? '')));
        if ($secName === '') {
            continue;
        }

        $unique_code = "{$dept}/{$batch}/S{$sem}/{$secName}";
        $unique_code_out = $unique_code;

        $master_id = 0;
        $is_update = false;

        if ($passed_edit_id > 0) {
            $sec_chk_stmt = $mysqli->prepare("SELECT department FROM timetable_master WHERE id = ? LIMIT 1");
            $sec_chk_stmt->bind_param("i", $passed_edit_id);
            $sec_chk_stmt->execute();
            $sec_chk_res = $sec_chk_stmt->get_result();
            if ($sec_chk_res && $sec_row = $sec_chk_res->fetch_assoc()) {
                $existing_dept = strtoupper(trim((string) ($sec_row['department'] ?? '')));
                if ($user_role === 'HOD' && $session_dept !== 'S&H' && $session_dept !== 'SCIENCE AND HUMANITIES' && $existing_dept !== $session_dept) {
                    throw new Exception("Security Violation: You cannot edit this ID.");
                }
            } else {
                throw new Exception("Invalid edit ID.");
            }
            $master_id = $passed_edit_id;
            $is_update = true;
        } else {
            $stmt_chk = $mysqli->prepare("SELECT id FROM timetable_master WHERE unique_code=? LIMIT 1");
            $stmt_chk->bind_param("s", $unique_code);
            $stmt_chk->execute();
            $res = $stmt_chk->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $master_id = (int) $row['id'];
                $is_update = true;
            }
            $stmt_chk->close();
        }

        if ($is_update) {
            $stmt_upd = $mysqli->prepare("
                UPDATE timetable_master
                SET program=?, batch=?, semester=?, section=?, intake=?, class_advisor=?, class_advisor_id=?, status=?, unique_code=?, updated_at=NOW()
                WHERE id=?
            ");
            $stmt_upd->bind_param("ssisissssi", $program, $batch, $sem, $secName, $intake, $ca_name, $ca_id, $status, $unique_code, $master_id);
            $stmt_upd->execute();
            $stmt_upd->close();

            $del_old_details = $mysqli->prepare("DELETE FROM timetable_details WHERE master_id = ?");
            $del_old_details->bind_param("i", $master_id);
            $del_old_details->execute();
            $del_old_details->close();
        } else {
            $stmt_ins = $mysqli->prepare("
                INSERT INTO timetable_master
                (unique_code, batch, semester, program, department, section, intake,
                 class_advisor, class_advisor_id, status, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt_ins->bind_param("ssisssisss", $unique_code, $batch, $sem, $program, $dept, $secName, $intake, $ca_name, $ca_id, $status);
            $stmt_ins->execute();
            $master_id = (int) $stmt_ins->insert_id;
            $stmt_ins->close();
        }

        $stmt_clash = $mysqli->prepare("
            SELECT tm.department, tm.section
            FROM timetable_details td
            JOIN timetable_master tm ON tm.id = td.master_id
            WHERE td.day=? AND td.hour=? AND td.faculty_id=?
              AND tm.batch=? AND tm.semester=?
              AND tm.unique_code <> ?
              AND tm.status='PUBLISHED'
            LIMIT 1
        ");

        $stmt_det = $mysqli->prepare("
            INSERT INTO timetable_details
            (master_id, day, hour, subject_code, subject_name,
             faculty_id, faculty_name, type,
             l, t, p, c, class_advisor, group_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $grid = isset($secData['grid']) && is_array($secData['grid']) ? $secData['grid'] : [];
        foreach ($grid as $day => $hours) {
            foreach ((array) $hours as $hour => $cell) {
                if (!is_array($cell) || empty($cell['sub'])) {
                    continue;
                }

                $day_int = (int) $day;
                $hour_int = (int) $hour;

                $subCode = strtoupper((string) $cell['sub']);
                $facId   = strtoupper((string) ($cell['fac_id'] ?? ''));
                $facName = strtoupper((string) ($cell['fac_name'] ?? ''));
                $type    = strtoupper((string) ($cell['type'] ?? ''));
                $group   = isset($cell['group']) ? strtoupper((string) $cell['group']) : 'ENTIRE';

                $meta = $data['subjects_meta'][$subCode] ?? [];
                $subName = strtoupper((string) ($meta['name'] ?? ($cell['sub_name'] ?? '')));

                $L = (int) ($meta['L'] ?? 0);
                $T = (int) ($meta['T'] ?? 0);
                $P = (int) ($meta['P'] ?? 0);
                $C = (int) ($meta['credits'] ?? 0);

                if ($status === 'PUBLISHED' && $facId !== 'NA' && strpos($facId, 'MANUAL_') !== 0) {
                    $stmt_clash->bind_param("iissis", $day_int, $hour_int, $facId, $batch, $sem, $unique_code);
                    $stmt_clash->execute();
                    $stmt_clash->store_result();

                    if ($stmt_clash->num_rows > 0) {
                        $stmt_clash->bind_result($cDept, $cSec);
                        $stmt_clash->fetch();
                        throw new Exception("CLASH: Faculty {$facName} ({$facId}) already assigned in {$cDept} - Sec {$cSec} on Day {$day_int} Hour {$hour_int}");
                    }
                }

                $stmt_det->bind_param("iiisssssiiiiss", $master_id, $day_int, $hour_int, $subCode, $subName, $facId, $facName, $type, $L, $T, $P, $C, $ca_name, $group);
                $stmt_det->execute();
            }
        }

        $stmt_det->close();
        $stmt_clash->close();

        $del_matrix_stmt = $mysqli->prepare("DELETE FROM timetable_matrix WHERE unique_code = ?");
        $del_matrix_stmt->bind_param("s", $unique_code);
        $del_matrix_stmt->execute();
        $del_matrix_stmt->close();

        $stmt_mx = $mysqli->prepare("
            INSERT INTO timetable_matrix
            (unique_code, day_order, hour_slot, subject_code, subject_name, faculty_id, faculty_name, type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($grid as $day => $hours) {
            foreach ((array) $hours as $hour => $cell) {
                if (!is_array($cell) || empty($cell['sub'])) {
                    continue;
                }

                $day_int = (int) $day;
                $hour_int = (int) $hour;
                $sCode = strtoupper((string) $cell['sub']);
                $sName = strtoupper((string) ($cell['sub_name'] ?? ''));
                $fId   = strtoupper((string) ($cell['fac_id'] ?? ''));
                $fName = strtoupper((string) ($cell['fac_name'] ?? ''));
                $typ   = strtoupper((string) ($cell['type'] ?? ''));

                $stmt_mx->bind_param("siisssss", $unique_code, $day_int, $hour_int, $sCode, $sName, $fId, $fName, $typ);
                $stmt_mx->execute();
            }
        }
        $stmt_mx->close();
    }

    $mysqli->commit();

    echo json_encode([
        'status' => 'success',
        'message' => "Timetable saved successfully as {$status}",
        'unique_code' => $unique_code_out,
    ]);
} catch (Throwable $e) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        try {
            $mysqli->rollback();
        } catch (Throwable $rollbackError) {
            // Ignore rollback errors and return original error.
        }
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
?>
