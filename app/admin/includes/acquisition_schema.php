<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/candidate_schema.php';

function try_sql($sql) { try { db()->exec($sql); } catch (Throwable $e) { } }

function ensure_acquisition_tables() {
    ensure_candidate_tables();
    db()->exec("CREATE TABLE IF NOT EXISTS source_acquisition_evaluations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NOT NULL,
        state_code CHAR(2) NOT NULL,
        detected_source_type ENUM('csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator','unknown','unsupported') NOT NULL DEFAULT 'unknown',
        acquisition_route ENUM('needs_probe','auto_collect','sample_first','manual_exception','external_only','blocked') NOT NULL DEFAULT 'needs_probe',
        namecheap_compatible BOOLEAN NOT NULL DEFAULT FALSE,
        recommended_parser VARCHAR(128) NULL,
        parser_risk_weight INT UNSIGNED NOT NULL DEFAULT 30,
        compliance_risk_weight INT UNSIGNED NOT NULL DEFAULT 20,
        storage_risk_weight INT UNSIGNED NOT NULL DEFAULT 20,
        total_risk_weight INT UNSIGNED NOT NULL DEFAULT 70,
        decision ENUM('needs_probe','auto_promote_candidate','needs_sample','needs_exception_review','external_only','blocked') NOT NULL DEFAULT 'needs_probe',
        decision_reason TEXT NULL,
        evaluator_version VARCHAR(64) NOT NULL DEFAULT 'source_acquisition_v3',
        created_at DATETIME NOT NULL,
        KEY idx_candidate (source_candidate_id),
        KEY idx_state_code (state_code),
        KEY idx_decision (decision),
        KEY idx_route (acquisition_route)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try_sql("ALTER TABLE source_acquisition_evaluations MODIFY acquisition_route ENUM('needs_probe','auto_collect','sample_first','manual_exception','external_only','blocked') NOT NULL DEFAULT 'needs_probe'");
    try_sql("ALTER TABLE source_acquisition_evaluations MODIFY decision ENUM('needs_probe','auto_promote_candidate','needs_sample','needs_exception_review','external_only','blocked') NOT NULL DEFAULT 'needs_probe'");

    db()->exec("CREATE TABLE IF NOT EXISTS source_probe_results (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NOT NULL,
        state_code CHAR(2) NOT NULL,
        probe_url TEXT NOT NULL,
        http_status INT NULL,
        content_type VARCHAR(255) NULL,
        bytes_read INT UNSIGNED NOT NULL DEFAULT 0,
        has_captcha BOOLEAN NOT NULL DEFAULT FALSE,
        has_login BOOLEAN NOT NULL DEFAULT FALSE,
        has_bot_block BOOLEAN NOT NULL DEFAULT FALSE,
        has_javascript_required BOOLEAN NOT NULL DEFAULT FALSE,
        has_html_table BOOLEAN NOT NULL DEFAULT FALSE,
        has_search_form BOOLEAN NOT NULL DEFAULT FALSE,
        discovered_csv_links INT UNSIGNED NOT NULL DEFAULT 0,
        discovered_xlsx_links INT UNSIGNED NOT NULL DEFAULT 0,
        discovered_txt_links INT UNSIGNED NOT NULL DEFAULT 0,
        discovered_zip_links INT UNSIGNED NOT NULL DEFAULT 0,
        discovered_pdf_links INT UNSIGNED NOT NULL DEFAULT 0,
        discovered_api_links INT UNSIGNED NOT NULL DEFAULT 0,
        detected_source_type ENUM('csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator','unknown','unsupported') NOT NULL DEFAULT 'unknown',
        probe_status ENUM('success','blocked','failed','inconclusive') NOT NULL DEFAULT 'inconclusive',
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_candidate (source_candidate_id),
        KEY idx_state_code (state_code),
        KEY idx_probe_status (probe_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_acquisition_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_type ENUM('probe_candidates','evaluate_candidates','auto_promote_safe','seed_and_evaluate') NOT NULL,
        status ENUM('running','success','partial_success','failed') NOT NULL DEFAULT 'running',
        candidates_seen INT UNSIGNED NOT NULL DEFAULT 0,
        candidates_evaluated INT UNSIGNED NOT NULL DEFAULT 0,
        auto_collect INT UNSIGNED NOT NULL DEFAULT 0,
        sample_first INT UNSIGNED NOT NULL DEFAULT 0,
        manual_exception INT UNSIGNED NOT NULL DEFAULT 0,
        external_only INT UNSIGNED NOT NULL DEFAULT 0,
        blocked INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        notes TEXT NULL,
        KEY idx_started (started_at),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try_sql("ALTER TABLE source_acquisition_runs MODIFY run_type ENUM('probe_candidates','evaluate_candidates','auto_promote_safe','seed_and_evaluate') NOT NULL");
}

function acquisition_parser_risk($type) {
    return ['csv'=>5,'api'=>5,'xlsx'=>8,'txt'=>8,'zip'=>8,'html_table'=>12,'search_form'=>18,'pdf'=>35,'aggregator'=>25,'unknown'=>30,'unsupported'=>60][$type] ?? 30;
}
function acquisition_parser_for($type) {
    return ['csv'=>'csv','xlsx'=>'xlsx','txt'=>'txt','zip'=>'zip','pdf'=>'pdf_text','html_table'=>'html_table','search_form'=>'simple_form','api'=>'api_json','aggregator'=>'csv'][$type] ?? null;
}
function detect_source_type_from_url($url, $currentType='unknown') {
    $u = strtolower(trim($url));
    if (preg_match('/\.csv($|\?)/', $u)) return 'csv';
    if (preg_match('/\.xlsx?($|\?)/', $u)) return 'xlsx';
    if (preg_match('/\.txt($|\?)/', $u)) return 'txt';
    if (preg_match('/\.zip($|\?)/', $u)) return 'zip';
    if (preg_match('/\.pdf($|\?)/', $u)) return 'pdf';
    if (strpos($u, 'api') !== false || strpos($u, 'json') !== false) return 'api';
    if ($currentType && $currentType !== 'unknown') return $currentType;
    return 'unknown';
}

function fetch_probe_page($url) {
    $body = '';
    $status = 0;
    $contentType = '';
    $error = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>4, CURLOPT_TIMEOUT=>15, CURLOPT_USERAGENT=>'RevenuePack Source Probe/0.1', CURLOPT_RANGE=>'0-200000']);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http'=>['timeout'=>15,'header'=>"User-Agent: RevenuePack Source Probe/0.1\r\n"]]);
        $body = @file_get_contents($url, false, $ctx, 0, 200000);
        $headers = $http_response_header ?? [];
        foreach ($headers as $h) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) $status = (int)$m[1];
            if (stripos($h, 'Content-Type:') === 0) $contentType = trim(substr($h, 13));
        }
        if ($body === false) $error = 'file_get_contents failed';
    }
    return ['body'=>(string)$body, 'status'=>$status, 'content_type'=>$contentType, 'error'=>$error];
}

function probe_candidate_source($candidate) {
    $url = $candidate['candidate_url'];
    $f = fetch_probe_page($url);
    $body = strtolower(substr($f['body'], 0, 200000));
    $ct = strtolower($f['content_type']);
    $status = (int)$f['status'];
    $directType = detect_source_type_from_url($url, $candidate['source_type'] ?? 'unknown');
    $counts = [
        'csv'=>preg_match_all('/href=["\'][^"\']+\.csv(\?[^"\']*)?["\']/i', $f['body']),
        'xlsx'=>preg_match_all('/href=["\'][^"\']+\.xlsx?(\?[^"\']*)?["\']/i', $f['body']),
        'txt'=>preg_match_all('/href=["\'][^"\']+\.txt(\?[^"\']*)?["\']/i', $f['body']),
        'zip'=>preg_match_all('/href=["\'][^"\']+\.zip(\?[^"\']*)?["\']/i', $f['body']),
        'pdf'=>preg_match_all('/href=["\'][^"\']+\.pdf(\?[^"\']*)?["\']/i', $f['body']),
        'api'=>preg_match_all('/(api|json|graphql)/i', $f['body']),
    ];
    $hasCaptcha = (bool)preg_match('/captcha|recaptcha|hcaptcha|cloudflare turnstile/', $body);
    $hasLogin = (bool)preg_match('/\blog in\b|\blogin\b|sign in|required account|create account/', $body);
    $hasBot = (bool)preg_match('/access denied|forbidden|bot detected|automated access|unusual traffic|rate limit|temporarily blocked/', $body) || in_array($status, [401,403,429], true);
    $hasJs = (bool)preg_match('/javascript is required|enable javascript|app-root|__next_data__|nuxt|reactroot/', $body);
    $hasTable = (strpos($body, '<table') !== false);
    $hasForm = (strpos($body, '<form') !== false || preg_match('/<input|<select|name=["\']search|name=["\']last/i', $f['body']));

    $detected = $directType;
    if ($counts['csv'] > 0 || strpos($ct, 'csv') !== false) $detected = 'csv';
    elseif ($counts['xlsx'] > 0) $detected = 'xlsx';
    elseif ($counts['zip'] > 0) $detected = 'zip';
    elseif ($counts['txt'] > 0) $detected = 'txt';
    elseif ($counts['api'] > 0) $detected = 'api';
    elseif ($hasTable) $detected = 'html_table';
    elseif ($hasForm) $detected = 'search_form';
    elseif ($counts['pdf'] > 0 || strpos($ct, 'pdf') !== false) $detected = 'pdf';

    $probeStatus = 'inconclusive';
    $notes = [];
    if ($f['error'] || $status >= 400) { $probeStatus = 'failed'; $notes[] = 'HTTP/error condition observed.'; }
    if ($hasCaptcha || $hasLogin || $hasBot) { $probeStatus = 'blocked'; $notes[] = 'Blocking signal observed.'; }
    elseif ($detected !== 'unknown') { $probeStatus = 'success'; $notes[] = 'Collectable structure detected.'; }
    if ($hasJs && !$hasForm && !$hasTable && array_sum($counts) === 0) { $probeStatus = 'blocked'; $notes[] = 'Likely JavaScript-only page without server-readable structure.'; }

    return ['http_status'=>$status, 'content_type'=>$f['content_type'], 'bytes_read'=>strlen($f['body']), 'has_captcha'=>$hasCaptcha?1:0, 'has_login'=>$hasLogin?1:0, 'has_bot_block'=>$hasBot?1:0, 'has_javascript_required'=>$hasJs?1:0, 'has_html_table'=>$hasTable?1:0, 'has_search_form'=>$hasForm?1:0, 'discovered_csv_links'=>$counts['csv'], 'discovered_xlsx_links'=>$counts['xlsx'], 'discovered_txt_links'=>$counts['txt'], 'discovered_zip_links'=>$counts['zip'], 'discovered_pdf_links'=>$counts['pdf'], 'discovered_api_links'=>$counts['api'], 'detected_source_type'=>$detected, 'probe_status'=>$probeStatus, 'notes'=>implode(' ', $notes)];
}

function latest_probe_for_candidate($candidateId) {
    return one('SELECT * FROM source_probe_results WHERE source_candidate_id=? ORDER BY id DESC LIMIT 1', [$candidateId]);
}

function evaluate_candidate_acquisition($candidate) {
    $probe = latest_probe_for_candidate($candidate['id']);
    if (!$probe) {
        return ['detected_source_type'=>'unknown','acquisition_route'=>'needs_probe','namecheap_compatible'=>0,'recommended_parser'=>null,'parser_risk_weight'=>30,'compliance_risk_weight'=>20,'storage_risk_weight'=>20,'total_risk_weight'=>70,'decision'=>'needs_probe','decision_reason'=>'Candidate has not been probed yet. No pass/fail decision can be made without observed page evidence.'];
    }
    $type = $probe['detected_source_type'];
    $parserRisk = acquisition_parser_risk($type);
    $complianceRisk = 10;
    $storageRisk = 20;
    $route = 'sample_first';
    $decision = 'needs_sample';
    $compatible = true;
    $reason = 'Probe observed collectable structure; route to controlled sample.';

    if ($probe['probe_status'] === 'blocked' || $probe['has_captcha'] || $probe['has_login'] || $probe['has_bot_block'] || $probe['has_javascript_required']) {
        $route = 'external_only'; $decision = 'external_only'; $compatible = false; $complianceRisk = 40; $storageRisk = 60; $reason = 'Probe observed blocking, login, CAPTCHA, bot protection, or JavaScript-only behavior.';
    } elseif ($probe['probe_status'] === 'failed' || $type === 'unknown') {
        $route = 'manual_exception'; $decision = 'needs_exception_review'; $compatible = false; $complianceRisk = 20; $storageRisk = 20; $reason = 'Probe failed or did not detect a collectable structure. Exception queue required.';
    } elseif ($type === 'pdf') {
        $storageRisk = 40; $reason = 'Probe detected PDF source; route to controlled low-volume sample.';
    }

    $total = $parserRisk + $complianceRisk + $storageRisk;
    return ['detected_source_type'=>$type,'acquisition_route'=>$route,'namecheap_compatible'=>$compatible?1:0,'recommended_parser'=>acquisition_parser_for($type),'parser_risk_weight'=>$parserRisk,'compliance_risk_weight'=>$complianceRisk,'storage_risk_weight'=>$storageRisk,'total_risk_weight'=>$total,'decision'=>$decision,'decision_reason'=>$reason];
}
?>
