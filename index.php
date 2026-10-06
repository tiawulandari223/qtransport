<?php
// Q-Transport front controller. Every request goes through index.php?p=<page>[&a=<action>].

require __DIR__ . '/src/bootstrap.php';

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'dashboard'));
if ($page === '') $page = 'dashboard';

if ($page === 'logout') {
    logout();
    redirect('login');
}

$known = array_keys(PAGE_ROLES) + ['login'];

if ($page === 'login') {
    if (auth_user()) redirect('dashboard');
    require __DIR__ . '/pages/login.php';
    exit;
}

if (!auth_user()) redirect('login');
if (!in_array($page, $known, true)) {
    http_response_code(404);
    $page = 'dashboard';
}

deny_unless(can($page), 'Role Anda tidak memiliki akses ke modul ini.');

ob_start();
require __DIR__ . '/pages/' . $page . '.php';
$content = ob_get_clean();

require __DIR__ . '/layout.php';
