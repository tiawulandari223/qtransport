<?php

const ROLE_LABELS = [
    'admin' => 'Admin',
    'quality' => 'Quality/QRM',
    'logistics' => 'Logistik/Distribusi',
    'management' => 'Manajemen',
    'auditor' => 'Auditor',
];

/** Page => roles allowed to view it. Everything else is denied (enforced server-side). */
const PAGE_ROLES = [
    'dashboard' => ['admin', 'quality', 'logistics', 'management', 'auditor'],
    'lane'      => ['admin', 'quality', 'logistics', 'management', 'auditor'],
    'tps'       => ['admin', 'quality', 'logistics', 'management', 'auditor'],
    'event'     => ['admin', 'quality', 'logistics', 'management', 'auditor'],
    'risk'      => ['admin', 'quality', 'logistics', 'management', 'auditor'],
    'report'    => ['admin', 'quality', 'logistics', 'management', 'auditor'],
    'admin'     => ['admin'],
    'audit'     => ['admin', 'quality', 'auditor'],
];

/** Per-page write permissions: page => action key => roles. */
const PAGE_ACTIONS = [
    'lane'    => ['write' => ['admin', 'logistics']],
    'tps'     => ['write' => ['admin'], 'approve' => ['quality']],
    'event'   => ['write' => ['admin', 'logistics', 'quality'], 'close' => ['admin', 'quality']],
    'risk'    => ['save' => ['admin', 'logistics', 'management'], 'approve' => ['quality']],
    'report'  => ['generate' => ['admin', 'quality'], 'download' => ['admin', 'quality', 'logistics', 'management', 'auditor']],
    'admin'   => ['*' => ['admin']],
];

function auth_user(): ?array {
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
    if (!$u) unset($_SESSION['uid']);
    return $u;
}

function login(string $email, string $password): bool {
    $u = one('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
    if (!$u || !$u['active'] || !password_verify($password, $u['password_hash'])) return false;
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    audit_log('session', (string)$u['id'], 'login', null, ['email' => $u['email']]);
    return true;
}

function logout(): void {
    if ($u = auth_user()) audit_log('session', (string)$u['id'], 'logout', null, null);
    session_unset();
    session_destroy();
}

function can(string $page, string $action = 'read'): bool {
    $u = auth_user();
    if (!$u) return false;
    $role = $u['role'];
    if ($action === 'read') return in_array($role, PAGE_ROLES[$page] ?? [], true);
    return in_array($role, PAGE_ACTIONS[$page][$action] ?? [], true);
}

function deny_unless(bool $ok, string $msg = 'Akses ditolak.'): void {
    if ($ok) return;
    http_response_code(403);
    if (want_json()) {
        header('Content-Type: application/json');
        exit(json_encode(['error' => $msg]));
    }
    exit('<div class="panel"><h3>403 — ' . e($msg) . '</h3><p><a href="index.php?p=dashboard">Kembali ke dashboard</a></p></div>');
}
