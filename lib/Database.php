<?php
declare(strict_types=1);

interface QrcamStore
{
    public function findOne(string $table, array $filters): ?array;
    public function findAll(string $table, array $filters = [], string $order = '', bool $descending = false, int $limit = 0): array;
    public function insert(string $table, array $row): array;
    public function update(string $table, array $filters, array $changes): array;
    public function adjustAttendance(string $actor, array $record, string $auditId, string $auditCreatedAt): array;
}

final class MysqlStore implements QrcamStore
{
    private PDO $pdo;
    private const TABLES = ['students', 'attendance', 'audit_logs'];

    public function __construct(array $config)
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['MYSQL_HOST'], $config['MYSQL_PORT'], $config['MYSQL_DATABASE']);
        $this->pdo = new PDO($dsn, $config['MYSQL_USERNAME'], $config['MYSQL_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function findOne(string $table, array $filters): ?array
    {
        return $this->findAll($table, $filters)[0] ?? null;
    }

    public function findAll(string $table, array $filters = [], string $order = '', bool $descending = false, int $limit = 0): array
    {
        $this->assertTable($table);
        $where = [];
        $values = [];
        foreach ($filters as $column => $condition) {
            $this->assertColumn($column);
            $operator = is_array($condition) ? ($condition[0] ?? '=') : '=';
            $value = is_array($condition) ? ($condition[1] ?? null) : $condition;
            if (!in_array($operator, ['=', '>=', '<=', '>', '<'], true)) {
                throw new InvalidArgumentException('Unsupported filter operator.');
            }
            $where[] = "`$column` $operator ?";
            $values[] = $value;
        }
        $sql = "SELECT * FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        if ($order !== '') {
            $this->assertColumn($order);
            $sql .= " ORDER BY `$order`" . ($descending ? ' DESC' : ' ASC');
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . min($limit, 1000);
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll();
    }

    public function insert(string $table, array $row): array
    {
        $this->assertTable($table);
        foreach ($row as $column => $value) {
            $this->assertColumn($column);
            if (in_array($column, ['before_data', 'after_data'], true) && is_array($value)) {
                $row[$column] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }
        $columns = array_keys($row);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $columns) . '`) VALUES (' .
            implode(',', array_fill(0, count($columns), '?')) . ')';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($row));
        return $row;
    }

    public function update(string $table, array $filters, array $changes): array
    {
        $this->assertTable($table);
        if (!$filters || !$changes) {
            throw new InvalidArgumentException('Updates require filters and changes.');
        }
        $set = [];
        $values = [];
        foreach ($changes as $column => $value) {
            $this->assertColumn($column);
            $set[] = "`$column` = ?";
            if (in_array($column, ['before_data', 'after_data'], true) && is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $values[] = $value;
        }
        $where = [];
        foreach ($filters as $column => $value) {
            $this->assertColumn($column);
            $where[] = "`$column` = ?";
            $values[] = $value;
        }
        $statement = $this->pdo->prepare("UPDATE `$table` SET " . implode(',', $set) .
            ' WHERE ' . implode(' AND ', $where));
        $statement->execute($values);
        return $this->findOne($table, $filters) ?? [];
    }

    public function adjustAttendance(string $actor, array $record, string $auditId, string $auditCreatedAt): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM students WHERE id = ? FOR UPDATE');
            $lock->execute([$record['student_id']]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Student record no longer exists.');
            }
            $filters = [
                'student_id' => $record['student_id'],
                'attendance_date' => $record['attendance_date'],
            ];
            $before = $this->findOne('attendance', $filters);
            if ($before === null) {
                $after = $this->insert('attendance', $record);
            } else {
                $after = $this->update('attendance', ['id' => $before['id']], [
                    'status' => $record['status'],
                    'note' => $record['note'],
                ]);
            }
            if (!$after) {
                throw new RuntimeException('Attendance adjustment could not be read back.');
            }
            $this->insert('audit_logs', [
                'id' => $auditId,
                'actor' => $actor,
                'action' => 'attendance_adjusted',
                'record_id' => $after['id'],
                'before_data' => $before,
                'after_data' => $after,
                'created_at' => $auditCreatedAt,
            ]);
            $this->pdo->commit();
            return $after;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown table.');
        }
    }

    private function assertColumn(string $column): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
            throw new InvalidArgumentException('Invalid column name.');
        }
    }
}

final class SupabaseStore implements QrcamStore
{
    private string $url;
    private string $key;
    private const TABLES = ['students', 'attendance', 'audit_logs'];

    public function __construct(string $url, string $key)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for Supabase mode.');
        }
        $this->url = rtrim($url, '/');
        $this->key = $key;
    }

    public function findOne(string $table, array $filters): ?array
    {
        return $this->findAll($table, $filters)[0] ?? null;
    }

    public function findAll(string $table, array $filters = [], string $order = '', bool $descending = false, int $limit = 0): array
    {
        $query = [];
        foreach ($filters as $column => $condition) {
            $operator = is_array($condition) ? ($condition[0] ?? '=') : '=';
            $value = is_array($condition) ? ($condition[1] ?? null) : $condition;
            $ops = ['=' => 'eq', '>=' => 'gte', '<=' => 'lte', '>' => 'gt', '<' => 'lt'];
            if (!isset($ops[$operator])) {
                throw new InvalidArgumentException('Unsupported filter operator.');
            }
            $query[$column] = $ops[$operator] . '.' . (string)$value;
        }
        if ($order !== '') {
            $query['order'] = $order . ($descending ? '.desc' : '.asc');
        }
        if ($limit > 0) {
            $query['limit'] = (string)min($limit, 1000);
        }
        return $this->request('GET', $table, $query);
    }

    public function insert(string $table, array $row): array
    {
        $records = $this->request('POST', $table, [], $row, 'return=representation');
        return $records[0] ?? $row;
    }

    public function update(string $table, array $filters, array $changes): array
    {
        $query = [];
        foreach ($filters as $column => $value) {
            $query[$column] = 'eq.' . (string)$value;
        }
        $records = $this->request('PATCH', $table, $query, $changes, 'return=representation');
        return $records[0] ?? [];
    }

    public function adjustAttendance(string $actor, array $record, string $auditId, string $auditCreatedAt): array
    {
        $records = $this->request('POST', 'adjust_attendance', [], [
            'p_actor' => $actor,
            'p_student_id' => $record['student_id'],
            'p_attendance_date' => $record['attendance_date'],
            'p_status' => $record['status'],
            'p_note' => $record['note'],
            'p_attendance_id' => $record['id'],
            'p_attended_at' => $record['attended_at'],
            'p_audit_id' => $auditId,
            'p_audit_created_at' => $auditCreatedAt,
        ], '', true);
        $result = $records[0] ?? null;
        if (!is_array($result) || !is_array($result['after'] ?? null)) {
            throw new RuntimeException('Supabase did not return the adjusted attendance record.');
        }
        return $result['after'];
    }

    private function request(string $method, string $table, array $query = [], ?array $body = null, string $prefer = '', bool $rpc = false): array
    {
        if ((!$rpc && !in_array($table, self::TABLES, true)) || ($rpc && $table !== 'adjust_attendance')) {
            throw new InvalidArgumentException('Unknown table.');
        }
        $url = $this->url . '/rest/v1/' . ($rpc ? 'rpc/' : '') . rawurlencode($table);
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = [
            'apikey: ' . $this->key,
            'Authorization: Bearer ' . $this->key,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($prefer !== '') {
            $headers[] = 'Prefer: ' . $prefer;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            throw new RuntimeException('Supabase request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Supabase returned HTTP ' . $status . ': ' . substr($response, 0, 500));
        }
        if ($response === '') {
            return [];
        }
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? (array_is_list($decoded) ? $decoded : [$decoded]) : [];
    }
}
