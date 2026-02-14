<?php
// seminar_hall_booking.php
// Faculty booking + AO approval workflow for seminar hall.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

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
    echo 'Missing include: db.php';
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
if (!isset($mysqli) || !($mysqli instanceof mysqli) || $mysqli->connect_error) {
    http_response_code(500);
    echo 'Database connection not initialized.';
    exit();
}

$security_path = __DIR__ . '/../platform_security.php';
if (file_exists($security_path)) {
    require_once $security_path;
}
if (!function_exists('vh_e')) {
    function vh_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('vh_bind_params')) {
    function vh_bind_params($stmt, $types, array &$values)
    {
        $refs = [];
        $refs[] = &$types;
        foreach ($values as $k => $v) {
            $refs[] = &$values[$k];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
if (!function_exists('table_exists')) {
    function table_exists(mysqli $mysqli, string $table): bool
    {
        $safe = $mysqli->real_escape_string($table);
        $res = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('table_has_column')) {
    function table_has_column(mysqli $mysqli, string $table, string $column): bool
    {
        $safeT = $mysqli->real_escape_string($table);
        $safeC = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM `{$safeT}` LIKE '{$safeC}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('safe_date')) {
    function safe_date($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
if (!function_exists('hour_slot_map')) {
    function hour_slot_map(): array
    {
        return [
            1 => '08:45 - 09:35',
            2 => '09:35 - 10:25',
            3 => '10:45 - 11:35',
            4 => '11:35 - 12:25',
            5 => '01:25 - 02:15',
            6 => '02:15 - 03:05',
            7 => '03:05 - 03:55',
            8 => '03:55 - 04:45',
        ];
    }
}
if (!function_exists('default_inventory_items')) {
    function default_inventory_items(): array
    {
        return [
            ['item_name' => 'System with Keyboard and Mouse', 'quantity' => 1, 'is_available' => 1],
            ['item_name' => 'Collar Mike', 'quantity' => 2, 'is_available' => 1],
            ['item_name' => 'White Board', 'quantity' => 1, 'is_available' => 1],
            ['item_name' => 'Duster', 'quantity' => 2, 'is_available' => 1],
        ];
    }
}
if (!function_exists('role_is_ao')) {
    function role_is_ao(string $role): bool
    {
        $role = strtolower(trim($role));
        return in_array($role, ['ao', 'a.o', 'administrative_officer', 'administrative officer'], true);
    }
}
if (!function_exists('query_conflicts')) {
    function query_conflicts(mysqli $mysqli, string $hallName, string $bookingDate, int $startHour, int $endHour, int $excludeId = 0): array
    {
        if (!table_exists($mysqli, 'seminar_hall_bookings')) {
            return [];
        }
        $sql = "SELECT id, hall_name, booking_date, start_hour, end_hour, subject_name, booked_by_name, booked_by_id, status
                FROM seminar_hall_bookings
                WHERE booking_date = ? AND hall_name = ?
                  AND status IN ('PENDING', 'APPROVED')
                  AND NOT (end_hour < ? OR start_hour > ?)";
        $types = "ssii";
        $vals = [$bookingDate, $hallName, $startHour, $endHour];
        if ($excludeId > 0) {
            $sql .= " AND id <> ?";
            $types .= "i";
            $vals[] = $excludeId;
        }
        $sql .= " ORDER BY start_hour ASC";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return [];
        }
        vh_bind_params($stmt, $types, $vals);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($res && $r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        return $rows;
    }
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['ID_NO'])) {
    header('Location: /login.php');
    exit();
}

$user_id = trim((string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? ''));
$user_name = trim((string) ($_SESSION['NAME'] ?? $_SESSION['user_name'] ?? 'Faculty'));
$user_role = strtolower(trim((string) ($_SESSION['role'] ?? 'public')));
$user_dept = trim((string) ($_SESSION['DEPARTMENT'] ?? ''));

$allowed_roles = ['faculty', 'hod', 'dean', 'dean_academics', 'dean_academic', 'principal', 'admin', 'ao', 'a.o', 'administrative_officer', 'administrative officer'];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(404);
    echo '404 Not Found';
    exit();
}

$is_ao = role_is_ao($user_role);
$can_approve = ($is_ao || in_array($user_role, ['admin', 'principal'], true));
$can_book = in_array($user_role, ['faculty', 'hod', 'dean', 'dean_academics', 'dean_academic', 'principal', 'admin'], true);

$bookings_ready = table_exists($mysqli, 'seminar_hall_bookings');
$inventory_ready = table_exists($mysqli, 'seminar_hall_inventory');
$csrf_token = function_exists('vh_get_csrf_token') ? vh_get_csrf_token() : '';
$hours = hour_slot_map();

// -------------------------
// AJAX API
// -------------------------
if (isset($_POST['action'])) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    if (function_exists('vh_require_csrf_or_exit')) {
        vh_require_csrf_or_exit(true);
    }

    try {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'get_inventory') {
            $items = [];
            if ($inventory_ready) {
                $res = $mysqli->query("SELECT id, item_name, quantity, is_available, notes FROM seminar_hall_inventory ORDER BY item_name ASC");
                while ($res && $row = $res->fetch_assoc()) {
                    $items[] = $row;
                }
            }
            if (empty($items)) {
                $items = default_inventory_items();
            }
            echo json_encode(['status' => 'success', 'data' => $items]);
            exit;
        }

        if (!$bookings_ready) {
            throw new Exception('seminar_hall_bookings table not found. Run fix_seminar_hall_db.php first.');
        }

        if ($action === 'check_availability') {
            $hallName = trim((string) ($_POST['hall_name'] ?? ''));
            $bookingDate = safe_date($_POST['booking_date'] ?? '');
            $startHour = (int) ($_POST['start_hour'] ?? 0);
            $endHour = (int) ($_POST['end_hour'] ?? 0);
            if ($hallName === '' || $bookingDate === '' || $startHour < 1 || $endHour > 8 || $startHour > $endHour) {
                throw new Exception('Invalid slot details.');
            }
            $conflicts = query_conflicts($mysqli, $hallName, $bookingDate, $startHour, $endHour);
            echo json_encode([
                'status' => 'success',
                'available' => count($conflicts) === 0,
                'data' => $conflicts,
            ]);
            exit;
        }

        if ($action === 'create_booking') {
            if (!$can_book) {
                throw new Exception('You are not allowed to book seminar hall.');
            }

            $hallName = trim((string) ($_POST['hall_name'] ?? ''));
            $bookingDate = safe_date($_POST['booking_date'] ?? '');
            $startHour = (int) ($_POST['start_hour'] ?? 0);
            $endHour = (int) ($_POST['end_hour'] ?? 0);
            $subjectName = trim((string) ($_POST['subject_name'] ?? ''));
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $eventTitle = trim((string) ($_POST['event_title'] ?? ''));
            $purpose = trim((string) ($_POST['purpose'] ?? ''));

            $items = $_POST['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $items = array_values(array_unique(array_filter(array_map(static function ($i) {
                return trim((string) $i);
            }, $items))));
            $requestedItems = implode(', ', $items);

            if ($hallName === '' || $bookingDate === '' || $startHour < 1 || $endHour > 8 || $startHour > $endHour || $subjectName === '' || $purpose === '') {
                throw new Exception('Please fill all mandatory fields.');
            }

            $conflicts = query_conflicts($mysqli, $hallName, $bookingDate, $startHour, $endHour);
            if (!empty($conflicts)) {
                throw new Exception('Selected slot is already booked/pending approval. Check availability list.');
            }

            $status = 'PENDING';
            $stmt = $mysqli->prepare("INSERT INTO seminar_hall_bookings
                (hall_name, booking_date, start_hour, end_hour, subject_name, class_name, event_title, purpose, requested_items, booked_by_id, booked_by_name, booked_by_role, department, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception($mysqli->error);
            }
            $stmt->bind_param(
                "ssiissssssssss",
                $hallName,
                $bookingDate,
                $startHour,
                $endHour,
                $subjectName,
                $className,
                $eventTitle,
                $purpose,
                $requestedItems,
                $user_id,
                $user_name,
                $user_role,
                $user_dept,
                $status
            );
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            echo json_encode(['status' => 'success', 'message' => 'Booking submitted to AO for approval.']);
            exit;
        }

        if ($action === 'list_bookings') {
            $fromDate = safe_date($_POST['from_date'] ?? '');
            $toDate = safe_date($_POST['to_date'] ?? '');
            $hall = trim((string) ($_POST['hall_name'] ?? ''));
            $status = strtoupper(trim((string) ($_POST['status'] ?? '')));
            $mineOnly = (int) ($_POST['mine_only'] ?? 0) === 1;

            $sql = "SELECT id, hall_name, booking_date, start_hour, end_hour, subject_name, class_name, event_title, purpose, requested_items,
                           booked_by_id, booked_by_name, booked_by_role, department, status, ao_remarks, approved_by, approved_at,
                           key_collected, key_collected_at, created_at
                    FROM seminar_hall_bookings WHERE 1=1";
            $types = '';
            $vals = [];

            if ($fromDate !== '') {
                $sql .= " AND booking_date >= ?";
                $types .= 's';
                $vals[] = $fromDate;
            }
            if ($toDate !== '') {
                $sql .= " AND booking_date <= ?";
                $types .= 's';
                $vals[] = $toDate;
            }
            if ($hall !== '') {
                $sql .= " AND hall_name = ?";
                $types .= 's';
                $vals[] = $hall;
            }
            if (in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
                $sql .= " AND status = ?";
                $types .= 's';
                $vals[] = $status;
            }

            if (!$can_approve || $mineOnly) {
                $sql .= " AND booked_by_id = ?";
                $types .= 's';
                $vals[] = $user_id;
            }

            $sql .= " ORDER BY booking_date DESC, start_hour DESC, id DESC LIMIT 250";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception($mysqli->error);
            }
            if ($types !== '') {
                vh_bind_params($stmt, $types, $vals);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($res && $row = $res->fetch_assoc()) {
                $rows[] = $row;
            }

            echo json_encode(['status' => 'success', 'data' => $rows]);
            exit;
        }

        if ($action === 'update_status') {
            if (!$can_approve) {
                throw new Exception('Only AO/Admin/Principal can approve or reject.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            $newStatus = strtoupper(trim((string) ($_POST['new_status'] ?? '')));
            $remarks = trim((string) ($_POST['remarks'] ?? ''));
            if ($id <= 0 || !in_array($newStatus, ['APPROVED', 'REJECTED'], true)) {
                throw new Exception('Invalid request.');
            }

            $stmt = $mysqli->prepare("SELECT id, hall_name, booking_date, start_hour, end_hour, status FROM seminar_hall_bookings WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $bk = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
            if (!$bk) {
                throw new Exception('Booking not found.');
            }

            if ($newStatus === 'APPROVED') {
                $conflicts = query_conflicts(
                    $mysqli,
                    (string) $bk['hall_name'],
                    (string) $bk['booking_date'],
                    (int) $bk['start_hour'],
                    (int) $bk['end_hour'],
                    $id
                );
                $approvedConflict = array_filter($conflicts, static function ($r) {
                    return strtoupper((string) ($r['status'] ?? '')) === 'APPROVED';
                });
                if (!empty($approvedConflict)) {
                    throw new Exception('This slot was already approved for another booking. Cannot approve this one.');
                }
            }

            $approver = trim((string) ($_SESSION['ID_NO'] ?? $_SESSION['user_id'] ?? 'AO'));
            $upd = $mysqli->prepare("UPDATE seminar_hall_bookings
                                     SET status = ?, ao_remarks = ?, approved_by = ?, approved_at = NOW()
                                     WHERE id = ?");
            $upd->bind_param("sssi", $newStatus, $remarks, $approver, $id);
            if (!$upd->execute()) {
                throw new Exception($upd->error);
            }
            echo json_encode(['status' => 'success', 'message' => "Booking {$newStatus} successfully."]);
            exit;
        }

        if ($action === 'mark_key_collected') {
            if (!$can_approve) {
                throw new Exception('Only AO/Admin/Principal can mark key collection.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Invalid booking id.');
            }
            $upd = $mysqli->prepare("UPDATE seminar_hall_bookings
                                     SET key_collected = 1, key_collected_at = NOW()
                                     WHERE id = ? AND status = 'APPROVED'");
            $upd->bind_param("i", $id);
            if (!$upd->execute()) {
                throw new Exception($upd->error);
            }
            echo json_encode(['status' => 'success', 'message' => 'Key collection marked.']);
            exit;
        }

        throw new Exception('Invalid action.');
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$header_path = find_include_path($include_paths, 'includes/header.php');
if ($header_path) {
    include $header_path;
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
    :root { --inst-grad: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); }
    body { background:#f8fafc; font-family:'Plus Jakarta Sans', sans-serif; }
    .wrap { max-width: 1380px; margin: 22px auto; padding: 0 14px 40px; }
    .hero, .cardx {
        background:#fff; border:1px solid #eef2f7; border-radius:20px; padding:18px; margin-bottom:14px;
        box-shadow:0 8px 22px rgba(15,23,42,.05);
    }
    .title { margin:0; font-size:1.6rem; font-weight:800; background:var(--inst-grad); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
    .sub { color:#64748b; margin-top:6px; }
    .lbl { display:block; margin-bottom:6px; font-size:.73rem; color:#64748b; text-transform:uppercase; font-weight:700; letter-spacing:.4px; }
    .btn-inst { background:var(--inst-grad); color:#fff; border:none; border-radius:12px; font-weight:700; box-shadow:0 10px 22px rgba(188,24,136,.2); }
    .btn-inst:hover { color:#fff; transform:translateY(-1px); }
    .badgex { font-size:.74rem; font-weight:700; border-radius:999px; padding:5px 10px; display:inline-block; }
    .b-pending { background:#fef3c7; color:#92400e; }
    .b-approved { background:#dcfce7; color:#166534; }
    .b-rejected { background:#fee2e2; color:#991b1b; }
    .b-cancelled { background:#e5e7eb; color:#374151; }
    .slot-box { border:1px dashed #d1d5db; border-radius:12px; padding:10px; background:#fafafa; min-height:62px; }
    .mini { font-size:.84rem; color:#6b7280; }
    .tbl td, .tbl th { font-size:.86rem; vertical-align:middle; }
</style>

<div class="wrap">
    <div class="hero">
        <h1 class="title">Seminar Hall Booking & Allotment</h1>
        <div class="sub">Faculty can book by timetable-style hours. AO/Admin/Principal can approve and mark key collection.</div>
    </div>

    <?php if (!$bookings_ready): ?>
        <div class="alert alert-warning" style="border-radius:12px;">
            Booking tables are missing. Please run <code>fix_seminar_hall_db.php</code> first.
            <?php if ($can_approve): ?>
                <a href="fix_seminar_hall_db.php" class="btn btn-sm btn-dark ms-2">Run DB Fix</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <?php if ($can_book): ?>
        <div class="col-lg-5">
            <div class="cardx">
                <h5 class="fw-bold mb-3">New Booking Request</h5>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="lbl">Hall Name</label>
                        <select id="hallName" class="form-select">
                            <option value="Main Seminar Hall">Main Seminar Hall</option>
                            <option value="Seminar Hall 1">Seminar Hall 1</option>
                            <option value="Seminar Hall 2">Seminar Hall 2</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="lbl">Booking Date</label>
                        <input type="date" id="bookDate" class="form-control" value="<?= vh_e(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="lbl">Start Hour</label>
                        <select id="startHour" class="form-select">
                            <?php foreach ($hours as $h => $t): ?><option value="<?= (int) $h ?>">H<?= (int) $h ?> (<?= vh_e($t) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="lbl">End Hour</label>
                        <select id="endHour" class="form-select">
                            <?php foreach ($hours as $h => $t): ?><option value="<?= (int) $h ?>">H<?= (int) $h ?> (<?= vh_e($t) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="lbl">Subject Name</label>
                        <input type="text" id="subjectName" class="form-control" placeholder="Subject / Session title">
                    </div>
                    <div class="col-12">
                        <label class="lbl">Class / Audience</label>
                        <input type="text" id="className" class="form-control" placeholder="e.g. III CSE A">
                    </div>
                    <div class="col-12">
                        <label class="lbl">Event Title (Optional)</label>
                        <input type="text" id="eventTitle" class="form-control" placeholder="Seminar / Workshop topic">
                    </div>
                    <div class="col-12">
                        <label class="lbl">Purpose</label>
                        <textarea id="purpose" class="form-control" rows="3" placeholder="Purpose of booking"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="lbl">Required Items in Hall</label>
                        <div id="inventoryBox" class="slot-box mini">Loading items...</div>
                    </div>
                    <div class="col-12">
                        <label class="lbl">Availability Check</label>
                        <div id="availabilityBox" class="slot-box mini">Choose date/hour to check live hall status.</div>
                    </div>
                    <div class="col-12 d-grid mt-2">
                        <button class="btn btn-inst" id="btnBook">Submit Booking to AO</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?= $can_book ? 'col-lg-7' : 'col-12' ?>">
            <div class="cardx">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="lbl">From Date</label>
                        <input type="date" id="fromDate" class="form-control" value="<?= vh_e(date('Y-m-01')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="lbl">To Date</label>
                        <input type="date" id="toDate" class="form-control" value="<?= vh_e(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="lbl">Hall</label>
                        <select id="fltHall" class="form-select">
                            <option value="">All</option>
                            <option value="Main Seminar Hall">Main Seminar Hall</option>
                            <option value="Seminar Hall 1">Seminar Hall 1</option>
                            <option value="Seminar Hall 2">Seminar Hall 2</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="lbl">Status</label>
                        <select id="fltStatus" class="form-select">
                            <option value="">All</option>
                            <option value="PENDING">Pending</option>
                            <option value="APPROVED">Approved</option>
                            <option value="REJECTED">Rejected</option>
                            <option value="CANCELLED">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-inst w-100" id="btnLoad">Load</button>
                    </div>
                    <?php if ($can_approve): ?>
                    <div class="col-md-3">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="mineOnly">
                            <label class="form-check-label mini" for="mineOnly">Show only my requests</label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cardx">
                <h5 class="fw-bold mb-3">Booking Register</h5>
                <div class="table-responsive">
                    <table class="table table-hover tbl">
                        <thead><tr>
                            <th>Date</th><th>Hall</th><th>Time</th><th>Subject</th><th>Faculty</th><th>Status</th><th>Items</th><th>Action</th>
                        </tr></thead>
                        <tbody id="bookingsBody"><tr><td colspan="8" class="text-center text-muted">No data loaded.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const API = <?= json_encode(basename(__FILE__), JSON_UNESCAPED_SLASHES) ?>;
const CSRF_TOKEN = <?= json_encode($csrf_token, JSON_UNESCAPED_SLASHES) ?>;
const HOURS = <?= json_encode($hours, JSON_UNESCAPED_SLASHES) ?>;
const CAN_APPROVE = <?= $can_approve ? 'true' : 'false' ?>;
const CAN_BOOK = <?= $can_book ? 'true' : 'false' ?>;
const BOOKING_READY = <?= $bookings_ready ? 'true' : 'false' ?>;

function withCsrf(data){ data._csrf = CSRF_TOKEN; return data; }
function esc(v){ return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function toast(msg){ alert(msg); }

function statusBadge(status){
    status = String(status || '').toUpperCase();
    if (status === 'APPROVED') return '<span class="badgex b-approved">APPROVED</span>';
    if (status === 'REJECTED') return '<span class="badgex b-rejected">REJECTED</span>';
    if (status === 'CANCELLED') return '<span class="badgex b-cancelled">CANCELLED</span>';
    return '<span class="badgex b-pending">PENDING</span>';
}

function loadInventory(){
    $.post(API, withCsrf({action:'get_inventory'}), function(res){
        if(!res || res.status !== 'success'){
            $('#inventoryBox').html('Unable to load items.');
            return;
        }
        const list = Array.isArray(res.data) ? res.data : [];
        if(!list.length){ $('#inventoryBox').html('No inventory configured.'); return; }
        let html = '<div class="row g-1">';
        list.forEach((i, idx) => {
            const id = `itm_${idx}`;
            const label = `${esc(i.item_name || '')}${i.quantity ? ` (Qty: ${esc(i.quantity)})` : ''}`;
            html += `<div class="col-md-6"><div class="form-check">
                <input class="form-check-input item-check" type="checkbox" value="${esc(i.item_name || '')}" id="${id}">
                <label class="form-check-label mini" for="${id}">${label}</label>
            </div></div>`;
        });
        html += '</div>';
        $('#inventoryBox').html(html);
    }, 'json');
}

function checkAvailability(){
    if(!CAN_BOOK || !BOOKING_READY) return;
    const payload = {
        action: 'check_availability',
        hall_name: $('#hallName').val(),
        booking_date: $('#bookDate').val(),
        start_hour: $('#startHour').val(),
        end_hour: $('#endHour').val()
    };
    $.post(API, withCsrf(payload), function(res){
        if(!res || res.status !== 'success'){
            $('#availabilityBox').html(`<span style="color:#b91c1c;">${esc(res && res.message ? res.message : 'Unable to check availability')}</span>`);
            return;
        }
        if(res.available){
            $('#availabilityBox').html('<span style="color:#166534; font-weight:700;">Hall is empty for selected slot.</span>');
            return;
        }
        const arr = Array.isArray(res.data) ? res.data : [];
        let html = '<div style="color:#92400e; font-weight:700;">Already booked / pending in this slot:</div><ul style="margin:8px 0 0 16px;">';
        arr.forEach(r => {
            html += `<li>H${esc(r.start_hour)}-H${esc(r.end_hour)} | ${esc(r.subject_name)} | ${esc(r.booked_by_name)} (${esc(r.booked_by_id)}) | ${esc(r.status)}</li>`;
        });
        html += '</ul>';
        $('#availabilityBox').html(html);
    }, 'json');
}

function submitBooking(){
    if(!CAN_BOOK){ toast('Not allowed'); return; }
    const items = [];
    $('.item-check:checked').each(function(){ items.push($(this).val()); });
    const payload = {
        action: 'create_booking',
        hall_name: $('#hallName').val(),
        booking_date: $('#bookDate').val(),
        start_hour: $('#startHour').val(),
        end_hour: $('#endHour').val(),
        subject_name: $('#subjectName').val(),
        class_name: $('#className').val(),
        event_title: $('#eventTitle').val(),
        purpose: $('#purpose').val(),
        items: items
    };
    $.post(API, withCsrf(payload), function(res){
        if(res && res.status === 'success'){
            toast(res.message || 'Booking submitted');
            $('#subjectName,#className,#eventTitle,#purpose').val('');
            $('.item-check').prop('checked', false);
            checkAvailability();
            loadBookings();
        } else {
            toast(res && res.message ? res.message : 'Unable to submit booking');
        }
    }, 'json').fail(function(){ toast('Booking request failed'); });
}

function loadBookings(){
    if(!BOOKING_READY){
        $('#bookingsBody').html('<tr><td colspan="8" class="text-center text-danger">Run fix_seminar_hall_db.php first.</td></tr>');
        return;
    }
    const payload = {
        action: 'list_bookings',
        from_date: $('#fromDate').val(),
        to_date: $('#toDate').val(),
        hall_name: $('#fltHall').val(),
        status: $('#fltStatus').val(),
        mine_only: $('#mineOnly').is(':checked') ? 1 : 0
    };
    $.post(API, withCsrf(payload), function(res){
        if(!res || res.status !== 'success'){
            $('#bookingsBody').html(`<tr><td colspan="8" class="text-center text-danger">${esc(res && res.message ? res.message : 'Unable to load bookings')}</td></tr>`);
            return;
        }
        const rows = Array.isArray(res.data) ? res.data : [];
        if(!rows.length){
            $('#bookingsBody').html('<tr><td colspan="8" class="text-center text-muted">No bookings found.</td></tr>');
            return;
        }
        let html = '';
        rows.forEach(r => {
            const t = `H${r.start_hour} (${HOURS[r.start_hour] || ''}) - H${r.end_hour} (${HOURS[r.end_hour] || ''})`;
            let actions = '<span class="mini text-muted">-</span>';
            if(CAN_APPROVE && String(r.status).toUpperCase() === 'PENDING'){
                actions = `<button class="btn btn-sm btn-outline-success me-1" onclick="updateStatus(${r.id},'APPROVED')">Approve</button>
                           <button class="btn btn-sm btn-outline-danger me-1" onclick="updateStatus(${r.id},'REJECTED')">Reject</button>`;
            } else if(CAN_APPROVE && String(r.status).toUpperCase() === 'APPROVED' && Number(r.key_collected || 0) !== 1){
                actions = `<button class="btn btn-sm btn-outline-primary" onclick="markKey(${r.id})">Mark Key Collected</button>`;
            } else if(String(r.status).toUpperCase() === 'APPROVED' && Number(r.key_collected || 0) === 1){
                actions = `<span class="mini text-success">Key Collected</span>`;
            }
            html += `<tr>
                <td>${esc(r.booking_date)}</td>
                <td>${esc(r.hall_name)}</td>
                <td>${esc(t)}</td>
                <td><strong>${esc(r.subject_name)}</strong><br><span class="mini">${esc(r.class_name || r.event_title || '-')}</span></td>
                <td>${esc(r.booked_by_name)}<br><span class="mini">${esc(r.booked_by_id)}</span></td>
                <td>${statusBadge(r.status)}<br><span class="mini">${esc(r.ao_remarks || '')}</span></td>
                <td>${esc(r.requested_items || '-')}</td>
                <td>${actions}</td>
            </tr>`;
        });
        $('#bookingsBody').html(html);
    }, 'json').fail(function(){
        $('#bookingsBody').html('<tr><td colspan="8" class="text-center text-danger">Unable to load bookings.</td></tr>');
    });
}

function updateStatus(id, newStatus){
    const remarks = prompt(`Enter remarks for ${newStatus}:`, '') || '';
    $.post(API, withCsrf({action:'update_status', id:id, new_status:newStatus, remarks:remarks}), function(res){
        if(res && res.status === 'success'){
            toast(res.message || 'Updated');
            loadBookings();
            checkAvailability();
        } else {
            toast(res && res.message ? res.message : 'Unable to update status');
        }
    }, 'json').fail(function(){ toast('Update failed'); });
}

function markKey(id){
    if(!confirm('Mark key as collected for this booking?')) return;
    $.post(API, withCsrf({action:'mark_key_collected', id:id}), function(res){
        if(res && res.status === 'success'){
            toast(res.message || 'Updated');
            loadBookings();
        } else {
            toast(res && res.message ? res.message : 'Unable to mark key collection');
        }
    }, 'json').fail(function(){ toast('Update failed'); });
}

$('#btnBook').on('click', submitBooking);
$('#btnLoad').on('click', loadBookings);
$('#hallName,#bookDate,#startHour,#endHour').on('change', checkAvailability);
$('#fromDate,#toDate,#fltHall,#fltStatus,#mineOnly').on('change', loadBookings);

$(document).ready(function(){
    loadInventory();
    loadBookings();
    checkAvailability();
});
</script>

<?php
$footer_path = find_include_path($include_paths, 'includes/footer.php');
if ($footer_path) {
    include $footer_path;
}
?>
