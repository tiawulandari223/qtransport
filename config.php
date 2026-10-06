<?php
if (!defined('APP_ROOT')) { http_response_code(404); exit; }

// Loads .env (if present) then applies defaults. No Composer, works on any PHP 8 host.

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
}

return [
    'db_path'      => getenv('DB_PATH') ?: __DIR__ . '/storage/app.sqlite',
    'reports_path' => getenv('REPORTS_PATH') ?: __DIR__ . '/storage/reports',
    'app_name'     => 'Q-Transport',
    'session_ttl'  => (int)(getenv('SESSION_TTL') ?: 480) * 60, // minutes -> seconds
    'base_url'     => rtrim(getenv('BASE_URL') ?: '', '/'),
];
