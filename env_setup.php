<?php
// Temporary environment setup helper.
// Delete after successful .env creation.

function page_start($title) {
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;max-width:760px;margin:32px auto;padding:16px;line-height:1.45">';
    echo '<h1 style="font-size:34px;line-height:1.05">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
}
function page_end() { echo '</body>'; }
function pre_block($text) {
    echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#f3f4f6;padding:12px;border-radius:12px;border:1px solid #e5e7eb">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</pre>';
}
function form_value($key, $default='') { return htmlspecialchars($_POST[$key] ?? $default, ENT_QUOTES, 'UTF-8'); }

$envPath = __DIR__ . '/.env';
$appEnvPath = __DIR__ . '/app/.env';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_password'] ?? '');
    $charset = trim($_POST['db_charset'] ?? 'utf8mb4');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        page_start('Missing required values');
        echo '<p>DB host, name, and user are required.</p><p><a href="/env_setup.php">Go back</a></p>';
        page_end();
        exit;
    }

    try {
        $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=' . $charset;
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        page_start('Database test failed');
        echo '<p>The credentials were not saved because the connection test failed.</p>';
        pre_block($e->getMessage());
        echo '<p><a href="/env_setup.php">Try again</a></p>';
        page_end();
        exit;
    }

    $env = "APP_ENV=production\n";
    $env .= "APP_BASE_PATH=" . __DIR__ . "/app\n";
    $env .= "DB_HOST=" . $dbHost . "\n";
    $env .= "DB_NAME=" . $dbName . "\n";
    $env .= "DB_USER=" . $dbUser . "\n";
    $env .= "DB_PASSWORD=" . $dbPass . "\n";
    $env .= "DB_CHARSET=" . $charset . "\n";
    $env .= "ADMIN_SESSION_NAME=spec1_admin\n";

    $ok = file_put_contents($envPath, $env, LOCK_EX);
    @chmod($envPath, 0600);

    // Also write app/.env as a compatibility fallback for early package versions.
    @file_put_contents($appEnvPath, $env, LOCK_EX);
    @chmod($appEnvPath, 0600);

    if ($ok === false) {
        page_start('Could not write .env');
        echo '<p>The database test passed, but the server could not write:</p>';
        pre_block($envPath);
        echo '<p>Create the file manually using File Manager.</p>';
        page_end();
        exit;
    }

    $deletedSelf = false;
    if (($_POST['delete_after'] ?? '') === '1') {
        $deletedSelf = @unlink(__FILE__);
    }

    page_start('.env created');
    echo '<p>The database connection tested successfully and .env was written.</p>';
    echo '<p>Next open:</p>';
    pre_block('https://revenuepack.com/root_setup.php');
    echo '<p>If env_setup.php was not deleted automatically, delete it now:</p>';
    pre_block('/public_html/env_setup.php');
    echo '<p>Self-delete result: <strong>' . ($deletedSelf ? 'deleted' : 'not deleted') . '</strong></p>';
    page_end();
    exit;
}

page_start('RevenuePack Environment Setup');
echo '<p>This writes the server-only .env file. Do not leave this file on the server after setup.</p>';
echo '<form method="post">';
echo '<label>DB Host</label><input name="db_host" value="' . form_value('db_host', 'localhost') . '" style="width:100%;height:48px;margin:6px 0 12px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px;font-size:16px">';
echo '<label>DB Name</label><input name="db_name" value="' . form_value('db_name') . '" style="width:100%;height:48px;margin:6px 0 12px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px;font-size:16px">';
echo '<label>DB User</label><input name="db_user" value="' . form_value('db_user') . '" style="width:100%;height:48px;margin:6px 0 12px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px;font-size:16px">';
echo '<label>DB Password</label><input name="db_password" type="password" style="width:100%;height:48px;margin:6px 0 12px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px;font-size:16px">';
echo '<label>Charset</label><input name="db_charset" value="' . form_value('db_charset', 'utf8mb4') . '" style="width:100%;height:48px;margin:6px 0 12px;border:1px solid #d1d5db;border-radius:12px;padding:0 12px;font-size:16px">';
echo '<label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input type="checkbox" name="delete_after" value="1" checked style="width:20px;height:20px"> Delete this setup file after success</label>';
echo '<button style="width:100%;height:52px;border:0;background:#1f6feb;color:#fff;border-radius:14px;font-weight:700;font-size:16px">Test DB and Write .env</button>';
echo '</form>';
page_end();
?>
