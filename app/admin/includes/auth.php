<?php
require_once __DIR__ . '/db.php';
session_name(ADMIN_SESSION_NAME);
session_start();
function user() { return $_SESSION['user'] ?? null; }
function require_login() { if (!user()) { header('Location: ' . admin_url('login.php')); exit; } }
function login($email, $password) {
    $u = one('SELECT * FROM admin_users WHERE email=? AND is_active=1 LIMIT 1', [$email]);
    if (!$u || !password_verify($password, $u['password_hash'])) return false;
    $_SESSION['user'] = ['id'=>$u['id'], 'email'=>$u['email'], 'role'=>$u['role']];
    exec_sql('UPDATE admin_users SET last_login_at=NOW() WHERE id=?', [$u['id']]);
    return true;
}
function csrf() { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function require_csrf() { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(403); exit('Invalid CSRF'); } }
?>
