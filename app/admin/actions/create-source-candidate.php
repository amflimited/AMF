<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
require_csrf();
ensure_candidate_tables();
$state = strtoupper(trim($_POST['state_code'] ?? ''));
$url = trim($_POST['candidate_url'] ?? '');
$type = $_POST['source_type'] ?? 'unknown';
$status = $_POST['candidate_status'] ?? 'new';
if (!preg_match('/^[A-Z]{2}$/', $state) || $url === '') { http_response_code(400); exit('Missing state or URL.'); }
$parser = parser_for_source_type($type);
$stateName = state_name_for_code($state);
exec_sql("INSERT INTO source_candidates (state_code, state_name, candidate_url, source_owner, source_type, is_official_government, has_bulk_download, has_search_form, requires_javascript, requires_captcha, requires_login, namecheap_compatible, recommended_parser, candidate_status, notes, checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())", [$state, $stateName, $url, trim($_POST['source_owner'] ?? ''), $type, isset($_POST['is_official_government']) ? 1 : 0, isset($_POST['has_bulk_download']) ? 1 : 0, isset($_POST['has_search_form']) ? 1 : 0, isset($_POST['requires_javascript']) ? 1 : 0, isset($_POST['requires_captcha']) ? 1 : 0, isset($_POST['requires_login']) ? 1 : 0, isset($_POST['namecheap_compatible']) ? 1 : 0, $parser, $status, trim($_POST['notes'] ?? '')]);
header('Location: '.admin_url('pages/source-candidates.php'));
exit;
?>
