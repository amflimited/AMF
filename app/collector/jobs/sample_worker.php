<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../admin/includes/sample_schema.php';
ensure_sample_tables();
$limit = (int)($argv[1] ?? 2); if ($limit < 1) $limit = 1; if ($limit > 5) $limit = 5;
$logPath = dirname(__DIR__) . '/storage/sample_worker.log';
function sample_log($p,$m){$d=dirname($p);if(!is_dir($d))@mkdir($d,0755,true);@file_put_contents($p,'['.date('Y-m-d H:i:s').'] '.$m.PHP_EOL,FILE_APPEND|LOCK_EX);} 
$lockPath = dirname(__DIR__) . '/storage/sample_worker.lock';
$lock = fopen($lockPath,'c'); if(!$lock || !flock($lock, LOCK_EX|LOCK_NB)){ sample_log($logPath,'SKIP already running'); exit(0); }
$runs = rows("SELECT * FROM source_sample_runs WHERE status='queued' ORDER BY queued_at ASC LIMIT $limit");
sample_log($logPath, 'START queued=' . count($runs));
foreach ($runs as $run) {
    exec_sql("UPDATE source_sample_runs SET status='running', started_at=NOW() WHERE id=?", [$run['id']]);
    if ($run['source_id']) exec_sql("UPDATE sources SET sample_status='running', updated_at=NOW() WHERE id=?", [$run['source_id']]);
    try {
        $payload = fetch_sample_payload($run['source_url']);
        if ($payload['status'] >= 400 || $payload['error']) throw new Exception('Fetch failed: HTTP '.$payload['status'].' '.$payload['error']);
        $records = parse_sample_records($run['detected_source_type'], $payload['body'], $payload['content_type']);
        $parsed = count($records);
        if ($parsed === 0) throw new Exception('No parseable sample records found for parser '.$run['parser_key']);
        $rawBytes = 0; $compressedBytes = 0;
        foreach ($records as $rec) { $json=json_encode($rec); $rawBytes += strlen($json); $compressedBytes += strlen(gzencode($json)); }
        $avgRaw = (int)round($rawBytes / max(1,$parsed));
        $avgCompressed = (int)round($compressedBytes / max(1,$parsed));
        $fields = observe_sample_fields($run['id'], $run['source_id'], $run['source_candidate_id'], $run['state_code'], $records);
        $estimatedRecords = null;
        $estimatedMb = round((($avgRaw + $avgCompressed + 600) * max(1000, $parsed * 100)) / 1048576, 2);
        $budgetStatus = $estimatedMb < 100 ? 'safe' : ($estimatedMb < 500 ? 'watch' : 'requires_sampling');
        exec_sql("INSERT INTO source_storage_budgets (source_id,source_candidate_id,state_code,estimated_total_records,avg_raw_envelope_bytes,avg_compressed_envelope_bytes,estimated_total_mb,budget_status,checked_at) VALUES (?,?,?,?,?,?,?,?,NOW())", [$run['source_id'],$run['source_candidate_id'],$run['state_code'],$estimatedRecords,$avgRaw,$avgCompressed,$estimatedMb,$budgetStatus]);
        exec_sql("UPDATE source_sample_runs SET status='success', records_seen=?, records_parsed=?, fields_observed=?, avg_raw_bytes=?, avg_compressed_bytes=?, estimated_full_records=?, estimated_full_storage_mb=?, sample_json=?, finished_at=NOW() WHERE id=?", [$parsed,$parsed,$fields,$avgRaw,$avgCompressed,$estimatedRecords,$estimatedMb,json_encode(array_slice($records,0,5)),$run['id']]);
        if ($run['source_id']) exec_sql("UPDATE sources SET sample_status='sample_complete', sample_last_run_id=?, sample_completed_at=NOW(), updated_at=NOW() WHERE id=?", [$run['id'],$run['source_id']]);
        sample_log($logPath, 'SUCCESS run='.$run['id'].' state='.$run['state_code'].' parsed='.$parsed.' fields='.$fields);
    } catch (Throwable $e) {
        $status = (strpos($e->getMessage(), 'No parseable') !== false) ? 'needs_parser_config' : 'failed';
        exec_sql("UPDATE source_sample_runs SET status=?, error_message=?, finished_at=NOW() WHERE id=?", [$status,$e->getMessage(),$run['id']]);
        if ($run['source_id']) exec_sql("UPDATE sources SET sample_status=?, updated_at=NOW() WHERE id=?", [$status === 'needs_parser_config' ? 'needs_parser_config' : 'sample_failed',$run['source_id']]);
        sample_log($logPath, 'FAILED run='.$run['id'].' state='.$run['state_code'].' error='.$e->getMessage());
    }
}
flock($lock, LOCK_UN);
exit(0);
?>
