<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/form_adapter_schema.php';
require_login();
ensure_form_adapter_tables();
header_page('Dashboard');
$m = one("SELECT mode_value FROM system_modes WHERE mode_key='system_mode'");
$c = one("SELECT (SELECT COUNT(*) FROM ref_states) states,(SELECT COUNT(*) FROM sources) sources,(SELECT COUNT(*) FROM records) records,(SELECT COUNT(*) FROM rejected_records) rejected,(SELECT COUNT(*) FROM source_candidates) candidates,(SELECT COUNT(*) FROM source_acquisition_evaluations) evaluations,(SELECT COUNT(*) FROM source_sample_runs) samples,(SELECT COUNT(*) FROM source_form_adapters) adapters");
echo '<section class="card"><div class="kpi">';
foreach (['states'=>'States','candidates'=>'Candidates','evaluations'=>'Auto Evals','samples'=>'Samples','adapters'=>'Form Adapters','sources'=>'Runtime Sources','records'=>'Accepted','rejected'=>'Rejected'] as $k=>$label) echo '<div class="metric"><b>'.h($c[$k] ?? 0).'</b><span>'.h($label).'</span></div>';
echo '</div></section>';
echo '<section class="card"><h2>System Health</h2><p>This page verifies that the expected architecture is actually present on the live server.</p><p><a href="'.h(admin_url('pages/system-health.php')).'">Open System Health</a></p></section>';
echo '<section class="card"><h2>System Mode</h2><p>'.h($m['mode_value'] ?? 'unknown').'</p></section>';
echo '<section class="card"><h2>Source Acquisition</h2><p><a href="'.h(admin_url('pages/source-acquisition.php')).'">Open Automated Acquisition</a></p></section>';
echo '<section class="card"><h2>Form Adapters</h2><p><a href="'.h(admin_url('pages/form-adapters.php')).'">Open Form Adapters</a></p></section>';
echo '<section class="card"><h2>Sample Pipeline</h2><p><a href="'.h(admin_url('pages/sample-runs.php')).'">Open Sample Runs</a></p></section>';
echo '<section class="card"><h2>Discovery Seeder</h2><p><a href="'.h(admin_url('pages/discovery.php')).'">Seed Official State Candidates</a></p></section>';
echo '<section class="card"><h2>Deployment</h2><p><a href="/webhook_setup.php">Open Webhook Setup</a></p><p><a href="'.h(admin_url('pages/deploy-status.php')).'">Open Deploy Status</a></p></section>';
echo '<section class="card"><h2>AI Bridge</h2><p><a href="'.h(admin_url('pages/apply-ai-advice.php')).'">Apply ChatGPT Advice</a></p></section>';
footer_page();
?>
