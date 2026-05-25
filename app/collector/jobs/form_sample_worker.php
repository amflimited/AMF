<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../admin/includes/form_adapter_schema.php';
ensure_form_adapter_tables();
$limit=(int)($argv[1]??2); if($limit<1)$limit=1; if($limit>5)$limit=5;
$logPath = dirname(__DIR__) . '/storage/form_sample_worker.log';
function form_log($p,$m){$d=dirname($p);if(!is_dir($d))@mkdir($d,0755,true);@file_put_contents($p,'['.date('Y-m-d H:i:s').'] '.$m.PHP_EOL,FILE_APPEND|LOCK_EX);} 
$lock=fopen(dirname(__DIR__).'/storage/form_sample_worker.lock','c'); if(!$lock || !flock($lock, LOCK_EX|LOCK_NB)){form_log($logPath,'SKIP already running');exit(0);} 
$runs=rows("SELECT r.*, a.form_action_url, a.form_method, a.search_field_name, a.hidden_fields_json FROM source_form_sample_runs r JOIN source_form_adapters a ON a.id=r.form_adapter_id WHERE r.status='queued' ORDER BY r.queued_at ASC LIMIT $limit");
foreach($runs as $r){
    exec_sql("UPDATE source_form_sample_runs SET status='running', started_at=NOW() WHERE id=?",[$r['id']]);
    try{
        $req=build_form_request($r,$r['query_value']);
        $resp=fetch_form_request($req);
        if($resp['status']>=400 || $resp['error']) throw new Exception('HTTP '.$resp['status'].' '.$resp['error']);
        $bodyLower=strtolower(substr($resp['body'],0,200000));
        if(preg_match('/captcha|recaptcha|access denied|bot detected|unusual traffic|rate limit/', $bodyLower)){
            exec_sql("UPDATE source_form_sample_runs SET status='blocked', request_url=?, http_status=?, response_bytes=?, error_message='Blocking signal in search response', finished_at=NOW() WHERE id=?",[$req['url'],$resp['status'],strlen($resp['body']),$r['id']]);
            exec_sql("UPDATE source_form_adapters SET adapter_status='blocked', updated_at=NOW() WHERE id=?",[$r['form_adapter_id']]);
            continue;
        }
        $records=parse_html_table_sample($resp['body'],50);
        if(!$records) $records=parse_search_form_sample($resp['body']);
        $parsed=count($records);
        if($parsed===0){
            exec_sql("UPDATE source_form_sample_runs SET status='no_results', request_url=?, http_status=?, response_bytes=?, error_message='No parseable result rows from query', finished_at=NOW() WHERE id=?",[$req['url'],$resp['status'],strlen($resp['body']),$r['id']]);
            continue;
        }
        $fields=observe_sample_fields(null,$r['source_id'],$r['source_candidate_id'],$r['state_code'],$records);
        exec_sql("UPDATE source_form_sample_runs SET status='success', request_url=?, http_status=?, response_bytes=?, parsed_rows=?, fields_observed=?, sample_json=?, finished_at=NOW() WHERE id=?",[$req['url'],$resp['status'],strlen($resp['body']),$parsed,$fields,json_encode(array_slice($records,0,5)),$r['id']]);
        exec_sql("UPDATE source_form_adapters SET adapter_status='sample_success', updated_at=NOW() WHERE id=?",[$r['form_adapter_id']]);
        form_log($logPath,'SUCCESS state='.$r['state_code'].' query='.$r['query_value'].' rows='.$parsed);
    }catch(Throwable $e){
        exec_sql("UPDATE source_form_sample_runs SET status='failed', error_message=?, finished_at=NOW() WHERE id=?",[$e->getMessage(),$r['id']]);
        form_log($logPath,'FAILED state='.$r['state_code'].' '.$e->getMessage());
    }
}
flock($lock,LOCK_UN);
exit(0);
?>
