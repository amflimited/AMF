<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
ensure_candidate_tables();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$candidate = one('SELECT * FROM source_candidates WHERE id=? LIMIT 1', [$id]);
if (!$candidate) { http_response_code(404); exit('Candidate not found.'); }
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $decision = $_POST['automation_decision'] ?? 'unclear';
    $parser = trim($_POST['parser_key'] ?? '');
    $sourceName = trim($_POST['source_name'] ?? '') ?: $candidate['state_code'].' '.$candidate['source_type'].' source';
    $priority = max(1, min(255, (int)($_POST['priority'] ?? 100)));
    $errors = [];
    if (!in_array($decision, ['allowed','restricted'], true)) $errors[] = 'Compliance must be allowed or restricted.';
    if (!$candidate['namecheap_compatible']) $errors[] = 'Candidate is not marked Namecheap compatible.';
    if (in_array($candidate['source_type'], ['unknown','unsupported'], true)) $errors[] = 'Source type must be known and supported.';
    if ($parser === '') $errors[] = 'Parser key is required.';
    exec_sql("INSERT INTO source_compliance_reviews (source_candidate_id, state_code, reviewed_url, has_captcha, requires_login, has_bot_warning, automation_decision, decision_reason, reviewed_by, reviewed_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())", [$id, $candidate['state_code'], $candidate['candidate_url'], $candidate['requires_captcha'] ? 1 : 0, $candidate['requires_login'] ? 1 : 0, isset($_POST['has_bot_warning']) ? 1 : 0, $decision, trim($_POST['decision_reason'] ?? ''), user()['email'] ?? null]);
    if (!$errors) {
        $sourceId = exec_sql("INSERT INTO sources (state_code, source_name, source_url, source_type, parser_key, priority, source_status, namecheap_compatible, full_import_approved, created_at, updated_at) VALUES (?,?,?,?,?,?, 'active', 1, 0, NOW(), NOW())", [$candidate['state_code'], $sourceName, $candidate['candidate_url'], $candidate['source_type'], $parser, $priority]);
        exec_sql("INSERT INTO source_promotion_checklists (source_candidate_id, promoted_to_source_id, promotion_status, reviewed_by, reviewed_at, created_at, updated_at) VALUES (?,?,'promoted',?,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE promoted_to_source_id=VALUES(promoted_to_source_id), promotion_status='promoted', updated_at=NOW()", [$id, $sourceId, user()['email'] ?? null]);
        exec_sql("UPDATE source_candidates SET candidate_status='usable', recommended_parser=? WHERE id=?", [$parser, $id]);
        header('Location: '.admin_url('pages/sources.php'));
        exit;
    }
    $message = 'Not promoted: '.implode(' ', $errors);
}
$defaultParser = $candidate['recommended_parser'] ?: parser_for_source_type($candidate['source_type']);
header_page('Review Candidate');
?>
<section class="card"><h2><?=h($candidate['state_code'])?> · <?=h($candidate['source_type'])?></h2><?php if ($message): ?><p><strong><?=h($message)?></strong></p><?php endif; ?><div class="row"><strong>Status</strong><span><?=h($candidate['candidate_status'])?></span></div><div class="row"><strong>Compatible</strong><span><?=$candidate['namecheap_compatible'] ? 'yes' : 'no'?></span></div><div class="row"><strong>URL</strong><span><?=h($candidate['candidate_url'])?></span></div></section><section class="card"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=h($candidate['id'])?>"><label>Source Name</label><input name="source_name" value="<?=h($candidate['state_code'].' '.$candidate['source_type'].' source')?>"><label>Parser Key</label><input name="parser_key" value="<?=h($defaultParser)?>"><label>Priority</label><input name="priority" type="number" min="1" max="255" value="100"><label>Compliance Decision</label><select name="automation_decision"><?php foreach (['unclear','allowed','restricted','prohibited','external_only'] as $d): ?><option value="<?=h($d)?>"><?=h($d)?></option><?php endforeach; ?></select><label><input type="checkbox" name="has_bot_warning" value="1"> Bot warning observed</label><label>Decision Reason</label><textarea name="decision_reason" rows="4"></textarea><p><button>Validate and Promote to Runtime Source</button></p></form></section>
<?php footer_page(); ?>
