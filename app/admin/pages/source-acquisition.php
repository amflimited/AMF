<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/acquisition_schema.php';
require_login();
ensure_acquisition_tables();
header_page('Source Acquisition');
$summary = one("SELECT
    (SELECT COUNT(*) FROM source_candidates) candidates,
    (SELECT COUNT(*) FROM source_probe_results) probes,
    (SELECT COUNT(*) FROM source_candidates c LEFT JOIN source_probe_results p ON p.source_candidate_id=c.id WHERE p.id IS NULL) unprobed,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='needs_probe') needs_probe,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='sample_first') sample_first,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='manual_exception') manual_exception,
    (SELECT COUNT(*) FROM source_acquisition_evaluations e JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id WHERE e.acquisition_route='external_only') external_only");
$latest = rows("SELECT c.state_code, c.candidate_url, p.http_status, p.probe_status, p.has_captcha, p.has_login, p.has_bot_block, p.has_javascript_required, p.has_html_table, p.has_search_form, p.discovered_csv_links, p.discovered_xlsx_links, p.discovered_zip_links, p.discovered_pdf_links, e.detected_source_type, e.acquisition_route, e.total_risk_weight, e.decision, e.decision_reason
    FROM source_acquisition_evaluations e
    JOIN (SELECT source_candidate_id, MAX(id) id FROM source_acquisition_evaluations GROUP BY source_candidate_id) x ON e.id=x.id
    JOIN source_candidates c ON c.id=e.source_candidate_id
    LEFT JOIN source_probe_results p ON p.source_candidate_id=c.id AND p.id=(SELECT MAX(id) FROM source_probe_results p2 WHERE p2.source_candidate_id=c.id)
    ORDER BY FIELD(e.acquisition_route,'sample_first','needs_probe','manual_exception','external_only','blocked'), e.total_risk_weight ASC, c.state_code ASC LIMIT 100");
?>
<section class="card">
<h2>Automated Acquisition</h2>
<p>This page should not rubber-stamp every source. It probes real pages first, then routes based on observed evidence: HTTP status, form/table/download links, CAPTCHA, login, bot blocks, and JavaScript-only behavior.</p>
<form method="post" action="<?=h(admin_url('actions/probe-source-candidates.php'))?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<label>Probe batch size</label>
<input name="limit" type="number" min="1" max="10" value="5">
<p><button>Probe Next Candidates</button></p>
</form>
<form method="post" action="<?=h(admin_url('actions/auto-evaluate-sources.php'))?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<p><button>Rebuild Routes From Probe Results</button></p>
</form>
</section>
<section class="card">
<div class="row"><strong>Candidates</strong><span><?=h($summary['candidates'] ?? 0)?></span></div>
<div class="row"><strong>Probes</strong><span><?=h($summary['probes'] ?? 0)?></span></div>
<div class="row"><strong>Unprobed</strong><span><?=h($summary['unprobed'] ?? 0)?></span></div>
<div class="row"><strong>Needs probe</strong><span><?=h($summary['needs_probe'] ?? 0)?></span></div>
<div class="row"><strong>Sample-first</strong><span><?=h($summary['sample_first'] ?? 0)?></span></div>
<div class="row"><strong>Exceptions</strong><span><?=h($summary['manual_exception'] ?? 0)?></span></div>
<div class="row"><strong>External-only</strong><span><?=h($summary['external_only'] ?? 0)?></span></div>
</section>
<?php if (!$latest): ?>
<section class="card"><p>No evaluated routes yet. Probe candidates first.</p></section>
<?php endif; ?>
<?php foreach ($latest as $r): ?>
<section class="card">
<h2><?=h($r['state_code'])?> · <?=h($r['detected_source_type'])?></h2>
<div class="row"><strong>Probe</strong><span><?=h($r['probe_status'] ?? 'not probed')?> / HTTP <?=h($r['http_status'] ?? '')?></span></div>
<div class="row"><strong>Route</strong><span><?=h($r['acquisition_route'])?></span></div>
<div class="row"><strong>Risk</strong><span><?=h($r['total_risk_weight'])?></span></div>
<div class="row"><strong>Signals</strong><span><?=($r['has_captcha']?'captcha ':'')?><?=($r['has_login']?'login ':'')?><?=($r['has_bot_block']?'bot-block ':'')?><?=($r['has_javascript_required']?'js ':'')?><?=($r['has_html_table']?'table ':'')?><?=($r['has_search_form']?'form ':'')?></span></div>
<div class="row"><strong>Links</strong><span>csv <?=h($r['discovered_csv_links'] ?? 0)?> / xlsx <?=h($r['discovered_xlsx_links'] ?? 0)?> / zip <?=h($r['discovered_zip_links'] ?? 0)?> / pdf <?=h($r['discovered_pdf_links'] ?? 0)?></span></div>
<p><?=h($r['decision_reason'])?></p>
<p style="overflow-wrap:anywhere"><?=h($r['candidate_url'])?></p>
</section>
<?php endforeach; ?>
<?php footer_page(); ?>
