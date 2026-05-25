<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/sample_schema.php';
require_login();
require_csrf();
ensure_sample_tables();
$limit = max(1, min(25, (int)($_POST['limit'] ?? 10)));
$created = 0;
$rows = rows("SELECT c.*, e.acquisition_route, e.detected_source_type, e.recommended_parser
    FROM source_acquisition_evaluations e
    JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id
    JOIN source_candidates c ON c.id=e.source_candidate_id
    LEFT JOIN source_sample_runs sr ON sr.source_candidate_id=c.id
    WHERE e.acquisition_route='sample_first' AND sr.id IS NULL
    ORDER BY e.total_risk_weight ASC, c.state_code ASC
    LIMIT $limit");
foreach ($rows as $c) {
    $sourceId = ensure_sample_runtime_source($c);
    exec_sql("INSERT INTO source_sample_runs (source_candidate_id,source_id,state_code,source_url,detected_source_type,parser_key,status,queued_at) VALUES (?,?,?,?,?,?, 'queued', NOW())", [$c['id'],$sourceId,$c['state_code'],$c['candidate_url'],$c['source_type'],$c['recommended_parser'] ?: acquisition_parser_for($c['source_type'])]);
    exec_sql("UPDATE sources SET sample_status='queued', sample_last_run_id=LAST_INSERT_ID(), updated_at=NOW() WHERE id=?", [$sourceId]);
    $created++;
}
header('Location: '.admin_url('pages/sample-runs.php?queued='.$created));
exit;
?>
