<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/form_adapter_schema.php';
require_login();
ensure_form_adapter_tables();
seed_query_profiles();
header_page('Form Adapters');
$root=dirname(__DIR__,3); $log=$root.'/app/collector/storage/form_sample_worker.log'; $tail=file_exists($log)?implode('',array_slice(file($log),-60)):'No form sample worker log yet.';
$summary=one("SELECT (SELECT COUNT(*) FROM source_form_adapters) adapters,(SELECT COUNT(*) FROM source_form_adapters WHERE adapter_status='ready') ready,(SELECT COUNT(*) FROM source_form_adapters WHERE adapter_status='sample_success') success,(SELECT COUNT(*) FROM source_form_adapters WHERE adapter_status IN ('sample_failed','blocked','needs_custom_parser')) problem,(SELECT COUNT(*) FROM source_form_sample_runs WHERE status='queued') queued,(SELECT COUNT(*) FROM source_form_sample_runs WHERE status='success') sample_success");
$rows=rows("SELECT * FROM source_form_adapters ORDER BY FIELD(adapter_status,'sample_success','ready','discovered','needs_custom_parser','blocked','sample_failed'), confidence DESC, state_code ASC LIMIT 100");
?>
<section class="card"><h2>Discover Form Adapters</h2><p>This converts probed search-form sources into submit-capable adapters.</p><form method="post" action="<?=h(admin_url('actions/discover-form-adapters.php'))?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Batch size</label><input name="limit" type="number" min="1" max="10" value="5"><p><button>Discover Next Form Adapters</button></p></form></section>
<section class="card"><h2>Queue Business Query Samples</h2><p>Queues safe business test queries such as LLC/INC against ready adapters.</p><form method="post" action="<?=h(admin_url('actions/queue-form-samples.php'))?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Batch size</label><input name="limit" type="number" min="1" max="25" value="10"><p><button>Queue Form Samples</button></p></form></section>
<section class="card"><h2>Worker Cron</h2><pre>/usr/local/bin/php -q /home/reveqwuv/public_html/app/collector/jobs/form_sample_worker.php 2 &gt;/dev/null 2&gt;&amp;1</pre></section>
<section class="card"><div class="row"><strong>Adapters</strong><span><?=h($summary['adapters']??0)?></span></div><div class="row"><strong>Ready</strong><span><?=h($summary['ready']??0)?></span></div><div class="row"><strong>Sample success</strong><span><?=h($summary['success']??0)?></span></div><div class="row"><strong>Problem</strong><span><?=h($summary['problem']??0)?></span></div><div class="row"><strong>Queued runs</strong><span><?=h($summary['queued']??0)?></span></div></section>
<section class="card"><h2>Worker Log</h2><pre><?=h($tail)?></pre></section>
<?php foreach($rows as $r): ?><section class="card"><h2><?=h($r['state_code'])?> · <?=h($r['adapter_status'])?></h2><div class="row"><strong>Method</strong><span><?=h($r['form_method'])?></span></div><div class="row"><strong>Field</strong><span><?=h($r['search_field_name'])?></span></div><div class="row"><strong>Confidence</strong><span><?=h($r['confidence'])?></span></div><p><?=h($r['notes'])?></p><p style="overflow-wrap:anywhere"><?=h($r['form_action_url'] ?: $r['source_url'])?></p></section><?php endforeach; ?>
<?php footer_page(); ?>
