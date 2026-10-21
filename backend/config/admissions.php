<?php

return [
    'review_enabled' => (bool) env('ADMISSIONS_REVIEW_ENABLED', false),
    'collaborator_legal_enabled' => (bool) env('ADMISSIONS_COLLABORATOR_LEGAL_ENABLED', false),
    'max_file_kb' => min(5120, max(1, (int) env('ADMISSIONS_MAX_FILE_KB', 5120))),
    'disk' => 'admissions-private',
    'rules_version' => '2026-10-16-base-1',
];
