<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_login();
header_page('Runs');
$rs=rows("SELECT id,source_id,state_code,status,records_seen,records_accepted,records_rejected,started_at FROM ingestion_runs ORDER BY started_at DESC LIMIT 100");
if(!$rs)echo '<section class="card"><p>No runs yet.</p></section>';
foreach($rs as $r){echo '<section class="card">';foreach($r as $k=>$v)echo '<div class="row"><strong>'.h($k).'</strong><span>'.h($v).'</span></div>';echo '</section>';}
footer_page();
?>
