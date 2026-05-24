<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/acquisition_schema.php';
require_login();
require_csrf();
ensure_acquisition_tables();
$runId = exec_sql("INSERT INTO source_acquisition_runs (run_type,status,started_at) VALUES ('evaluate_candidates','running',NOW())");
$candidates = rows('SELECT * FROM source_candidates ORDER BY state_code ASC, id ASC');
$stats = ['seen'=>0,'evaluated'=>0,'sample_first'=>0,'manual_exception'=>0,'external_only'=>0,'blocked'=>0,'auto_collect'=>0];
foreach ($candidates as $c) {
    $stats['seen']++;
    $e = evaluate_candidate_acquisition($c);
    exec_sql("INSERT INTO source_acquisition_evaluations (
        source_candidate_id,state_code,detected_source_type,acquisition_route,namecheap_compatible,recommended_parser,
        parser_risk_weight,compliance_risk_weight,storage_risk_weight,total_risk_weight,decision,decision_reason,created_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())", [
        $c['id'],$c['state_code'],$e['detected_source_type'],$e['acquisition_route'],$e['namecheap_compatible'],$e['recommended_parser'],
        $e['parser_risk_weight'],$e['compliance_risk_weight'],$e['storage_risk_weight'],$e['total_risk_weight'],$e['decision'],$e['decision_reason']
    ]);
    exec_sql("UPDATE source_candidates SET source_type=?, namecheap_compatible=?, recommended_parser=?, checked_at=NOW() WHERE id=?", [$e['detected_source_type'],$e['namecheap_compatible'],$e['recommended_parser'],$c['id']]);
    $stats['evaluated']++;
    if (isset($stats[$e['acquisition_route']])) $stats[$e['acquisition_route']]++;
}
exec_sql("UPDATE source_acquisition_runs SET status='success', candidates_seen=?, candidates_evaluated=?, auto_collect=?, sample_first=?, manual_exception=?, external_only=?, blocked=?, finished_at=NOW(), notes='Automated source-candidate evaluation completed.' WHERE id=?", [$stats['seen'],$stats['evaluated'],$stats['auto_collect'],$stats['sample_first'],$stats['manual_exception'],$stats['external_only'],$stats['blocked'],$runId]);
header('Location: '.admin_url('pages/source-acquisition.php'));
exit;
?>
