<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_login();
header_page('Deploy Status');
$root = dirname(__DIR__, 3);
$log = $root . '/app/collector/storage/deploy_webhook.log';
$branch = 'unknown';
$head = 'unknown';
if (is_dir($root . '/.git')) {
    @exec('cd ' . escapeshellarg($root) . ' && git rev-parse --abbrev-ref HEAD 2>&1', $b);
    @exec('cd ' . escapeshellarg($root) . ' && git rev-parse HEAD 2>&1', $h);
    $branch = trim(implode("\n", $b));
    $head = trim(implode("\n", $h));
}
$tail = file_exists($log) ? implode('', array_slice(file($log), -120)) : 'No webhook deploy log yet.';
echo '<section class="card"><div class="row"><strong>Branch</strong><span>'.h($branch).'</span></div><div class="row"><strong>HEAD</strong><span>'.h($head).'</span></div></section>';
echo '<section class="card"><h2>Webhook URL</h2><pre>https://revenuepack.com/deploy_webhook.php</pre></section>';
echo '<section class="card"><h2>Latest Log</h2><pre>'.h($tail).'</pre></section>';
footer_page();
?>
