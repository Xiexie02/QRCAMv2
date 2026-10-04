<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/config.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_response(['error' => 'Request body must be valid JSON.'], 400);
    }
    return $body;
}

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf']) || !is_string($provided) ||
        !hash_equals($_SESSION['csrf'], $provided)) {
        json_response(['error' => 'Your session token expired. Reload the page and try again.'], 419);
    }
}

function require_adviser(): string
{
    if (empty($_SESSION['adviser'])) {
        json_response(['error' => 'Sign in to continue.'], 401);
    }
    return (string)$_SESSION['adviser'];
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function new_id(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
        substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

try {
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        json_response([
            'authenticated' => !empty($_SESSION['adviser']),
            'adviser' => $_SESSION['adviser'] ?? null,
            'csrf' => $_SESSION['csrf'],
        ]);
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $body = request_body();
        $username = qrcam_config('ADMIN_USERNAME');
        $hash = qrcam_config('ADMIN_PASSWORD_HASH');
        if ($username === '' || $hash === '') {
            json_response(['error' => 'Adviser login is not configured. Set ADMIN_USERNAME and ADMIN_PASSWORD_HASH in .env.'], 503);
        }
        if (!hash_equals($username, (string)($body['username'] ?? '')) ||
            !password_verify((string)($body['password'] ?? ''), $hash)) {
            json_response(['error' => 'Invalid username or password.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['adviser'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_response(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        session_unset();
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_response(['ok' => true]);
    }

    if ($action === 'scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $body = request_body();
        $token = trim((string)($body['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            json_response(['error' => 'This QR code is not valid.'], 400);
        }
        $store = qrcam_store();
        $student = $store->findOne('students', ['qr_token' => $token]);
        if ($student === null) {
            json_response(['error' => 'No enrolled student matches this QR code.'], 404);
        }
        $today = date('Y-m-d');
        $existing = $store->findOne('attendance', [
            'student_id' => $student['id'],
            'attendance_date' => $today,
        ]);
        if ($existing !== null) {
            json_response(['ok' => true, 'duplicate' => true, 'student' => $student, 'attendance' => $existing]);
        }
        $now = getenv('DB_DRIVER') === 'supabase' ? date(DATE_ATOM) : date('Y-m-d H:i:s');
        try {
            $entry = $store->insert('attendance', [
                'id' => new_id(),
                'student_id' => $student['id'],
                'attendance_date' => $today,
                'attended_at' => $now,
                'status' => 'present',
                'note' => '',
            ]);
        } catch (Throwable $error) {
            $entry = $store->findOne('attendance', [
                'student_id' => $student['id'],
                'attendance_date' => $today,
            ]);
            if ($entry === null) {
                throw $error;
            }
            json_response(['ok' => true, 'duplicate' => true, 'student' => $student, 'attendance' => $entry]);
        }
        json_response(['ok' => true, 'duplicate' => false, 'student' => $student, 'attendance' => $entry]);
    }

    $actor = require_adviser();
    $store = qrcam_store();

    if ($action === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $today = date('Y-m-d');
        $students = $store->findAll('students', [], 'full_name');
        $attendance = $store->findAll('attendance', ['attendance_date' => $today], 'attended_at');
        $byStudent = [];
        foreach ($attendance as $row) {
            $byStudent[$row['student_id']] = $row;
        }
        $present = count(array_filter($attendance, static fn(array $r): bool => $r['status'] === 'present'));
        $absent = max(0, count($students) - $present);
        $auditLogs = $store->findAll('audit_logs', [], 'created_at', true, 20);
        foreach ($auditLogs as &$log) {
            foreach (['before_data', 'after_data'] as $field) {
                if (is_string($log[$field] ?? null)) {
                    $log[$field] = json_decode($log[$field], true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }
        unset($log);
        json_response([
            'date' => $today,
            'students' => $students,
            'attendance' => $attendance,
            'present' => $present,
            'absent' => $absent,
            'total' => count($students),
            'byStudent' => $byStudent,
            'auditLogs' => $auditLogs,
        ]);
    }

    if ($action === 'students' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['students' => $store->findAll('students', [], 'full_name')]);
    }

    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'Choose a readable CSV file to upload.'], 400);
        }
        if ($_FILES['csv']['size'] > 5 * 1024 * 1024) {
            json_response(['error' => 'CSV files must be 5 MB or smaller.'], 413);
        }
        $handle = fopen($_FILES['csv']['tmp_name'], 'rb');
        if ($handle === false) {
            json_response(['error' => 'The uploaded CSV could not be read.'], 400);
        }
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            json_response(['error' => 'CSV is empty or missing its header row.'], 400);
        }
        $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        $studentNoCol = array_search('student_no', $header, true);
        $fullNameCol = array_search('full_name', $header, true);
        $sectionCol = array_search('class_section', $header, true);
        if ($studentNoCol === false || $fullNameCol === false) {
            fclose($handle);
            json_response(['error' => 'CSV needs student_no and full_name columns.'], 400);
        }
        $created = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($line > 2001) {
                $errors[] = 'Import limit is 2,000 data rows per file.';
                break;
            }
            if (!array_filter($row, static fn($value): bool => trim((string)$value) !== '')) {
                continue;
            }
            $studentNo = trim((string)($row[$studentNoCol] ?? ''));
            $fullName = trim((string)($row[$fullNameCol] ?? ''));
            $section = $sectionCol === false ? '' : trim((string)($row[$sectionCol] ?? ''));
            if ($studentNo === '' || $fullName === '') {
                $errors[] = "Row $line: student_no and full_name are required.";
                continue;
            }
            if (mb_strlen($studentNo) > 80 || mb_strlen($fullName) > 200 || mb_strlen($section) > 120) {
                $errors[] = "Row $line: one or more values exceed the allowed length.";
                continue;
            }
            if ($store->findOne('students', ['student_no' => $studentNo]) !== null) {
                $skipped++;
                continue;
            }
            try {
                $store->insert('students', [
                    'id' => new_id(),
                    'student_no' => $studentNo,
                    'full_name' => $fullName,
                    'class_section' => $section,
                    'qr_token' => bin2hex(random_bytes(32)),
                    'created_at' => getenv('DB_DRIVER') === 'supabase' ? date(DATE_ATOM) : date('Y-m-d H:i:s'),
                ]);
                $created++;
            } catch (Throwable $error) {
                $errors[] = "Row $line: " . $error->getMessage();
            }
        }
        fclose($handle);
        json_response(['ok' => true, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors]);
    }

    if ($action === 'set_attendance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $body = request_body();
        $studentId = (string)($body['student_id'] ?? '');
        $date = (string)($body['date'] ?? '');
        $status = (string)($body['status'] ?? '');
        $note = trim((string)($body['note'] ?? ''));
        if (!valid_date($date) || !in_array($status, ['present', 'absent', 'excused'], true) ||
            strlen($note) > 500 || $store->findOne('students', ['id' => $studentId]) === null) {
            json_response(['error' => 'Provide an enrolled student, valid date, status, and note (500 characters max).'], 400);
        }
        $timestamp = getenv('DB_DRIVER') === 'supabase' ? date(DATE_ATOM) : date('Y-m-d H:i:s');
        $updated = $store->adjustAttendance($actor, [
            'id' => new_id(),
            'student_id' => $studentId,
            'attendance_date' => $date,
            'attended_at' => $timestamp,
            'status' => $status,
            'note' => $note,
        ], new_id(), $timestamp);
        json_response(['ok' => true, 'attendance' => $updated]);
    }

    if ($action === 'report' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $start = (string)($_GET['start'] ?? '');
        $end = (string)($_GET['end'] ?? '');
        if (!valid_date($start) || !valid_date($end) || $start > $end) {
            json_response(['error' => 'Choose a valid start and end date.'], 400);
        }
        $from = new DateTimeImmutable($start);
        $to = new DateTimeImmutable($end);
        $days = (int)$from->diff($to)->days + 1;
        if ($days > 366) {
            json_response(['error' => 'Reports can cover at most 366 days at a time.'], 400);
        }
        $students = $store->findAll('students', [], 'full_name');
        if (count($students) * $days > 100000) {
            json_response(['error' => 'This report is too large. Select a shorter date range.'], 413);
        }
        $attendance = $store->findAll('attendance', [
            'attendance_date' => ['>=', $start],
        ]);
        $attendance = array_values(array_filter($attendance, static fn(array $r): bool => $r['attendance_date'] <= $end));
        $indexed = [];
        foreach ($attendance as $entry) {
            $indexed[$entry['attendance_date'] . ':' . $entry['student_id']] = $entry;
        }
        $rows = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $dayText = $day->format('Y-m-d');
            foreach ($students as $student) {
                $entry = $indexed[$dayText . ':' . $student['id']] ?? null;
                $rows[] = [
                    'date' => $dayText,
                    'student_no' => $student['student_no'],
                    'full_name' => $student['full_name'],
                    'class_section' => $student['class_section'],
                    'status' => $entry['status'] ?? 'absent',
                    'attended_at' => $entry['attended_at'] ?? '',
                    'note' => $entry['note'] ?? '',
                ];
            }
        }
        json_response(['rows' => $rows]);
    }

    json_response(['error' => 'Unknown action or unsupported method.'], 404);
} catch (Throwable $error) {
    error_log('[QRCAM] ' . $error::class . ': ' . $error->getMessage());
    json_response(['error' => 'The request failed: ' . $error->getMessage()], 500);
}
