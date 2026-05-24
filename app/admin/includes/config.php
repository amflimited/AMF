<?php
function load_env_file($path) {
    if (!file_exists($path)) return false;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
    return true;
}
$appDir = dirname(__DIR__, 2);
$publicRoot = dirname($appDir);
load_env_file($publicRoot . '/.env') || load_env_file($appDir . '/.env');
define('ADMIN_SESSION_NAME', $_ENV['ADMIN_SESSION_NAME'] ?? 'spec1_admin');
function admin_base_url() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/app/admin/index.php';
    $pos = strpos($script, '/admin/');
    if ($pos !== false) return substr($script, 0, $pos + strlen('/admin'));
    return '/app/admin';
}
function admin_url($path = '') {
    return rtrim(admin_base_url(), '/') . '/' . ltrim($path, '/');
}
?>
