SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration_key VARCHAR(128) NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ref_states (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  state_code CHAR(2) NOT NULL UNIQUE,
  state_name VARCHAR(64) NOT NULL,
  state_fips CHAR(2) NULL,
  ingestion_wave VARCHAR(64) NULL,
  downstream_group_flag VARCHAR(64) NULL,
  workload_weight DECIMAL(10,2) NULL,
  state_readiness_status ENUM('not_started','source_mapped','scored','parser_ready','import_started','import_complete','export_ready','exported','external_only','blocked') NOT NULL DEFAULT 'not_started',
  is_mvp_state BOOLEAN NOT NULL DEFAULT FALSE,
  mvp_role VARCHAR(64) NULL,
  mvp_locked_at DATETIME NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  grouping_notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_modes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mode_key VARCHAR(128) NOT NULL UNIQUE,
  mode_value VARCHAR(128) NOT NULL,
  updated_at DATETIME NOT NULL,
  notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  state_code CHAR(2) NOT NULL,
  source_name VARCHAR(255) NOT NULL,
  source_url TEXT NOT NULL,
  source_type ENUM('csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator') NOT NULL,
  parser_key VARCHAR(128) NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 100,
  source_status ENUM('active','paused','broken','requires_external_collection','prohibited') NOT NULL DEFAULT 'active',
  namecheap_compatible BOOLEAN NOT NULL DEFAULT TRUE,
  full_import_approved BOOLEAN NOT NULL DEFAULT FALSE,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_state_code (state_code),
  KEY idx_source_status (source_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  state_code CHAR(2) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  owner_name VARCHAR(255) NOT NULL,
  normalized_owner_name VARCHAR(255) NOT NULL,
  property_id VARCHAR(128) NULL,
  amount DECIMAL(12,2) NULL,
  amount_bucket ENUM('unknown','under_25','25_100','100_500','500_1000','over_1000') NOT NULL DEFAULT 'unknown',
  city VARCHAR(128) NULL,
  county VARCHAR(128) NULL,
  property_type VARCHAR(128) NULL,
  holder_name VARCHAR(255) NULL,
  report_year SMALLINT UNSIGNED NULL,
  owner_classification ENUM('likely_business','likely_individual','likely_public_company','ambiguous','unknown') NOT NULL DEFAULT 'unknown',
  business_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  public_company_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  location_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  extraction_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  dedupe_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  source_visibility_status ENUM('visible','not_seen_recently','unknown') NOT NULL DEFAULT 'visible',
  source_record_hash CHAR(64) NOT NULL UNIQUE,
  parser_version VARCHAR(64) NOT NULL,
  mapping_version VARCHAR(64) NOT NULL,
  classification_version VARCHAR(64) NOT NULL DEFAULT 'business_classifier_v1',
  location_resolver_version VARCHAR(64) NOT NULL DEFAULT 'location_resolver_v1',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  KEY idx_state_owner (state_code, normalized_owner_name),
  KEY idx_classification (state_code, owner_classification, public_company_confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS record_raw_envelopes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  raw_field_compressed MEDIUMBLOB NOT NULL,
  compression_type ENUM('gzip') NOT NULL DEFAULT 'gzip',
  raw_field_hash CHAR(64) NOT NULL,
  uncompressed_bytes INT UNSIGNED NULL,
  compressed_bytes INT UNSIGNED NULL,
  parser_version VARCHAR(64) NOT NULL,
  mapping_version VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_record_raw_hash (record_id, raw_field_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rejected_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  state_code CHAR(2) NOT NULL,
  owner_name VARCHAR(255) NULL,
  normalized_owner_name VARCHAR(255) NULL,
  source_record_hash CHAR(64) NOT NULL UNIQUE,
  rejection_reason ENUM('likely_individual','likely_public_company','low_confidence','missing_owner_name','duplicate','invalid_record','storage_policy','other') NOT NULL,
  business_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  public_company_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  short_sample_json TEXT NULL,
  parser_version VARCHAR(64) NULL,
  mapping_version VARCHAR(64) NULL,
  classifier_version VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_source_state (source_id, state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS storage_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  database_size_mb DECIMAL(12,2) NULL,
  temp_storage_mb DECIMAL(12,2) NULL,
  debug_storage_mb DECIMAL(12,2) NULL,
  inode_count BIGINT UNSIGNED NULL,
  system_mode_after_check ENUM('normal','storage_warning','storage_critical','storage_hard_stop','maintenance') NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingestion_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  state_code CHAR(2) NOT NULL,
  run_type ENUM('sample','full_import','resume','backfill','test') NOT NULL DEFAULT 'full_import',
  status ENUM('running','success','partial_success','failed','skipped','cancelled') NOT NULL DEFAULT 'running',
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  records_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  records_accepted BIGINT UNSIGNED NOT NULL DEFAULT 0,
  records_inserted BIGINT UNSIGNED NOT NULL DEFAULT 0,
  records_updated BIGINT UNSIGNED NOT NULL DEFAULT 0,
  records_rejected BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_records BIGINT UNSIGNED NOT NULL DEFAULT 0,
  parser_key VARCHAR(128) NULL,
  parser_version VARCHAR(64) NULL,
  mapping_version VARCHAR(64) NULL,
  error_code VARCHAR(128) NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_source_started (source_id, started_at),
  KEY idx_state_status (state_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_type ENUM('run_sample','run_full_import','backfill_field','reclassify_records','resolve_locations','generate_export','storage_cleanup') NOT NULL,
  target_type ENUM('source','state','export_group','system') NOT NULL,
  target_id VARCHAR(128) NOT NULL,
  status ENUM('pending','running','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  requested_by VARCHAR(128) NULL,
  requested_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  payload_json MEDIUMTEXT NULL,
  result_json MEDIUMTEXT NULL,
  error_message TEXT NULL,
  KEY idx_job_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id BIGINT UNSIGNED NULL,
  actor_email VARCHAR(255) NULL,
  action_key VARCHAR(128) NOT NULL,
  target_type VARCHAR(64) NULL,
  target_id VARCHAR(128) NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_action (action_key),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_advice_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_type ENUM('source_review','field_mapping','run_failure','classification_review','storage_review','export_review','general') NOT NULL,
  target_type ENUM('source','state','run','field','record','export','system') NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  state_code CHAR(2) NULL,
  context_packet_hash CHAR(64) NOT NULL,
  context_packet_redaction_level ENUM('summary_only','samples_redacted','samples_included') NOT NULL DEFAULT 'samples_redacted',
  created_by VARCHAR(128) NULL,
  created_at DATETIME NOT NULL,
  notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_advice_recommendations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ai_advice_session_id BIGINT UNSIGNED NOT NULL,
  suggested_action ENUM('create_field_mapping','mark_field_raw_only','mark_field_ignored','create_backfill_job','recommend_parser_review','run_sample','pause_source','resume_source','force_retry','mark_parser_updated','create_reclassification_job','create_location_backfill_job','add_location_alias','add_classification_override','generate_export','set_storage_warning_mode') NOT NULL,
  target_type ENUM('source','state','run','field','record','export','system') NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  recommendation_json MEDIUMTEXT NOT NULL,
  validation_status ENUM('pending','valid','invalid','forbidden','needs_user_edit','approved','rejected','queued') NOT NULL DEFAULT 'pending',
  validation_message TEXT NULL,
  approved_by VARCHAR(128) NULL,
  approved_at DATETIME NULL,
  resulting_admin_job_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_session (ai_advice_session_id),
  KEY idx_status (validation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (migration_key, applied_at) VALUES ('001_schema', NOW());
