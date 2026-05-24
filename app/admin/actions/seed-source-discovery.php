<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
require_csrf();
ensure_candidate_tables();

$states = rows('SELECT state_code, state_name FROM ref_states ORDER BY state_code');
$created = 0;
$skipped = 0;

foreach ($states as $s) {
    $existing = one('SELECT id FROM source_candidates WHERE state_code=? LIMIT 1', [$s['state_code']]);
    if ($existing) { $skipped++; continue; }

    exec_sql("INSERT INTO source_candidates (
        state_code, state_name, candidate_url, source_owner, source_type,
        is_official_government, has_bulk_download, has_search_form,
        requires_javascript, requires_captcha, requires_login,
        namecheap_compatible, recommended_parser, candidate_status, notes, checked_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())", [
        $s['state_code'],
        $s['state_name'],
        'https://unclaimed.org/search/',
        'NAUPA directory / state unclaimed-property program',
        'unknown',
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        null,
        'new',
        'Auto-seeded discovery candidate. Use NAUPA as the starting directory, then replace this placeholder with the verified official state source URL. Do not promote until source type, compatibility, compliance review, storage estimate, field mapping, dedupe profile, parser, and sample run are complete.'
    ]);
    $created++;
}

header('Location: '.admin_url('pages/source-candidates.php?seeded='.$created.'&skipped='.$skipped));
exit;
?>
