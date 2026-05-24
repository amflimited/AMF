<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/acquisition_schema.php';
require_login();
require_csrf();
ensure_acquisition_tables();

$limit = max(1, min(10, (int)($_POST['limit'] ?? 5)));
$runId = exec_sql("INSERT INTO source_acquisition_runs (run_type,status,started_at,notes) VALUES ('probe_candidates','running',NOW(),'HTTP probe run')");

$candidates = rows("SELECT c.* FROM source_candidates c
    LEFT JOIN (SELECT source_candidate_id, MAX(id) id FROM source_probe_results GROUP BY source_candidate_id) p ON p.source_candidate_id=c.id
    WHERE p.id IS NULL
    ORDER BY c.state_code ASC, c.id ASC
    LIMIT $limit");

$seen = 0;
$success = 0;
$blocked = 0;
$failed = 0;
$inconclusive = 0;

foreach ($candidates as $c) {
    $seen++;
    $p = probe_candidate_source($c);
    exec_sql("INSERT INTO source_probe_results (
        source_candidate_id,state_code,probe_url,http_status,content_type,bytes_read,
        has_captcha,has_login,has_bot_block,has_javascript_required,has_html_table,has_search_form,
        discovered_csv_links,discovered_xlsx_links,discovered_txt_links,discovered_zip_links,discovered_pdf_links,discovered_api_links,
        detected_source_type,probe_status,notes,created_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())", [
        $c['id'],$c['state_code'],$c['candidate_url'],$p['http_status'],$p['content_type'],$p['bytes_read'],
        $p['has_captcha'],$p['has_login'],$p['has_bot_block'],$p['has_javascript_required'],$p['has_html_table'],$p['has_search_form'],
        $p['discovered_csv_links'],$p['discovered_xlsx_links'],$p['discovered_txt_links'],$p['discovered_zip_links'],$p['discovered_pdf_links'],$p['discovered_api_links'],
        $p['detected_source_type'],$p['probe_status'],$p['notes']
    ]);

    $e = evaluate_candidate_acquisition($c);
    exec_sql("INSERT INTO source_acquisition_evaluations (
        source_candidate_id,state_code,detected_source_type,acquisition_route,namecheap_compatible,recommended_parser,
        parser_risk_weight,compliance_risk_weight,storage_risk_weight,total_risk_weight,decision,decision_reason,created_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())", [
        $c['id'],$c['state_code'],$e['detected_source_type'],$e['acquisition_route'],$e['namecheap_compatible'],$e['recommended_parser'],
        $e['parser_risk_weight'],$e['compliance_risk_weight'],$e['storage_risk_weight'],$e['total_risk_weight'],$e['decision'],$e['decision_reason']
    ]);
    exec_sql("UPDATE source_candidates SET source_type=?, namecheap_compatible=?, recommended_parser=?, checked_at=NOW() WHERE id=?", [$e['detected_source_type'],$e['namecheap_compatible'],$e['recommended_parser'],$c['id']]);

    if ($p['probe_status'] === 'success') $success++;
    elseif ($p['probe_status'] === 'blocked') $blocked++;
    elseif ($p['probe_status'] === 'failed') $failed++;
    else $inconclusive++;
}

$status = 'success';
exec_sql("UPDATE source_acquisition_runs SET status=?, candidates_seen=?, candidates_evaluated=?, sample_first=?, manual_exception=?, external_only=?, blocked=?, finished_at=NOW(), notes=? WHERE id=?", [
    $status,$seen,$seen,$success,$inconclusive,$blocked,$failed,'Probed candidates using HTTP/content inspection.',$runId
]);

header('Location: '.admin_url('pages/source-acquisition.php?probed='.$seen));
exit;
?>
