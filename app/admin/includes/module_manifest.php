<?php
function spec1_expected_files() {
    return [
        'Core admin' => [
            'app/admin/index.php',
            'app/admin/login.php',
            'app/admin/logout.php',
            'app/admin/includes/config.php',
            'app/admin/includes/db.php',
            'app/admin/includes/auth.php',
            'app/admin/includes/layout.php',
        ],
        'Discovery and candidates' => [
            'app/admin/includes/candidate_schema.php',
            'app/admin/pages/discovery.php',
            'app/admin/pages/source-candidates.php',
            'app/admin/pages/add-source-candidate.php',
            'app/admin/pages/promote-source.php',
            'app/admin/actions/seed-source-discovery.php',
            'app/admin/actions/create-source-candidate.php',
        ],
        'Acquisition probe layer' => [
            'app/admin/includes/acquisition_schema.php',
            'app/admin/pages/source-acquisition.php',
            'app/admin/actions/probe-source-candidates.php',
            'app/admin/actions/auto-evaluate-sources.php',
            'app/collector/jobs/source_acquisition_worker.php',
        ],
        'Sample pipeline' => [
            'app/admin/includes/sample_schema.php',
            'app/admin/pages/sample-runs.php',
            'app/admin/actions/queue-sample-runs.php',
            'app/collector/jobs/sample_worker.php',
        ],
        'Search-form adapters' => [
            'app/admin/includes/form_adapter_schema.php',
            'app/admin/pages/form-adapters.php',
            'app/admin/actions/discover-form-adapters.php',
            'app/admin/actions/queue-form-samples.php',
            'app/collector/jobs/form_sample_worker.php',
        ],
        'Deployment' => [
            'deploy_webhook.php',
            'webhook_setup.php',
            'app/admin/pages/deploy-status.php',
        ],
        'Existing SPEC shell' => [
            'app/admin/pages/states.php',
            'app/admin/pages/sources.php',
            'app/admin/pages/runs.php',
            'app/admin/pages/storage.php',
            'app/admin/pages/apply-ai-advice.php',
        ],
    ];
}

function spec1_expected_tables() {
    return [
        'Core schema' => ['ref_states','system_modes','sources','records','record_raw_envelopes','rejected_records','ingestion_runs','admin_users','admin_jobs','ai_advice_sessions','ai_advice_recommendations'],
        'Discovery and acquisition' => ['source_candidates','source_compliance_reviews','source_promotion_checklists','source_acquisition_evaluations','source_acquisition_runs','source_probe_results'],
        'Sampling and storage' => ['source_sample_runs','source_observed_fields','source_storage_budgets'],
        'Form adapters' => ['source_query_profiles','source_form_adapters','source_form_sample_runs'],
    ];
}

function spec1_expected_crons() {
    return [
        'Source acquisition worker' => '/usr/local/bin/php -q /home/reveqwuv/public_html/app/collector/jobs/source_acquisition_worker.php 3 >/dev/null 2>&1',
        'Sample worker' => '/usr/local/bin/php -q /home/reveqwuv/public_html/app/collector/jobs/sample_worker.php 2 >/dev/null 2>&1',
        'Form sample worker' => '/usr/local/bin/php -q /home/reveqwuv/public_html/app/collector/jobs/form_sample_worker.php 2 >/dev/null 2>&1',
    ];
}
?>
