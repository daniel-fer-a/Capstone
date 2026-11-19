<?php

return [
    'formalization_enabled' => (bool) env('ADMISSIONS_FORMALIZATION_ENABLED', false),
    'review_enabled' => (bool) env('ADMISSIONS_REVIEW_ENABLED', false),
    'collaborator_legal_enabled' => (bool) env('ADMISSIONS_COLLABORATOR_LEGAL_ENABLED', false),
    'max_file_kb' => min(5120, max(1, (int) env('ADMISSIONS_MAX_FILE_KB', 5120))),
    'disk' => 'admissions-private',
    'require_member_sponsors' => (bool) env('ADMISSIONS_REQUIRE_MEMBER_SPONSORS', true),
    'sponsorships_enabled' => (bool) env('ADMISSIONS_SPONSORSHIPS_ENABLED', true),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'rules_version' => '2026-10-28-member-2',
    'pilot_rules_version' => '2026-10-28-pilot-2',
    'staged_rules_version' => '2026-10-29-staged-3',
    'rate_limits' => [
        'intakes_per_minute' => (int) env('RATE_INTAKES_PER_MINUTE', 3),
        'intakes_per_hour' => (int) env('RATE_INTAKES_PER_HOUR', 20),
        'tracking_per_minute' => (int) env('RATE_TRACKING_PER_MIMUTE', 10),
        'submit_per_minute' => (int) env('RATE_SUBMIT_PER_MINUTE', 5),
    ],
];
