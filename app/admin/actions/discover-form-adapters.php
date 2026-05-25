<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/form_adapter_schema.php';
require_login();
require_csrf();
ensure_form_adapter_tables();
seed_query_profiles();
$limit = max(1, min(10, (int)($_POST['limit'] ?? 5)));
$rows = rows("SELECT c.* FROM source_candidates c
 JOIN source_acquisition_evaluations e ON e.source_candidate_id=c.id
 JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON x.id=e.id
 LEFT JOIN source_form_adapters a ON a.source_candidate_id=c.id
 WHERE e.acquisition_route='sample_first' AND e.detected_source_type='search_form' AND a.id IS NULL
 ORDER BY c.state_code ASC LIMIT $limit");
$created=0; $failed=0;
foreach($rows as $c){
    $d = discover_search_form_adapter($c);
    if(($d['status'] ?? '') === 'ready'){
        exec_sql("INSERT INTO source_form_adapters (source_candidate_id,source_id,state_code,source_url,form_action_url,form_method,search_field_name,hidden_fields_json,adapter_status,confidence,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?, 'ready',?,?,NOW(),NOW())",
            [$c['id'],null,$c['state_code'],$c['candidate_url'],$d['form_action_url'],$d['form_method'],$d['search_field_name'],$d['hidden_fields_json'],$d['confidence'],$d['notes']]);
        $created++;
    } else {
        exec_sql("INSERT INTO source_form_adapters (source_candidate_id,state_code,source_url,adapter_status,confidence,notes,created_at,updated_at) VALUES (?,?,?, ?,0.000,?,NOW(),NOW())",
            [$c['id'],$c['state_code'],$c['candidate_url'],$d['status'] ?? 'needs_custom_parser',$d['error'] ?? 'Adapter discovery failed']);
        $failed++;
    }
}
header('Location: '.admin_url('pages/form-adapters.php?created='.$created.'&failed='.$failed));
exit;
?>
