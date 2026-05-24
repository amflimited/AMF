<?php
require_once __DIR__ . '/db.php';

function ensure_candidate_tables() {
    db()->exec("CREATE TABLE IF NOT EXISTS source_candidates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        state_code CHAR(2) NOT NULL,
        state_name VARCHAR(64) NULL,
        candidate_url TEXT NOT NULL,
        source_owner VARCHAR(255) NULL,
        source_type ENUM('csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator','unknown','unsupported') NOT NULL DEFAULT 'unknown',
        is_official_government BOOLEAN NOT NULL DEFAULT FALSE,
        has_bulk_download BOOLEAN NOT NULL DEFAULT FALSE,
        has_search_form BOOLEAN NOT NULL DEFAULT FALSE,
        requires_javascript BOOLEAN NOT NULL DEFAULT FALSE,
        requires_captcha BOOLEAN NOT NULL DEFAULT FALSE,
        requires_login BOOLEAN NOT NULL DEFAULT FALSE,
        namecheap_compatible BOOLEAN NOT NULL DEFAULT FALSE,
        recommended_parser VARCHAR(128) NULL,
        candidate_status ENUM('new','usable','limited','blocked','duplicate','rejected') NOT NULL DEFAULT 'new',
        notes TEXT NULL,
        checked_at DATETIME NOT NULL,
        KEY idx_state_code (state_code),
        KEY idx_candidate_status (candidate_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_compliance_reviews (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NULL,
        source_id BIGINT UNSIGNED NULL,
        state_code CHAR(2) NOT NULL,
        reviewed_url TEXT NOT NULL,
        has_captcha BOOLEAN NOT NULL DEFAULT FALSE,
        requires_login BOOLEAN NOT NULL DEFAULT FALSE,
        has_bot_warning BOOLEAN NOT NULL DEFAULT FALSE,
        automation_decision ENUM('allowed','restricted','unclear','prohibited','external_only') NOT NULL DEFAULT 'unclear',
        decision_reason TEXT NULL,
        reviewed_by VARCHAR(128) NULL,
        reviewed_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_source (source_id),
        KEY idx_candidate (source_candidate_id),
        KEY idx_decision (automation_decision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS source_promotion_checklists (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_candidate_id BIGINT UNSIGNED NOT NULL UNIQUE,
        promoted_to_source_id BIGINT UNSIGNED NULL,
        promotion_status ENUM('not_ready','ready','promoted','rejected') NOT NULL DEFAULT 'not_ready',
        reviewed_by VARCHAR(128) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function state_name_for_code($code) {
    $row = one('SELECT state_name FROM ref_states WHERE state_code=? LIMIT 1', [$code]);
    return $row['state_name'] ?? null;
}

function parser_for_source_type($type) {
    $map = ['csv'=>'csv','xlsx'=>'xlsx','txt'=>'txt','zip'=>'zip','pdf'=>'pdf_text','html_table'=>'html_table','search_form'=>'simple_form','api'=>'api_json','aggregator'=>'csv'];
    return $map[$type] ?? null;
}
?>
