<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/sample_schema.php';
require_login();
ensure_sample_tables();
header_page('Sample Runs');
$root = dirname(__DIR__, 3);
$log = $root . '/app/collector/storage/sample_worker.log';
$tail = file_exists($log) ? implode('', array_slice(file($log), -80)) : 'No sample worker log yet.';
$summary = one("SELECT
 (SELECT COUNT(*) FROM source_sample_runs) total,
 (SELECT COUNT(*) FROM source_sample_runs WHERE status='queued') queued,
 (SELECT COUNT(*) FROM source_sample_runs WHERE status='running') running,
 (SELECT COUNT(*) FROM source_sample_runs WHERE status='success') success,
 (SELECT COUNT(*) FROM source_sample_runs WHERE status IN ('failed','blocked','needs_parser_config')) failed,
 (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id LEFT JOIN source_sample_runs sr ON sr.source_candidate_id=e.source_candidate_id WHERE e.acquisition_route='sample_first' AND sr.id IS NULL) ready_to_queue");
$runs = rows("SELECT * FROM source_sample_runs ORDER BY queued_at DESC LIMIT 50");
?>
<section class="card"><h2>Queue Samples</h2><p>Creates sample-only runtime sources from sample-first candidates. Full import remains disabled.</p><form method="post" action="<?=h(admin_url('actions/queue-sample-runs.php'))?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Queue limit</label><input name="limit" type="number" min="1" max="25" value="10"><p><button>Queue Sample Runs</button></p></form></section>
<section class="card"><h2>Sample Worker Cron</h2><pre>/usr/local/bin/php -q /home/reveqwuv/public_html/app/collector/jobs/sample_worker.php 2 &gt;/dev/null 2&gt;&amp;1</pre><p>Recommended cadence: every 5 minutes while building MVP samples.</p></section>
<section class="card"><div class="row"><strong>Ready to queue</strong><span><?=h($summary['ready_to_queue'] ?? 0)?></span></div><div class="row"><strong>Queued</strong><span><?=h($summary['queued'] ?? 0)?></span></div><div class="row"><strong>Running</strong><span><?=h($summary['running'] ?? 0)?></span></div><div class="row"><strong>Success</strong><span><?=h($summary['success'] ?? 0)?></span></div><div class="row"><strong>Failed/config needed</strong><span><?=h($summary['failed'] ?? 0)?></span></div></section>
<section class="card"><h2>Worker Log</h2><pre><?=h($tail)?></pre></section>
<?php foreach ($runs as $r): ?><section class="card"><h2><?=h($r['state_code'])?> · <?=h($r['status'])?></h2><div class="row"><strong>Type</strong><span><?=h($r['detected_source_type'])?></span></div><div class="row"><strong>Parsed</strong><span><?=h($r['records_parsed'])?></span></div><div class="row"><strong>Fields</strong><span><?=h($r['fields_observed'])?></span></div><div class="row"><strong>Est. MB</strong><span><?=h($r['estimated_full_storage_mb'])?></span></div><?php if($r['error_message']) echo '<p>'.h($r['error_message']).'</p>'; ?><p style="overflow-wrap:anywhere"><?=h($r['source_url'])?></p></section><?php endforeach; ?>
<?php footer_page(); ?>
