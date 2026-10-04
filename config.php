<?php
declare(strict_types=1);

$envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

function qrcam_config(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function qrcam_store(): QrcamStore
{
    static $store = null;
    if ($store instanceof QrcamStore) {
        return $store;
    }
    $driver = qrcam_config('DB_DRIVER', 'mysql');
    if ($driver === 'mysql') {
        $store = new MysqlStore([
            'MYSQL_HOST' => qrcam_config('MYSQL_HOST', '127.0.0.1'),
            'MYSQL_PORT' => qrcam_config('MYSQL_PORT', '3306'),
            'MYSQL_DATABASE' => qrcam_config('MYSQL_DATABASE', 'qrcam'),
            'MYSQL_USERNAME' => qrcam_config('MYSQL_USERNAME', 'root'),
            'MYSQL_PASSWORD' => qrcam_config('MYSQL_PASSWORD'),
        ]);
        return $store;
    }
    if ($driver === 'supabase') {
        $url = qrcam_config('SUPABASE_URL');
        $key = qrcam_config('SUPABASE_SERVICE_ROLE_KEY');
        if ($url === '' || $key === '') {
            throw new RuntimeException('Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY in .env.');
        }
        $store = new SupabaseStore($url, $key);
        return $store;
    }
    throw new RuntimeException('DB_DRIVER must be mysql or supabase.');
}
