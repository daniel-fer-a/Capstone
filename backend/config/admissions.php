<?php

return [
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
];
