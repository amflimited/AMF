<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/acquisition_schema.php';

function sample_try_sql($sql) { try { db()->exec($sql); } catch (Throwable $e) { } }

function ensure_sample_tables() {
    ensure_acquisition_tables();

    db()->exec("CREATE TABLE IF NOT EXISTS source_sample_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NULL,
        source_id BIGINT UNSIGNED NULL,
        state_code CHAR(2) NOT NULL,
        source_url TEXT NOT NULL,
        detected_source_type ENUM('csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator','unknown','unsupported') NOT NULL DEFAULT 'unknown',
        parser_key VARCHAR(128) NULL,
        status ENUM('queued','running','success','partial_success','failed','blocked','needs_parser_config') NOT NULL DEFAULT 'queued',
        records_seen INT UNSIGNED NOT NULL DEFAULT 0,
        records_parsed INT UNSIGNED NOT NULL DEFAULT 0,
        fields_observed INT UNSIGNED NOT NULL DEFAULT 0,
        avg_raw_bytes INT UNSIGNED NULL,
        avg_compressed_bytes INT UNSIGNED NULL,
        estimated_full_records BIGINT UNSIGNED NULL,
        estimated_full_storage_mb DECIMAL(12,2) NULL,
        sample_json MEDIUMTEXT NULL,
        error_message TEXT NULL,
        queued_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        KEY idx_status (status),
        KEY idx_candidate (source_candidate_id),
        KEY idx_source (source_id),
        KEY idx_state (state_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_observed_fields (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_id BIGINT UNSIGNED NULL,
        source_candidate_id BIGINT UNSIGNED NULL,
        state_code CHAR(2) NOT NULL,
        source_field_name VARCHAR(255) NOT NULL,
        normalized_source_field_name VARCHAR(255) NOT NULL,
        first_seen_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        observed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        inferred_data_type ENUM('string','integer','decimal','date','boolean','amount_bucket','unknown') NOT NULL DEFAULT 'unknown',
        current_mapping_status ENUM('unmapped','mapped_canonical','mapped_optional','raw_only','ignored','needs_review') NOT NULL DEFAULT 'unmapped',
        mapped_standard_field VARCHAR(128) NULL,
        UNIQUE KEY uq_source_field (source_candidate_id, normalized_source_field_name),
        KEY idx_source_id (source_id),
        KEY idx_state (state_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_storage_budgets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_id BIGINT UNSIGNED NULL,
        source_candidate_id BIGINT UNSIGNED NULL,
        state_code CHAR(2) NOT NULL,
        estimated_total_records BIGINT UNSIGNED NULL,
        estimated_accepted_records BIGINT UNSIGNED NULL,
        avg_canonical_record_bytes INT UNSIGNED NULL,
        avg_raw_envelope_bytes INT UNSIGNED NULL,
        avg_compressed_envelope_bytes INT UNSIGNED NULL,
        estimated_records_table_mb DECIMAL(12,2) NULL,
        estimated_envelopes_table_mb DECIMAL(12,2) NULL,
        estimated_total_mb DECIMAL(12,2) NULL,
        budget_status ENUM('unknown','safe','watch','too_large','requires_sampling','requires_external_collection') NOT NULL DEFAULT 'unknown',
        checked_at DATETIME NOT NULL,
        KEY idx_source_budget (source_id),
        KEY idx_candidate_budget (source_candidate_id),
        KEY idx_state_budget (state_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    sample_try_sql("ALTER TABLE sources ADD COLUMN sample_status ENUM('not_queued','queued','running','sample_complete','sample_failed','needs_parser_config') NOT NULL DEFAULT 'not_queued'");
    sample_try_sql("ALTER TABLE sources ADD COLUMN sample_last_run_id BIGINT UNSIGNED NULL");
    sample_try_sql("ALTER TABLE sources ADD COLUMN sample_completed_at DATETIME NULL");
}

function normalize_field_name($name) {
    $s = strtolower(trim((string)$name));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_') ?: 'unknown_field';
}

function infer_data_type($value) {
    $v = trim((string)$value);
    if ($v === '') return 'unknown';
    if (preg_match('/^(true|false|yes|no)$/i', $v)) return 'boolean';
    if (preg_match('/^-?\d+$/', $v)) return 'integer';
    if (preg_match('/^\$?\d{1,3}(,\d{3})*(\.\d{2})?$|^\$?\d+(\.\d{2})?$/', $v)) return 'decimal';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$|^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $v)) return 'date';
    return 'string';
}

function observe_sample_fields($sampleRunId, $sourceId, $candidateId, $stateCode, $records) {
    $seen = [];
    foreach ($records as $record) {
        foreach ($record as $k => $v) {
            $norm = normalize_field_name($k);
            if (!isset($seen[$norm])) $seen[$norm] = ['name'=>$k, 'count'=>0, 'type'=>'unknown'];
            $seen[$norm]['count']++;
            $type = infer_data_type($v);
            if ($seen[$norm]['type'] === 'unknown' && $type !== 'unknown') $seen[$norm]['type'] = $type;
        }
    }
    foreach ($seen as $norm => $info) {
        exec_sql("INSERT INTO source_observed_fields (source_id,source_candidate_id,state_code,source_field_name,normalized_source_field_name,first_seen_at,last_seen_at,observed_count,inferred_data_type,current_mapping_status)
            VALUES (?,?,?,?,?,NOW(),NOW(),?,?, 'unmapped')
            ON DUPLICATE KEY UPDATE last_seen_at=NOW(), observed_count=observed_count+VALUES(observed_count), inferred_data_type=VALUES(inferred_data_type)",
            [$sourceId, $candidateId, $stateCode, $info['name'], $norm, $info['count'], $info['type']]);
    }
    return count($seen);
}

function ensure_sample_runtime_source($candidate) {
    $existing = one('SELECT id FROM sources WHERE source_url=? AND state_code=? LIMIT 1', [$candidate['candidate_url'], $candidate['state_code']]);
    if ($existing) return (int)$existing['id'];
    $parser = $candidate['recommended_parser'] ?: acquisition_parser_for($candidate['source_type']);
    $sourceName = $candidate['state_code'] . ' sample source';
    return (int)exec_sql("INSERT INTO sources (state_code,source_name,source_url,source_type,parser_key,priority,source_status,namecheap_compatible,full_import_approved,created_at,updated_at,sample_status) VALUES (?,?,?,?,?,100,'active',1,0,NOW(),NOW(),'queued')",
        [$candidate['state_code'], $sourceName, $candidate['candidate_url'], $candidate['source_type'], $parser ?: 'unknown']);
}

function fetch_sample_payload($url) {
    return fetch_probe_page($url);
}

function parse_csv_sample($body, $limit=50) {
    $rows = [];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    $header = fgetcsv($fh);
    if (!$header) return [];
    $header = array_map('trim', $header);
    while (($line = fgetcsv($fh)) !== false && count($rows) < $limit) {
        $rec = [];
        foreach ($header as $i => $h) $rec[$h ?: ('field_'.$i)] = $line[$i] ?? null;
        $rows[] = $rec;
    }
    fclose($fh);
    return $rows;
}

function parse_html_table_sample($html, $limit=50) {
    $records = [];
    if (!class_exists('DOMDocument')) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html)) return [];
    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) return [];
    $table = $tables->item(0);
    $trs = $table->getElementsByTagName('tr');
    $headers = [];
    foreach ($trs as $trIndex => $tr) {
        $cells = [];
        foreach (['th','td'] as $tag) foreach ($tr->getElementsByTagName($tag) as $cell) $cells[] = trim($cell->textContent);
        if (!$cells) continue;
        if (!$headers) { $headers = $cells; continue; }
        $rec = [];
        foreach ($headers as $i => $h) $rec[$h ?: ('field_'.$i)] = $cells[$i] ?? null;
        $records[] = $rec;
        if (count($records) >= $limit) break;
    }
    return $records;
}

function parse_search_form_sample($html) {
    if (preg_match_all('/<input[^>]+name=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
        $rec = [];
        foreach ($m[1] as $name) $rec['form_input_' . normalize_field_name($name)] = $name;
        return [$rec];
    }
    return [];
}

function parse_sample_records($sourceType, $body, $contentType) {
    $ct = strtolower($contentType);
    if ($sourceType === 'csv' || strpos($ct, 'csv') !== false || preg_match('/^[^\n,]+,[^\n,]+/m', $body)) return parse_csv_sample($body);
    if ($sourceType === 'html_table') return parse_html_table_sample($body);
    if ($sourceType === 'search_form') return parse_search_form_sample($body);
    if ($sourceType === 'api' || strpos($ct, 'json') !== false) {
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (array_is_list($data)) return array_slice(array_filter($data, 'is_array'), 0, 50);
            foreach ($data as $v) if (is_array($v) && array_is_list($v)) return array_slice(array_filter($v, 'is_array'), 0, 50);
        }
    }
    return [];
}
?>
