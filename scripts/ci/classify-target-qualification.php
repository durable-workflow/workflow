<?php

declare(strict_types=1);

const QUALIFICATION_FULL = 'full';
const QUALIFICATION_FOCUSED = 'release-recovery';
const QUALIFICATION_SCHEMA = 'durable-workflow.target-qualification/v1';
const PROTECTED_SERVER_URL = 'https://github.com';
const PROTECTED_EVENT = 'push';
const PROTECTED_REF = 'refs/heads/v2';

$options = getopt('', [
    'server-url:',
    'event-name:',
    'ref:',
    'before:',
    'head:',
    'paths-file:',
    'config:',
    'self-test',
]);

$configPath = optional_option($options, 'config');
$configPath = $configPath !== '' ? $configPath : '.github/target-qualification.json';

if (isset($options['self-test'])) {
    self_test(load_contract($configPath));
    exit(0);
}

try {
    $contract = load_contract($configPath);
} catch (Throwable) {
    emit_result(QUALIFICATION_FULL, 'invalid-classification-contract', [], 0, 0);
    exit(0);
}

$context = [
    'server_url' => optional_option($options, 'server-url'),
    'event_name' => optional_option($options, 'event-name'),
    'ref' => optional_option($options, 'ref'),
];

$pathsFile = optional_option($options, 'paths-file');

if ($pathsFile !== '') {
    $paths = read_paths_file($pathsFile);
    [$qualification, $reason, $categories] = classify_paths($context, $paths, $contract);
    emit_result(
        $qualification,
        $reason,
        $categories,
        $contract['baseline_run_id'],
        $contract['baseline_elapsed_seconds'],
    );
    exit(0);
}

$before = optional_option($options, 'before');
$head = optional_option($options, 'head');

if (! is_unambiguous_revision($before) || ! is_unambiguous_revision($head) || is_zero_revision($before)) {
    emit_contract_result(QUALIFICATION_FULL, 'ambiguous-revision-range', [], $contract);
    exit(0);
}

[$ancestorStatus] = run_process(['git', 'merge-base', '--is-ancestor', $before, $head]);

if ($ancestorStatus !== 0) {
    emit_contract_result(QUALIFICATION_FULL, 'unavailable-revision-range', [], $contract);
    exit(0);
}

[$diffStatus, $diffOutput] = run_process([
    'git',
    'diff',
    '--name-only',
    '-z',
    '--no-renames',
    '--diff-filter=ACDMRTUXB',
    $before,
    $head,
    '--',
]);

if ($diffStatus !== 0) {
    emit_contract_result(QUALIFICATION_FULL, 'unavailable-changed-paths', [], $contract);
    exit(0);
}

$paths = $diffOutput === ''
    ? []
    : explode("\0", rtrim($diffOutput, "\0"));

[$qualification, $reason, $categories] = classify_paths($context, $paths, $contract);
emit_contract_result($qualification, $reason, $categories, $contract);

/**
 * @return array{
 *     paths: array<string, string>,
 *     baseline_run_id: int,
 *     baseline_elapsed_seconds: int
 * }
 */
function load_contract(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read qualification contract at {$path}.");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded) || ($decoded['schema'] ?? null) !== QUALIFICATION_SCHEMA) {
        throw new RuntimeException('Qualification contract has an unsupported schema.');
    }

    if (($decoded['focused_class'] ?? null) !== QUALIFICATION_FOCUSED) {
        throw new RuntimeException('Qualification contract has an unsupported focused class.');
    }

    $baseline = $decoded['baseline'] ?? null;

    if (
        ! is_array($baseline)
        || ! is_int($baseline['run_id'] ?? null)
        || $baseline['run_id'] <= 0
        || ! is_int($baseline['elapsed_seconds'] ?? null)
        || $baseline['elapsed_seconds'] <= 0
    ) {
        throw new RuntimeException('Qualification contract has an invalid baseline.');
    }

    $categories = $decoded['path_categories'] ?? null;

    if (! is_array($categories) || $categories === []) {
        throw new RuntimeException('Qualification contract has no path categories.');
    }

    $paths = [];

    foreach ($categories as $category => $categoryPaths) {
        if (
            ! is_string($category)
            || preg_match('/^[a-z][a-z0-9-]*$/', $category) !== 1
            || ! is_array($categoryPaths)
            || $categoryPaths === []
        ) {
            throw new RuntimeException('Qualification contract has an invalid path category.');
        }

        foreach ($categoryPaths as $candidatePath) {
            if (! is_safe_relative_path($candidatePath) || isset($paths[$candidatePath])) {
                throw new RuntimeException('Qualification contract has an invalid or duplicate path.');
            }

            $paths[$candidatePath] = $category;
        }
    }

    ksort($paths);

    return [
        'paths' => $paths,
        'baseline_run_id' => $baseline['run_id'],
        'baseline_elapsed_seconds' => $baseline['elapsed_seconds'],
    ];
}

/**
 * @param array{server_url: string, event_name: string, ref: string} $context
 * @param list<string> $paths
 * @param array{
 *     paths: array<string, string>,
 *     baseline_run_id: int,
 *     baseline_elapsed_seconds: int
 * } $contract
 * @return array{string, string, list<string>}
 */
function classify_paths(array $context, array $paths, array $contract): array
{
    if (
        $context['server_url'] !== PROTECTED_SERVER_URL
        || $context['event_name'] !== PROTECTED_EVENT
        || $context['ref'] !== PROTECTED_REF
    ) {
        return [QUALIFICATION_FULL, 'not-protected-target-push', []];
    }

    if ($paths === []) {
        return [QUALIFICATION_FULL, 'empty-changed-path-set', []];
    }

    $categories = [];

    foreach ($paths as $path) {
        if (! is_safe_relative_path($path) || ! isset($contract['paths'][$path])) {
            return [QUALIFICATION_FULL, 'path-outside-focused-allowlist', []];
        }

        $categories[$contract['paths'][$path]] = true;
    }

    $selectedCategories = array_keys($categories);
    sort($selectedCategories);

    return [QUALIFICATION_FOCUSED, 'allowlisted-release-recovery-change', $selectedCategories];
}

function is_safe_relative_path(mixed $path): bool
{
    return is_string($path)
        && $path !== ''
        && $path[0] !== '/'
        && ! str_contains($path, '\\')
        && ! str_contains($path, "\0")
        && ! str_contains($path, "\n")
        && ! str_contains($path, "\r")
        && ! in_array('..', explode('/', $path), true);
}

function is_unambiguous_revision(string $revision): bool
{
    return preg_match('/^[0-9a-f]{40}$/', $revision) === 1;
}

function is_zero_revision(string $revision): bool
{
    return trim($revision, '0') === '';
}

/**
 * @return list<string>
 */
function read_paths_file(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        return [];
    }

    $paths = preg_split('/\r\n|\r|\n/', rtrim($contents, "\r\n"));

    return is_array($paths) ? array_values(array_filter($paths, static fn (string $item): bool => $item !== '')) : [];
}

/**
 * @param list<string> $command
 * @return array{int, string, string}
 */
function run_process(array $command): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        return [1, '', 'unable to start process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        proc_close($process),
        $stdout === false ? '' : $stdout,
        $stderr === false ? '' : $stderr,
    ];
}

/**
 * @param list<string> $categories
 * @param array{
 *     paths: array<string, string>,
 *     baseline_run_id: int,
 *     baseline_elapsed_seconds: int
 * } $contract
 */
function emit_contract_result(
    string $qualification,
    string $reason,
    array $categories,
    array $contract,
): void {
    emit_result(
        $qualification,
        $reason,
        $categories,
        $contract['baseline_run_id'],
        $contract['baseline_elapsed_seconds'],
    );
}

/**
 * @param list<string> $categories
 */
function emit_result(
    string $qualification,
    string $reason,
    array $categories,
    int $baselineRunId,
    int $baselineElapsedSeconds,
): void {
    printf("qualification=%s\n", $qualification);
    printf("qualification_reason=%s\n", $reason);
    printf("changed_path_categories=%s\n", implode(',', $categories));
    printf("baseline_run_id=%d\n", $baselineRunId);
    printf("baseline_elapsed_seconds=%d\n", $baselineElapsedSeconds);
}

/**
 * @param array<string, mixed> $options
 */
function optional_option(array $options, string $name): string
{
    if (! array_key_exists($name, $options) || $options[$name] === false) {
        return '';
    }

    if (! is_scalar($options[$name])) {
        return '';
    }

    return (string) $options[$name];
}

/**
 * @param array{
 *     paths: array<string, string>,
 *     baseline_run_id: int,
 *     baseline_elapsed_seconds: int
 * } $contract
 */
function self_test(array $contract): void
{
    $protectedContext = [
        'server_url' => PROTECTED_SERVER_URL,
        'event_name' => PROTECTED_EVENT,
        'ref' => PROTECTED_REF,
    ];

    foreach ($contract['paths'] as $path => $category) {
        [$qualification, , $categories] = classify_paths($protectedContext, [$path], $contract);

        if ($qualification !== QUALIFICATION_FOCUSED || $categories !== [$category]) {
            fail("Focused classification self-test failed for {$path}.");
        }
    }

    $fullPaths = [
        'composer.json',
        'src/V2/Support/LocalActivityRuntime.php',
        'src/migrations/2022_01_01_000000_create_workflows_table.php',
        'tests/Feature/V2/V2WorkflowTest.php',
        'tests/Unit/Serializers/SerializeTest.php',
        '.github/workflows/php.yml',
        '.github/target-qualification.json',
        'scripts/ci/classify-target-qualification.php',
    ];

    foreach ($fullPaths as $path) {
        [$qualification] = classify_paths($protectedContext, [$path], $contract);

        if ($qualification !== QUALIFICATION_FULL) {
            fail("Full classification self-test failed for {$path}.");
        }
    }

    $focusedPath = array_key_first($contract['paths']);
    [$mixedQualification] = classify_paths(
        $protectedContext,
        [$focusedPath, 'src/V2/Support/LocalActivityRuntime.php'],
        $contract,
    );

    if ($mixedQualification !== QUALIFICATION_FULL) {
        fail('Mixed classification self-test failed.');
    }

    foreach (['server_url', 'event_name', 'ref'] as $contextKey) {
        $ambiguousContext = $protectedContext;
        $ambiguousContext[$contextKey] = '';
        [$qualification] = classify_paths($ambiguousContext, [$focusedPath], $contract);

        if ($qualification !== QUALIFICATION_FULL) {
            fail("Ambiguous {$contextKey} classification self-test failed.");
        }
    }

    printf(
        "Target qualification self-test passed (%d focused paths, %d full paths).\n",
        count($contract['paths']),
        count($fullPaths),
    );
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
