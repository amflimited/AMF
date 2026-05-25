<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/form_adapter_schema.php';
require_login();
require_csrf();
ensure_form_adapter_tables();
seed_query_profiles();
$limit = max(1, min(25, (int)($_POST['limit'] ?? 10)));
$adapters = rows("SELECT * FROM source_form_adapters WHERE adapter_status='ready' ORDER BY confidence DESC, state_code ASC LIMIT $limit");
$profile = one("SELECT * FROM source_query_profiles WHERE is_active=1 ORDER BY priority ASC LIMIT 1");
$queued=0;
foreach($adapters as $a){
    $exists = one("SELECT id FROM source_form_sample_runs WHERE form_adapter_id=? AND query_profile_id=? LIMIT 1", [$a['id'],$profile['id']]);
    if($exists) continue;
    exec_sql("INSERT INTO source_form_sample_runs (form_adapter_id,source_candidate_id,source_id,state_code,query_profile_id,query_value,status,queued_at) VALUES (?,?,?,?,?,?, 'queued', NOW())", [$a['id'],$a['source_candidate_id'],$a['source_id'],$a['state_code'],$profile['id'],$profile['query_value']]);
    $queued++;
}
header('Location: '.admin_url('pages/form-adapters.php?queued='.$queued));
exit;
?>
