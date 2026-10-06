<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

$config = require APP_ROOT . '/config.php';

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/src/' . strtolower($class) . '.php';
    if (is_file($file)) require_once $file;
});

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/calc.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/audit.php';
require_once APP_ROOT . '/src/reports.php';
require_once APP_ROOT . '/src/docx.php';

function config(?string $key = null) {
    global $config;
    return $key === null ? $config : ($config[$key] ?? null);
}

// ---- session ----
if (session_status() === PHP_SESSION_NONE) {
    session_name('qtransport');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
if (isset($_SESSION['_last']) && time() - $_SESSION['_last'] > config('session_ttl')) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['_last'] = time();

// Forms send the action in the POST body; the pages read it from $_GET['a'].
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['a'])) {
    $_GET['a'] = (string)$_POST['a'];
}

// ---- helpers ----
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page, array $qs = []): string {
    $qs = array_merge(['p' => $page], $qs);
    if (config('base_url')) $qs = array_merge(['p' => $page], array_diff_key($qs, ['p' => 1]));
    $base = config('base_url');
    return ($base ?: '') . '/index.php?' . http_build_query($qs);
}

function redirect(string $page, array $qs = []): never {
    header('Location: ' . url($page, $qs));
    exit;
}

function post(string $key, $default = null) {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function flash(string $msg, string $type = 'ok'): void {
    $_SESSION['_flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array {
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function csrf_token(): string {
    return $_SESSION['_csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    if (!hash_equals(csrf_token(), (string)post('_csrf', ''))) {
        http_response_code(419);
        exit('Token kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

/** Nullable integer from POST: '' / null / 'abc' all become null (never 0). */
function post_int(string $key, ?int $default = null): ?int {
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') return $default;
    return preg_match('/^-?\d+$/', (string)$v) ? (int)$v : $default;
}

function is_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function want_json(): bool {
    return ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json' || ($_GET['format'] ?? '') === 'json';
}
