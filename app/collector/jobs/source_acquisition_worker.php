<?php
// CLI worker: probes/evaluates the next unprobed source candidates.
// Intended for cPanel cron. Safe batch size keeps shared hosting requests short.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../admin/includes/acquisition_schema.php';
ensure_acquisition_tables();

$limit = (int)($argv[1] ?? 3);
if ($limit < 1) $limit = 1;
if ($limit > 10) $limit = 10;

$logPath = dirname(__DIR__) . '/storage/source_acquisition_worker.log';
function worker_log($path, $msg) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$lockPath = dirname(__DIR__) . '/storage/source_acquisition_worker.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    worker_log($logPath, 'SKIP: worker already running');
    exit(0);
}

$runId = exec_sql("INSERT INTO source_acquisition_runs (run_type,status,started_at,notes) VALUES ('probe_candidates','running',NOW(),'CLI worker probe/evaluate run')");
worker_log($logPath, 'START run_id=' . $runId . ' limit=' . $limit);

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
    worker_log($logPath, 'PROBE ' . $c['state_code'] . ' ' . $c['candidate_url']);
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

    worker_log($logPath, 'RESULT ' . $c['state_code'] . ' probe=' . $p['probe_status'] . ' type=' . $p['detected_source_type'] . ' route=' . $e['acquisition_route']);
}

exec_sql("UPDATE source_acquisition_runs SET status='success', candidates_seen=?, candidates_evaluated=?, sample_first=?, manual_exception=?, external_only=?, blocked=?, finished_at=NOW(), notes=? WHERE id=?", [
    $seen,$seen,$success,$inconclusive,$blocked,$failed,'CLI worker completed probe/evaluate batch.',$runId
]);
worker_log($logPath, 'FINISH run_id=' . $runId . ' seen=' . $seen . ' success=' . $success . ' blocked=' . $blocked . ' failed=' . $failed . ' inconclusive=' . $inconclusive);
flock($lock, LOCK_UN);
exit(0);
?>
