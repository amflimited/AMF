<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/module_manifest.php';
require_login();
header_page('System Health');
$root = dirname(__DIR__, 3);
function exists_badge($ok) { return $ok ? '<span style="color:#047857;font-weight:700">OK</span>' : '<span style="color:#b91c1c;font-weight:700">MISSING</span>'; }
function table_exists_health($table) {
    try {
        $row = one('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
        return ((int)($row['c'] ?? 0)) > 0;
    } catch (Throwable $e) { return false; }
}
$commit = 'unknown'; $branch = 'unknown';
if (is_dir($root.'/.git')) {
    @exec('cd '.escapeshellarg($root).' && git rev-parse --abbrev-ref HEAD 2>&1', $b);
    @exec('cd '.escapeshellarg($root).' && git rev-parse HEAD 2>&1', $h);
    $branch = trim(implode("\n", $b));
    $commit = trim(implode("\n", $h));
}
$envOk = file_exists($root.'/.env');
?>
<section class="card">
<h2>Live Deploy</h2>
<div class="row"><strong>Branch</strong><span><?=h($branch)?></span></div>
<div class="row"><strong>HEAD</strong><span><?=h($commit)?></span></div>
<div class="row"><strong>.env</strong><span><?=exists_badge($envOk)?></span></div>
</section>
<?php foreach (spec1_expected_files() as $group => $files): ?>
<section class="card">
<h2><?=h($group)?></h2>
<?php foreach ($files as $file): $ok = file_exists($root.'/'.$file); ?>
<div class="row"><strong><?=h($file)?></strong><span><?=exists_badge($ok)?></span></div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php foreach (spec1_expected_tables() as $group => $tables): ?>
<section class="card">
<h2><?=h($group)?> Tables</h2>
<?php foreach ($tables as $table): $ok = table_exists_health($table); ?>
<div class="row"><strong><?=h($table)?></strong><span><?=exists_badge($ok)?></span></div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<section class="card">
<h2>Required Background Crons</h2>
<p>These are not readable from PHP on shared hosting. They must be present in cPanel Cron Jobs.</p>
<?php foreach (spec1_expected_crons() as $name => $cmd): ?>
<p><strong><?=h($name)?></strong></p><pre><?=h($cmd)?></pre>
<?php endforeach; ?>
</section>
<?php footer_page(); ?>
