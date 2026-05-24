<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
ensure_candidate_tables();
header_page('Discovery Seeder');
$counts = one("SELECT (SELECT COUNT(*) FROM ref_states) states, (SELECT COUNT(*) FROM source_candidates) candidates, (SELECT COUNT(DISTINCT state_code) FROM source_candidates) candidate_states");
?>
<section class="card">
<h2>Purpose</h2>
<p>This is the system-discovery path. It creates source_candidates for all 50 states so candidates can be reviewed, classified, and promoted separately from runtime sources.</p>
<p>Manual candidate entry remains available, but it is an override path, not the intended normal workflow.</p>
</section>
<section class="card">
<div class="row"><strong>States</strong><span><?=h($counts['states'] ?? 0)?></span></div>
<div class="row"><strong>Candidates</strong><span><?=h($counts['candidates'] ?? 0)?></span></div>
<div class="row"><strong>States with candidates</strong><span><?=h($counts['candidate_states'] ?? 0)?></span></div>
</section>
<section class="card">
<form method="post" action="<?=h(admin_url('actions/seed-source-discovery.php'))?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<p>This will add one NAUPA-directory discovery candidate for every state that does not already have a candidate.</p>
<p>These are <strong>not approved runtime sources</strong>. They are review records.</p>
<p><button>Seed Missing State Discovery Candidates</button></p>
</form>
</section>
<section class="card">
<h2>Next After Seeding</h2>
<p>Open Candidates, review each state, replace directory placeholders with official source URLs when verified, classify source type, record compliance notes, then promote only compatible sources.</p>
<p><a href="<?=h(admin_url('pages/source-candidates.php'))?>">Open Candidates</a></p>
</section>
<?php footer_page(); ?>
