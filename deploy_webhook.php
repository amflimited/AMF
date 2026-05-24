<?php
// RevenuePack immediate GitHub webhook deploy endpoint.
// GitHub should POST push events here. This file verifies the HMAC signature,
// then hard-resets the live repo to origin/cpanel-deploy while preserving .env.

$repoRoot = __DIR__;
$branch = 'cpanel-deploy';
$envPath = $repoRoot . '/.env';
$logPath = $repoRoot . '/app/collector/storage/deploy_webhook.log';
$lockPath = $repoRoot . '/app/collector/storage/deploy_webhook.lock';

function log_line($path, $message) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function load_env_value($path, $key) {
    if (!file_exists($path)) return null;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === $key) return trim($v);
    }
    return null;
}

function respond($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function run_cmd($cmd, $logPath) {
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    log_line($logPath, '$ ' . $cmd);
    foreach ($output as $line) log_line($logPath, '  ' . $line);
    if ($code !== 0) throw new Exception('Command failed: ' . $cmd);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(404, 'not found');
}

$secret = load_env_value($envPath, 'DEPLOY_WEBHOOK_SECRET');
if (!$secret || strlen($secret) < 24) {
    log_line($logPath, 'ABORT: DEPLOY_WEBHOOK_SECRET missing or too short');
    respond(500, 'deploy secret not configured');
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!$signature || !hash_equals($expected, $signature)) {
    log_line($logPath, 'ABORT: invalid signature');
    respond(403, 'invalid signature');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    log_line($logPath, 'IGNORED event=' . $event);
    respond(202, 'ignored non-push event');
}

$data = json_decode($payload, true);
$ref = $data['ref'] ?? '';
if ($ref !== 'refs/heads/' . $branch) {
    log_line($logPath, 'IGNORED ref=' . $ref);
    respond(202, 'ignored branch');
}

$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    log_line($logPath, 'BUSY: deploy already running');
    respond(409, 'deploy already running');
}

try {
    chdir($repoRoot);
    log_line($logPath, 'START deploy for ' . $ref);

    if (!is_dir($repoRoot . '/.git')) {
        throw new Exception('No .git directory at repo root');
    }

    $backupPath = dirname($repoRoot) . '/revenuepack.env.webhook.backup';
    if (file_exists($envPath)) @copy($envPath, $backupPath);

    run_cmd('git fetch origin ' . escapeshellarg($branch), $logPath);
    run_cmd('git checkout ' . escapeshellarg($branch), $logPath);
    run_cmd('git reset --hard origin/' . escapeshellarg($branch), $logPath);
    run_cmd('git clean -fd --exclude=.env --exclude=app/.env', $logPath);

    if (file_exists($backupPath)) {
        @copy($backupPath, $envPath);
        @chmod($envPath, 0600);
    }

    $head = trim(shell_exec('git rev-parse HEAD 2>&1'));
    log_line($logPath, 'SUCCESS HEAD=' . $head);
    flock($lock, LOCK_UN);
    respond(200, 'deployed ' . $head);
} catch (Throwable $e) {
    log_line($logPath, 'FAILED: ' . $e->getMessage());
    if (isset($backupPath) && file_exists($backupPath)) {
        @copy($backupPath, $envPath);
        @chmod($envPath, 0600);
    }
    flock($lock, LOCK_UN);
    respond(500, 'deploy failed');
}
?>
