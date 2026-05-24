<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_login();
header_page('Sources');
$rs=rows("SELECT id,state_code,source_name,source_type,source_status,full_import_approved FROM sources ORDER BY state_code,priority LIMIT 100");
if(!$rs)echo '<section class="card"><p>No sources yet.</p></section>';
foreach($rs as $r){echo '<section class="card">';foreach($r as $k=>$v)echo '<div class="row"><strong>'.h($k).'</strong><span>'.h($v).'</span></div>';echo '</section>';}
footer_page();
?>
