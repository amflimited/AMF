<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_login();
header_page('States');
$rs=rows("SELECT state_code,state_name,ingestion_wave,downstream_group_flag,state_readiness_status FROM ref_states ORDER BY state_code");
foreach($rs as $r){echo '<section class="card">';foreach($r as $k=>$v)echo '<div class="row"><strong>'.h($k).'</strong><span>'.h($v).'</span></div>';echo '</section>';}
footer_page();
?>
