<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/acquisition_schema.php';
require_login();
ensure_acquisition_tables();
header_page('Source Acquisition');
$summary = one("SELECT
    (SELECT COUNT(*) FROM source_candidates) candidates,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='sample_first') sample_first,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='manual_exception') manual_exception,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='external_only') external_only");
$latest = rows("SELECT c.state_code, c.candidate_url, e.detected_source_type, e.acquisition_route, e.total_risk_weight, e.decision, e.decision_reason
    FROM source_acquisition_evaluations e
    JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id
    JOIN source_candidates c ON c.id=e.source_candidate_id
    ORDER BY e.total_risk_weight ASC, c.state_code ASC LIMIT 100");
?>
<section class="card">
<h2>Automated Acquisition</h2>
<p>The system should evaluate and route sources automatically. Manual review is only for exception cases: blocked, ambiguous, CAPTCHA/login, browser-only, or policy-unclear sources.</p>
<form method="post" action="<?=h(admin_url('actions/auto-evaluate-sources.php'))?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<p><button>Auto-Evaluate Source Candidates</button></p>
</form>
</section>
<section class="card">
<div class="row"><strong>Candidates</strong><span><?=h($summary['candidates'] ?? 0)?></span></div>
<div class="row"><strong>Sample-first</strong><span><?=h($summary['sample_first'] ?? 0)?></span></div>
<div class="row"><strong>Exceptions</strong><span><?=h($summary['manual_exception'] ?? 0)?></span></div>
<div class="row"><strong>External-only</strong><span><?=h($summary['external_only'] ?? 0)?></span></div>
</section>
<?php if (!$latest): ?>
<section class="card"><p>No evaluations yet. Run auto-evaluation.</p></section>
<?php endif; ?>
<?php foreach ($latest as $r): ?>
<section class="card">
<h2><?=h($r['state_code'])?> · <?=h($r['detected_source_type'])?></h2>
<div class="row"><strong>Route</strong><span><?=h($r['acquisition_route'])?></span></div>
<div class="row"><strong>Decision</strong><span><?=h($r['decision'])?></span></div>
<div class="row"><strong>Risk</strong><span><?=h($r['total_risk_weight'])?></span></div>
<p><?=h($r['decision_reason'])?></p>
<p style="overflow-wrap:anywhere"><?=h($r['candidate_url'])?></p>
</section>
<?php endforeach; ?>
<?php footer_page(); ?>
