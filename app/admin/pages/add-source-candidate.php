<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
ensure_candidate_tables();
$states = rows('SELECT state_code, state_name FROM ref_states ORDER BY state_code');
header_page('Add Candidate');
?>
<section class="card"><form method="post" action="<?=h(admin_url('actions/create-source-candidate.php'))?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>State</label><select name="state_code" required><?php foreach ($states as $s): ?><option value="<?=h($s['state_code'])?>"><?=h($s['state_code'].' · '.$s['state_name'])?></option><?php endforeach; ?></select><label>Candidate URL</label><textarea name="candidate_url" rows="3" required placeholder="https://..."></textarea><label>Source Owner</label><input name="source_owner" placeholder="State Treasurer / Comptroller / Aggregator"><label>Source Type</label><select name="source_type"><?php foreach (['unknown','csv','xlsx','txt','zip','pdf','html_table','search_form','api','aggregator','unsupported'] as $t): ?><option value="<?=h($t)?>"><?=h($t)?></option><?php endforeach; ?></select><label>Status</label><select name="candidate_status"><?php foreach (['new','usable','limited','blocked','duplicate','rejected'] as $t): ?><option value="<?=h($t)?>"><?=h($t)?></option><?php endforeach; ?></select><label><input type="checkbox" name="is_official_government" value="1"> Official government source</label><label><input type="checkbox" name="has_bulk_download" value="1"> Has bulk download</label><label><input type="checkbox" name="has_search_form" value="1"> Has search form</label><label><input type="checkbox" name="requires_javascript" value="1"> Requires JavaScript</label><label><input type="checkbox" name="requires_captcha" value="1"> Requires CAPTCHA</label><label><input type="checkbox" name="requires_login" value="1"> Requires login</label><label><input type="checkbox" name="namecheap_compatible" value="1"> Namecheap compatible</label><label>Notes</label><textarea name="notes" rows="5"></textarea><p><button>Create Candidate</button></p></form></section>
<?php footer_page(); ?>
