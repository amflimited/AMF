<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/acquisition_schema.php';
require_login();
ensure_acquisition_tables();
header_page('Dashboard');
$m = one("SELECT mode_value FROM system_modes WHERE mode_key='system_mode'");
$c = one("SELECT (SELECT COUNT(*) FROM ref_states) states,(SELECT COUNT(*) FROM sources) sources,(SELECT COUNT(*) FROM records) records,(SELECT COUNT(*) FROM rejected_records) rejected,(SELECT COUNT(*) FROM source_candidates) candidates,(SELECT COUNT(*) FROM source_acquisition_evaluations) evaluations");
echo '<section class="card"><div class="kpi">';
foreach (['states'=>'States','candidates'=>'Candidates','evaluations'=>'Auto Evals','sources'=>'Runtime Sources','records'=>'Accepted','rejected'=>'Rejected'] as $k=>$label) echo '<div class="metric"><b>'.h($c[$k] ?? 0).'</b><span>'.h($label).'</span></div>';
echo '</div></section>';
echo '<section class="card"><h2>System Mode</h2><p>'.h($m['mode_value'] ?? 'unknown').'</p></section>';
echo '<section class="card"><h2>Source Acquisition</h2><p>The system should auto-seed, auto-evaluate, auto-route, and only surface exceptions.</p><p><a href="'.h(admin_url('pages/source-acquisition.php')).'">Open Automated Acquisition</a></p></section>';
echo '<section class="card"><h2>Discovery Seeder</h2><p><a href="'.h(admin_url('pages/discovery.php')).'">Seed Official State Candidates</a></p></section>';
echo '<section class="card"><h2>Deployment</h2><p><a href="/webhook_setup.php">Open Webhook Setup</a></p><p><a href="'.h(admin_url('pages/deploy-status.php')).'">Open Deploy Status</a></p></section>';
echo '<section class="card"><h2>AI Bridge</h2><p><a href="'.h(admin_url('pages/apply-ai-advice.php')).'">Apply ChatGPT Advice</a></p></section>';
footer_page();
?>
