<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
ensure_candidate_tables();
header_page('Source Candidates');

$status = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($status !== '') {
    $where = 'WHERE candidate_status=?';
    $params[] = $status;
}
$rs = rows("SELECT * FROM source_candidates $where ORDER BY checked_at DESC, id DESC LIMIT 200", $params);

echo '<section class="card"><p><a class="mini" href="'.h(admin_url('pages/add-source-candidate.php')).'">+ Add Candidate</a></p>';
echo '<div class="row"><strong>Total shown</strong><span>'.h(count($rs)).'</span></div></section>';

if (!$rs) echo '<section class="card"><p>No source candidates yet. Add the first MVP candidate.</p></section>';
foreach ($rs as $r) {
    echo '<section class="card">';
    echo '<h2>'.h($r['state_code']).' · '.h($r['source_type']).'</h2>';
    echo '<div class="row"><strong>Status</strong><span>'.h($r['candidate_status']).'</span></div>';
    echo '<div class="row"><strong>Compatible</strong><span>'.($r['namecheap_compatible'] ? 'yes' : 'no').'</span></div>';
    echo '<div class="row"><strong>Parser</strong><span>'.h($r['recommended_parser'] ?: 'not set').'</span></div>';
    echo '<div class="row"><strong>URL</strong><span>'.h($r['candidate_url']).'</span></div>';
    if ($r['notes']) echo '<p>'.nl2br(h($r['notes'])).'</p>';
    echo '<p><a class="mini" href="'.h(admin_url('pages/promote-source.php?id='.$r['id'])).'">Review / Promote</a></p>';
    echo '</section>';
}
footer_page();
?>
