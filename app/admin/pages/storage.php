<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_login();
header_page('Storage');
$rs=rows("SELECT id,database_size_mb,temp_storage_mb,debug_storage_mb,inode_count,system_mode_after_check,created_at FROM storage_snapshots ORDER BY created_at DESC LIMIT 50");
if(!$rs)echo '<section class="card"><p>No storage snapshots yet.</p></section>';
foreach($rs as $r){echo '<section class="card">';foreach($r as $k=>$v)echo '<div class="row"><strong>'.h($k).'</strong><span>'.h($v).'</span></div>';echo '</section>';}
footer_page();
?>
