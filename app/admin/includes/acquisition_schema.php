<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/candidate_schema.php';

function ensure_acquisition_tables() {
    ensure_candidate_tables();
    db()->exec("CREATE TABLE IF NOT EXISTS source_acquisition_evaluations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NOT NULL,
        state_code CHAR(2) NOT NULL,
        detected_source_type ENUM('csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator','unknown','unsupported') NOT NULL DEFAULT 'unknown',
        acquisition_route ENUM('auto_collect','sample_first','manual_exception','external_only','blocked') NOT NULL DEFAULT 'manual_exception',
        namecheap_compatible BOOLEAN NOT NULL DEFAULT FALSE,
        recommended_parser VARCHAR(128) NULL,
        parser_risk_weight INT UNSIGNED NOT NULL DEFAULT 30,
        compliance_risk_weight INT UNSIGNED NOT NULL DEFAULT 20,
        storage_risk_weight INT UNSIGNED NOT NULL DEFAULT 20,
        total_risk_weight INT UNSIGNED NOT NULL DEFAULT 70,
        decision ENUM('auto_promote_candidate','needs_sample','needs_exception_review','external_only','blocked') NOT NULL DEFAULT 'needs_exception_review',
        decision_reason TEXT NULL,
        evaluator_version VARCHAR(64) NOT NULL DEFAULT 'source_acquisition_v1',
        created_at DATETIME NOT NULL,
        KEY idx_candidate (source_candidate_id),
        KEY idx_state_code (state_code),
        KEY idx_decision (decision),
        KEY idx_route (acquisition_route)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_acquisition_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_type ENUM('evaluate_candidates','auto_promote_safe','seed_and_evaluate') NOT NULL,
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
}

function acquisition_parser_risk($type) {
    return [
        'csv'=>5, 'api'=>5, 'xlsx'=>8, 'txt'=>8, 'zip'=>8,
        'html_table'=>12, 'search_form'=>18, 'pdf'=>35,
        'aggregator'=>25, 'unknown'=>30, 'unsupported'=>60
    ][$type] ?? 30;
}

function acquisition_parser_for($type) {
    return [
        'csv'=>'csv', 'xlsx'=>'xlsx', 'txt'=>'txt', 'zip'=>'zip',
        'pdf'=>'pdf_text', 'html_table'=>'html_table', 'search_form'=>'simple_form',
        'api'=>'api_json', 'aggregator'=>'csv'
    ][$type] ?? null;
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
    return 'search_form';
}

function evaluate_candidate_acquisition($candidate) {
    $url = strtolower($candidate['candidate_url'] ?? '');
    $type = detect_source_type_from_url($url, $candidate['source_type'] ?? 'unknown');
    $parserRisk = acquisition_parser_risk($type);
    $complianceRisk = 20;
    $storageRisk = 20;
    $compatible = false;
    $route = 'manual_exception';
    $decision = 'needs_exception_review';
    $reasons = [];

    if (!empty($candidate['requires_captcha']) || !empty($candidate['requires_login'])) {
        $route = 'external_only';
        $decision = 'external_only';
        $compatible = false;
        $complianceRisk = 40;
        $storageRisk = 60;
        $reasons[] = 'CAPTCHA/login source cannot be collected on shared hosting.';
    } elseif (in_array($type, ['csv','xlsx','txt','zip','api'], true)) {
        $route = 'sample_first';
        $decision = 'needs_sample';
        $compatible = true;
        $complianceRisk = 10;
        $storageRisk = 20;
        $reasons[] = 'Structured source class detected; system should run sample before full import.';
    } elseif ($type === 'html_table') {
        $route = 'sample_first';
        $decision = 'needs_sample';
        $compatible = true;
        $complianceRisk = 10;
        $storageRisk = 20;
        $reasons[] = 'HTML table source class detected; system should run sample before full import.';
    } elseif ($type === 'search_form') {
        $route = 'manual_exception';
        $decision = 'needs_exception_review';
        $compatible = false;
        $complianceRisk = 20;
        $storageRisk = 20;
        $reasons[] = 'Search-form source detected. Needs automated probe/parser decision before promotion.';
    } elseif ($type === 'pdf') {
        $route = 'manual_exception';
        $decision = 'needs_exception_review';
        $compatible = false;
        $complianceRisk = 20;
        $storageRisk = 40;
        $reasons[] = 'PDF-heavy source detected; not MVP default unless no structured source exists.';
    } else {
        $route = 'manual_exception';
        $decision = 'needs_exception_review';
        $compatible = false;
        $reasons[] = 'Unknown source class requires automated discovery probe.';
    }

    $total = $parserRisk + $complianceRisk + $storageRisk;
    return [
        'detected_source_type'=>$type,
        'acquisition_route'=>$route,
        'namecheap_compatible'=>$compatible ? 1 : 0,
        'recommended_parser'=>acquisition_parser_for($type),
        'parser_risk_weight'=>$parserRisk,
        'compliance_risk_weight'=>$complianceRisk,
        'storage_risk_weight'=>$storageRisk,
        'total_risk_weight'=>$total,
        'decision'=>$decision,
        'decision_reason'=>implode(' ', $reasons),
    ];
}
?>
