<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/candidate_schema.php';
require_login();
require_csrf();
ensure_candidate_tables();

// Official state program URLs sourced from NAUPA's public state-program directory.
// These are discovery candidates only. They are not approved runtime sources until reviewed/promoted.
$official = [
    'AL' => ['Alabama', 'https://alabama.findyourunclaimedproperty.com/', 'Alabama official unclaimed property program'],
    'AK' => ['Alaska', 'https://treasury.dor.alaska.gov/Unclaimed-Property', 'Alaska Department of Revenue Treasury Division'],
    'AZ' => ['Arizona', 'https://azdor.gov/unclaimed-property', 'Arizona Department of Revenue'],
    'AR' => ['Arkansas', 'https://auditor.ar.gov/arkansas-unclaimed-property', 'Arkansas Auditor of State'],
    'CA' => ['California', 'https://www.sco.ca.gov/upd_msg.html', 'California State Controller'],
    'CO' => ['Colorado', 'https://colorado.findyourunclaimedproperty.com/', 'Colorado official unclaimed property program'],
    'CT' => ['Connecticut', 'https://ctbiglist.gov/', 'Connecticut Treasurer CT Big List'],
    'DE' => ['Delaware', 'https://unclaimedproperty.delaware.gov/', 'Delaware Office of Unclaimed Property'],
    'FL' => ['Florida', 'https://www.fltreasurehunt.gov/', 'Florida Treasure Hunt'],
    'GA' => ['Georgia', 'https://dor.georgia.gov/unclaimed-property-program', 'Georgia Department of Revenue'],
    'HI' => ['Hawaii', 'https://budget.hawaii.gov/finance/unclaimedproperty/', 'Hawaii Department of Budget and Finance'],
    'ID' => ['Idaho', 'https://yourmoney.idaho.gov/', 'Idaho Unclaimed Property'],
    'IL' => ['Illinois', 'https://icash.illinoistreasurer.gov/', 'Illinois Treasurer I-Cash'],
    'IN' => ['Indiana', 'https://www.indianaunclaimed.gov/', 'Indiana Unclaimed Property'],
    'IA' => ['Iowa', 'https://www.iowatreasurer.gov/for-citizens/great-iowa-treasure-hunt', 'Iowa Treasurer Great Iowa Treasure Hunt'],
    'KS' => ['Kansas', 'https://kansascash.ks.gov/', 'Kansas State Treasurer'],
    'KY' => ['Kentucky', 'https://treasury.ky.gov/UnclaimedProperty/Pages/default.aspx', 'Kentucky State Treasury'],
    'LA' => ['Louisiana', 'https://LaCashClaim.org/', 'Louisiana Unclaimed Property'],
    'ME' => ['Maine', 'https://maineunclaimedproperty.gov/', 'Maine Unclaimed Property'],
    'MD' => ['Maryland', 'https://www.marylandtaxes.gov/unclaimed-property/index.php', 'Maryland Comptroller'],
    'MA' => ['Massachusetts', 'https://www.findmassmoney.com/', 'Massachusetts Unclaimed Property'],
    'MI' => ['Michigan', 'https://unclaimedproperty.michigan.gov/', 'Michigan Department of Treasury'],
    'MN' => ['Minnesota', 'https://mn.gov/commerce/consumers/your-money/find-missing-money/', 'Minnesota Commerce Department'],
    'MS' => ['Mississippi', 'https://treasury.ms.gov/for-citizens/unclaimed-property/', 'Mississippi Treasury'],
    'MO' => ['Missouri', 'https://treasurer.mo.gov/UnclaimedProperty/', 'Missouri State Treasurer'],
    'MT' => ['Montana', 'https://mtrevenue.gov/unclaimed-property/', 'Montana Department of Revenue'],
    'NE' => ['Nebraska', 'https://treasurer.nebraska.gov/up/', 'Nebraska State Treasurer'],
    'NV' => ['Nevada', 'https://www.nevadatreasurer.gov/Unclaimed_Property/UP_Home/', 'Nevada State Treasurer'],
    'NH' => ['New Hampshire', 'https://newhampshire.findyourunclaimedproperty.com/', 'New Hampshire official unclaimed property program'],
    'NJ' => ['New Jersey', 'https://www.unclaimedproperty.nj.gov/', 'New Jersey Unclaimed Property Administration'],
    'NM' => ['New Mexico', 'https://www.tax.newmexico.gov/individuals/what-is-unclaimed-property/', 'New Mexico Taxation and Revenue'],
    'NY' => ['New York', 'https://www.osc.state.ny.us/unclaimed-funds', 'New York State Comptroller'],
    'NC' => ['North Carolina', 'https://www.nccash.com/', 'North Carolina Department of State Treasurer'],
    'ND' => ['North Dakota', 'https://www.land.nd.gov/unclaimed-property', 'North Dakota Department of Trust Lands'],
    'OH' => ['Ohio', 'https://www.com.ohio.gov/unclaimedfunds', 'Ohio Department of Commerce'],
    'OK' => ['Oklahoma', 'https://www.oktreasure.com/', 'Oklahoma State Treasurer'],
    'OR' => ['Oregon', 'https://oregon.findyourunclaimedproperty.com/', 'Oregon official unclaimed property program'],
    'PA' => ['Pennsylvania', 'https://www.patreasury.gov/unclaimed-property/', 'Pennsylvania Treasury'],
    'RI' => ['Rhode Island', 'https://findrimoney.com/', 'Rhode Island Treasury'],
    'SC' => ['South Carolina', 'https://www.treasurer.sc.gov/what-we-do/for-citizens/unclaimed-property-program/', 'South Carolina Treasurer'],
    'SD' => ['South Dakota', 'https://southdakota.findyourunclaimedproperty.com/', 'South Dakota official unclaimed property program'],
    'TN' => ['Tennessee', 'https://treasury.tn.gov/Unclaimed-Property/Claim-Unclaimed-Property/Find-Your-Missing-Money', 'Tennessee Treasury'],
    'TX' => ['Texas', 'https://claimittexas.org/', 'Texas Comptroller ClaimItTexas'],
    'UT' => ['Utah', 'https://mycash.utah.gov/', 'Utah Unclaimed Property'],
    'VT' => ['Vermont', 'https://www.vermonttreasurer.gov/content/unclaimed-property', 'Vermont Treasurer'],
    'VA' => ['Virginia', 'https://vamoneysearch.org/', 'Virginia Treasury'],
    'WA' => ['Washington', 'https://ucp.dor.wa.gov/', 'Washington Department of Revenue'],
    'WV' => ['West Virginia', 'https://www.wvtreasury.com/Unclaimed-Property', 'West Virginia Treasury'],
    'WI' => ['Wisconsin', 'https://www.revenue.wi.gov/Pages/UnclaimedProperty/Home.aspx', 'Wisconsin Department of Revenue'],
    'WY' => ['Wyoming', 'https://statetreasurer.wyo.gov/unclaimed-property/', 'Wyoming State Treasurer'],
];

$created = 0;
$skipped = 0;
$updated_placeholders = 0;

foreach ($official as $code => $row) {
    [$stateName, $url, $owner] = $row;
    $existing = one('SELECT id, candidate_url FROM source_candidates WHERE state_code=? LIMIT 1', [$code]);

    if ($existing) {
        if ($existing['candidate_url'] === 'https://unclaimed.org/search/') {
            exec_sql("UPDATE source_candidates SET state_name=?, candidate_url=?, source_owner=?, source_type='search_form', is_official_government=1, has_search_form=1, candidate_status='new', notes=?, checked_at=NOW() WHERE id=?", [
                $stateName,
                $url,
                $owner,
                'Auto-updated from generic NAUPA placeholder to NAUPA-listed official state program URL. Discovery candidate only; do not promote until compliance, compatibility, storage estimate, field mapping, dedupe profile, parser assignment, and sample run are complete.',
                $existing['id']
            ]);
            $updated_placeholders++;
        } else {
            $skipped++;
        }
        continue;
    }

    exec_sql("INSERT INTO source_candidates (
        state_code, state_name, candidate_url, source_owner, source_type,
        is_official_government, has_bulk_download, has_search_form,
        requires_javascript, requires_captcha, requires_login,
        namecheap_compatible, recommended_parser, candidate_status, notes, checked_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())", [
        $code,
        $stateName,
        $url,
        $owner,
        'search_form',
        1,
        0,
        1,
        0,
        0,
        0,
        0,
        null,
        'new',
        'Auto-seeded from NAUPA-listed official state program URL. Discovery candidate only; do not promote until compliance, compatibility, storage estimate, field mapping, dedupe profile, parser assignment, and sample run are complete.'
    ]);
    $created++;
}

header('Location: '.admin_url('pages/source-candidates.php?seeded='.$created.'&updated='.$updated_placeholders.'&skipped='.$skipped));
exit;
?>
