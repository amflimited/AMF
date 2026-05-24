<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
ensure_candidate_tables();
header_page('Source Candidates');
$rs = rows("SELECT * FROM source_candidates ORDER BY checked_at DESC, id DESC LIMIT 200");
echo '<section class="card"><p><a class="mini" href="'.h(admin_url('pages/add-source-candidate.php')).'">+ Add Candidate</a></p><div class="row"><strong>Total shown</strong><span>'.h(count($rs)).'</span></div></section>';
if (!$rs) echo '<section class="card"><p>No source candidates yet. Add the first MVP candidate.</p></section>';
foreach ($rs as $r) { echo '<section class="card"><h2>'.h($r['state_code']).' · '.h($r['source_type']).'</h2><div class="row"><strong>Status</strong><span>'.h($r['candidate_status']).'</span></div><div class="row"><strong>Compatible</strong><span>'.($r['namecheap_compatible'] ? 'yes' : 'no').'</span></div><div class="row"><strong>URL</strong><span>'.h($r['candidate_url']).'</span></div><p><a class="mini" href="'.h(admin_url('pages/promote-source.php?id='.$r['id'])).'">Review / Promote</a></p></section>'; }
footer_page();
?>
