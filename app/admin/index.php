<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/layout.php';
require_login();
header_page('Dashboard');
$m = one("SELECT mode_value FROM system_modes WHERE mode_key='system_mode'");
$c = one("SELECT (SELECT COUNT(*) FROM ref_states) states,(SELECT COUNT(*) FROM sources) sources,(SELECT COUNT(*) FROM records) records,(SELECT COUNT(*) FROM rejected_records) rejected");
echo '<section class="card"><div class="kpi">';
foreach (['states'=>'States','sources'=>'Sources','records'=>'Accepted','rejected'=>'Rejected'] as $k=>$label) echo '<div class="metric"><b>'.h($c[$k] ?? 0).'</b><span>'.h($label).'</span></div>';
echo '</div></section>';
echo '<section class="card"><h2>System Mode</h2><p>'.h($m['mode_value'] ?? 'unknown').'</p></section>';
echo '<section class="card"><h2>AI Bridge</h2><p><a href="'.h(admin_url('pages/apply-ai-advice.php')).'">Apply ChatGPT Advice</a></p></section>';
footer_page();
?>
