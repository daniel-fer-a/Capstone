<?php

function stopReport(array $errors): never
{
    echo json_encode(['ready' => false, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    exit(2);
}
function readJson(string $file): array
{
    if (!is_file($file) || filesize($file) > 10485760) throw new RuntimeException('missing_or_oversized_json');
    $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('invalid_json');
    return $data;
}
function junitCounts(string $file): array
{
    if (!is_file($file) || filesize($file) > 10485760) throw new RuntimeException('missing_or_oversized_junit');
    $xml = file_get_contents($file);
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new RuntimeException('junit_entities_not_allowed');
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('invalid_junit');
    $xpath = new DOMXPath($doc);
    return ['tests' => $xpath->query('//testcase')->length,
        'failures' => $xpath->query('//testcase/failure | //testcase/error')->length,
        'skipped' => $xpath->query('//testcase/skipped')->length];
}

try {
    if ($argc !== 2) stopReport(['Uso: php operacion/evaluar_evidencias.php ruta/a/aceptacion.json']);
    $root = dirname(__DIR__);
    $manifestPath = __DIR__.'/CODIGO_SHA256.json';
    $manifest = readJson($manifestPath);
    if (!is_array($manifest['files'] ?? null) || count($manifest['files']) === 0
        || count(array_unique(array_column($manifest['files'], 'path'))) !== count($manifest['files'])) stopReport(['invalid_code_manifest']);
    foreach ($manifest['files'] as $row) {
        if (!is_string($row['path'] ?? null) || str_starts_with($row['path'], '/') || str_contains($row['path'], '\\') || in_array('..', explode('/', $row['path']), true)
            || !is_string($row['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $row['sha256'])) stopReport(['invalid_manifest_path']);
        $path = $root.'/'.$row['path'];
        if (!is_file($path) || !hash_equals($row['sha256'], hash_file('sha256', $path))) stopReport(['source_changed_since_release']);
    }
    $config = readJson($argv[1]);
    $version = readJson(__DIR__.'/VERSION.json');
    $errors = [];
    $target = $config['target'] ?? null;
    if (!in_array($target, ['qa', 'production'], true)) $errors[] = 'target_not_defined';
    if (!hash_equals(hash_file('sha256', $manifestPath), (string) ($config['code_manifest_sha256'] ?? ''))) $errors[] = 'acceptance_release_mismatch';
    $backend = junitCounts((string) ($config['backend_junit'] ?? ''));
    if ($backend['tests'] < $version['expected_backend_tests'] || $backend['failures'] || $backend['skipped']) $errors[] = 'backend_suite_incomplete_or_failed';
    $frontPath = (string) ($config['frontend_report'] ?? '');
    $front = readJson($frontPath);
    if (($front['all_passed'] ?? false) !== true || ($front['code_manifest_sha256'] ?? '') !== hash_file('sha256', $manifestPath)) $errors[] = 'frontend_release_or_result_mismatch';
    $names = [];
    foreach ($front['commands'] ?? [] as $command) {
        $names[] = $command['name'] ?? '';
        if (($command['exit_code'] ?? -1) !== 0) $errors[] = 'frontend_command_failed';
        $file = $command['stdout_file'] ?? '';
        if (!is_string($file) || basename($file) !== $file || !is_file(dirname($frontPath).'/'.$file)
            || !hash_equals((string) ($command['stdout_sha256'] ?? ''), hash_file('sha256', dirname($frontPath).'/'.$file))) $errors[] = 'frontend_log_missing_or_changed';
    }
    sort($names);
    if ($names !== ['build','lint','test']) $errors[] = 'frontend_commands_incomplete';
    $frontend = junitCounts(dirname($frontPath).'/frontend.xml');
    if ($frontend['tests'] < $version['expected_frontend_tests'] || $frontend['failures'] || $frontend['skipped']) $errors[] = 'frontend_suite_incomplete_or_failed';
    $required = ['private_document_access','effective_permissions','own_request_denied','native_concurrency','document_integrity','functional_acceptance','workspace_scope_isolation','workspace_invitation_consent','workspace_private_resources','workspace_lost_response_recovery'];
    if ($target === 'production') $required = [...$required,'https_and_sessions','smtp_received','scheduler_running','backup_restore','institutional_permissions','data_treatment_defined'];
    foreach ($required as $name) {
        $check = $config['checks'][$name] ?? [];
        if (($check['passed'] ?? false) !== true || trim((string) ($check['evidence'] ?? '')) === '') $errors[] = 'manual_evidence_pending:'.$name;
    }
    $scope = $config['voting_scope'] ?? [];
    if (!in_array($scope['decision'] ?? '', ['in_release','excluded_by_agreement'], true) || trim((string) ($scope['approval_reference'] ?? '')) === '') $errors[] = 'voting_scope_pending';
    if (($scope['decision'] ?? '') === 'in_release' && (($scope['verified'] ?? false) !== true || trim((string) ($scope['evidence'] ?? '')) === '')) $errors[] = 'voting_verification_pending';
    echo json_encode(['ready' => $errors === [], 'target' => $target, 'backend' => $backend, 'frontend' => $frontend,
        'errors' => array_values(array_unique($errors)), 'notice' => 'Se verifica coherencia y presencia de evidencias declaradas; no es una certificación independiente ni despliega servicios.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    exit($errors === [] ? 0 : 2);
} catch (Throwable $error) { stopReport(['evidence_read_or_parse_error', get_class($error)]); }
