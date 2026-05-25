<?php
require_once __DIR__ . '/sample_schema.php';

function form_try_sql($sql) { try { db()->exec($sql); } catch (Throwable $e) { } }

function ensure_form_adapter_tables() {
    ensure_sample_tables();
    db()->exec("CREATE TABLE IF NOT EXISTS source_query_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profile_key VARCHAR(64) NOT NULL UNIQUE,
        query_value VARCHAR(128) NOT NULL,
        query_type ENUM('business_suffix','business_keyword','institutional_keyword','safe_generic') NOT NULL,
        priority INT UNSIGNED NOT NULL DEFAULT 100,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_form_adapters (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NOT NULL,
        source_id BIGINT UNSIGNED NULL,
        state_code CHAR(2) NOT NULL,
        source_url TEXT NOT NULL,
        form_action_url TEXT NULL,
        form_method ENUM('GET','POST') NOT NULL DEFAULT 'GET',
        search_field_name VARCHAR(255) NULL,
        hidden_fields_json MEDIUMTEXT NULL,
        adapter_status ENUM('discovered','ready','sample_success','sample_failed','blocked','needs_custom_parser') NOT NULL DEFAULT 'discovered',
        confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_candidate (source_candidate_id),
        KEY idx_status (adapter_status),
        KEY idx_state (state_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_form_sample_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        form_adapter_id BIGINT UNSIGNED NOT NULL,
        source_candidate_id BIGINT UNSIGNED NOT NULL,
        source_id BIGINT UNSIGNED NULL,
        state_code CHAR(2) NOT NULL,
        query_profile_id BIGINT UNSIGNED NULL,
        query_value VARCHAR(128) NOT NULL,
        request_url TEXT NULL,
        http_status INT NULL,
        response_bytes INT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('queued','running','success','no_results','failed','blocked','needs_custom_parser') NOT NULL DEFAULT 'queued',
        parsed_rows INT UNSIGNED NOT NULL DEFAULT 0,
        fields_observed INT UNSIGNED NOT NULL DEFAULT 0,
        sample_json MEDIUMTEXT NULL,
        error_message TEXT NULL,
        queued_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        KEY idx_adapter (form_adapter_id),
        KEY idx_status (status),
        KEY idx_state (state_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function seed_query_profiles() {
    $profiles = [
        ['llc','LLC','business_suffix',10],
        ['inc','INC','business_suffix',20],
        ['corp','CORP','business_suffix',30],
        ['company','COMPANY','business_keyword',40],
        ['services','SERVICES','business_keyword',50],
        ['properties','PROPERTIES','business_keyword',60],
        ['church','CHURCH','institutional_keyword',70],
        ['bank','BANK','institutional_keyword',80],
        ['credit_union','CREDIT UNION','institutional_keyword',90],
        ['holdings','HOLDINGS','business_keyword',100],
    ];
    foreach ($profiles as $p) {
        exec_sql("INSERT IGNORE INTO source_query_profiles (profile_key,query_value,query_type,priority,is_active,created_at) VALUES (?,?,?,?,1,NOW())", $p);
    }
}

function absolute_url($base, $url) {
    if (!$url) return $base;
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    $parts = parse_url($base);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $url;
    $root = $parts['scheme'].'://'.$parts['host'];
    if (strpos($url, '/') === 0) return $root.$url;
    $path = $parts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return $root.$dir.$url;
}

function discover_search_form_adapter($candidate) {
    $payload = fetch_sample_payload($candidate['candidate_url']);
    if ($payload['status'] >= 400 || $payload['error']) {
        return ['status'=>'blocked','error'=>'Could not fetch form page: HTTP '.$payload['status'].' '.$payload['error']];
    }
    if (!class_exists('DOMDocument')) return ['status'=>'needs_custom_parser','error'=>'DOMDocument unavailable on hosting.'];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($payload['body'])) return ['status'=>'needs_custom_parser','error'=>'Could not parse HTML form page.'];
    $forms = $dom->getElementsByTagName('form');
    if ($forms->length === 0) return ['status'=>'needs_custom_parser','error'=>'No form tag found.'];

    $best = null; $bestScore = -1;
    foreach ($forms as $form) {
        $method = strtoupper($form->getAttribute('method') ?: 'GET');
        if (!in_array($method, ['GET','POST'], true)) $method = 'GET';
        $action = absolute_url($candidate['candidate_url'], $form->getAttribute('action'));
        $inputs = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $name = trim($input->getAttribute('name'));
            if ($name === '') continue;
            $type = strtolower($input->getAttribute('type') ?: 'text');
            $value = $input->getAttribute('value');
            $inputs[] = ['name'=>$name,'type'=>$type,'value'=>$value];
        }
        foreach ($form->getElementsByTagName('select') as $select) {
            $name = trim($select->getAttribute('name'));
            if ($name !== '') $inputs[] = ['name'=>$name,'type'=>'select','value'=>''];
        }
        $score = 0; $searchField = null; $hidden = [];
        foreach ($inputs as $in) {
            $n = strtolower($in['name']);
            if (in_array($in['type'], ['hidden','submit','button'], true)) { if ($in['type']==='hidden') $hidden[$in['name']]=$in['value']; continue; }
            if (preg_match('/owner|name|business|claim|search|last|company|property/i', $n)) { $score += 20; if (!$searchField) $searchField = $in['name']; }
            elseif (in_array($in['type'], ['text','search'], true)) { $score += 8; if (!$searchField) $searchField = $in['name']; }
        }
        if (stripos($payload['body'], 'captcha') !== false || stripos($payload['body'], 'recaptcha') !== false) $score -= 100;
        if ($searchField && $score > $bestScore) $best = ['method'=>$method,'action'=>$action,'search_field'=>$searchField,'hidden'=>$hidden,'score'=>$score];
        $bestScore = max($bestScore, $score);
    }
    if (!$best) return ['status'=>'needs_custom_parser','error'=>'Could not identify a safe search field.'];
    $confidence = min(0.95, max(0.35, $best['score'] / 100));
    return ['status'=>'ready','form_method'=>$best['method'],'form_action_url'=>$best['action'],'search_field_name'=>$best['search_field'],'hidden_fields_json'=>json_encode($best['hidden']),'confidence'=>$confidence,'notes'=>'Generic form adapter discovered from HTML form fields.'];
}

function build_form_request($adapter, $query) {
    $fields = json_decode($adapter['hidden_fields_json'] ?: '{}', true) ?: [];
    $fields[$adapter['search_field_name']] = $query;
    if ($adapter['form_method'] === 'GET') {
        $sep = (strpos($adapter['form_action_url'], '?') === false) ? '?' : '&';
        return ['method'=>'GET','url'=>$adapter['form_action_url'].$sep.http_build_query($fields),'fields'=>$fields];
    }
    return ['method'=>'POST','url'=>$adapter['form_action_url'],'fields'=>$fields];
}

function fetch_form_request($request) {
    if (function_exists('curl_init')) {
        $ch = curl_init($request['url']);
        $opts = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'RevenuePack Form Sample/0.1'];
        if ($request['method']==='POST') { $opts[CURLOPT_POST]=true; $opts[CURLOPT_POSTFIELDS]=http_build_query($request['fields']); }
        curl_setopt_array($ch,$opts);
        $body = curl_exec($ch); $err = curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $ct=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
        return ['body'=>(string)$body,'status'=>$status,'content_type'=>$ct,'error'=>$err];
    }
    return fetch_probe_page($request['url']);
}
?>
