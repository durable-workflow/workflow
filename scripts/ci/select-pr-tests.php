<?php

declare(strict_types=1);

$options = getopt('', ['config:', 'weights:', 'weight-profile:', 'output-dir:', 'changed-files::']);

$configPath = parse_path_option($options, 'config');
$weightsPath = parse_path_option($options, 'weights');
$weightProfile = parse_string_option($options, 'weight-profile');
$outputDir = normalize_path(parse_path_option($options, 'output-dir'));
$changedFilesPath = isset($options['changed-files']) && is_scalar($options['changed-files'])
    ? (string) $options['changed-files']
    : null;

$config = decode_json_object($configPath);
$weightsDocument = decode_json_object($weightsPath);
$weights = $weightsDocument[$weightProfile] ?? null;

if (! is_array($weights)) {
    fail("Weights file [{$weightsPath}] does not contain profile [{$weightProfile}].");
}

$budget = positive_number($config, 'changed_feature_budget_seconds');
$unknownWeight = positive_number($config, 'unknown_feature_weight_seconds');
$unitContracts = string_list($config, 'unit_contracts');
$smokeTests = smoke_tests($config);

foreach ($unitContracts as $file) {
    assert_test_file($file, 'tests/Unit/');

    $contents = (string) file_get_contents($file);

    if (
        ! str_contains($contents, 'use PHPUnit\\Framework\\TestCase;')
        || str_contains($contents, 'use Tests\\TestCase;')
    ) {
        fail("Unit contract [{$file}] must extend PHPUnit's database-independent TestCase directly.");
    }
}

$requiredBehaviors = [
    'durable-dispatch',
    'signal-update-order',
    'query-replay',
    'persisted-payload',
];
$actualBehaviors = array_column($smokeTests, 'behavior');
sort($actualBehaviors, SORT_STRING);
$sortedRequiredBehaviors = $requiredBehaviors;
sort($sortedRequiredBehaviors, SORT_STRING);

if ($actualBehaviors !== $sortedRequiredBehaviors) {
    fail('The MySQL smoke selection must contain each required behavior exactly once.');
}

$fallbackFiles = [];
$fallbackTests = [];

foreach ($smokeTests as $smokeTest) {
    assert_test_file($smokeTest['file'], 'tests/Feature/');

    if (! preg_match('/^test[A-Za-z0-9_]+$/', $smokeTest['test'])) {
        fail("Invalid PHPUnit test method [{$smokeTest['test']}].");
    }

    $contents = (string) file_get_contents($smokeTest['file']);

    if (! preg_match('/function\s+' . preg_quote($smokeTest['test'], '/') . '\s*\(/', $contents)) {
        fail("Smoke method [{$smokeTest['test']}] does not exist in [{$smokeTest['file']}].");
    }

    $fallbackFiles[$smokeTest['file']] = true;
    $fallbackTests[] = $smokeTest['test'];
}

$changedFeatureFiles = changed_feature_files($changedFilesPath);
$candidates = [];

foreach ($changedFeatureFiles as $file) {
    $weight = $weights[$file] ?? $unknownWeight;

    if ((! is_int($weight) && ! is_float($weight)) || $weight <= 0) {
        fail("Weight for [{$file}] must be a positive number.");
    }

    $candidates[] = [
        'file' => $file,
        'weight' => (float) $weight,
        'profiled' => array_key_exists($file, $weights),
    ];
}

usort(
    $candidates,
    static fn (array $a, array $b): int => ($a['weight'] <=> $b['weight'])
        ?: strcmp($a['file'], $b['file'])
);

$selected = [];
$omitted = [];
$selectedWeight = 0.0;

foreach ($candidates as $candidate) {
    if ($selectedWeight + $candidate['weight'] <= $budget) {
        $selected[] = $candidate;
        $selectedWeight += $candidate['weight'];

        continue;
    }

    $omitted[] = $candidate;
}

if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir)) {
    fail("Unable to create output directory [{$outputDir}].");
}

write_lines("{$outputDir}/unit-files.txt", $unitContracts);
write_lines("{$outputDir}/mysql-fallback-files.txt", array_keys($fallbackFiles));
write_lines("{$outputDir}/mysql-changed-files.txt", array_column($selected, 'file'));
write_lines(
    "{$outputDir}/mysql-fallback-filter.txt",
    [
        '/(?:^|::)(?:'
        . implode('|', array_map(static fn (string $test): string => preg_quote($test, '/'), $fallbackTests))
        . ')(?: with data set .*)?$/',
    ]
);

$summary = [
    'selection=high-signal-fallback+bounded-changed-feature-tests',
    sprintf('unit_contract_files=%d', count($unitContracts)),
    sprintf('changed_feature_budget_seconds=%.3f', $budget),
    sprintf('changed_feature_selected_seconds=%.3f', $selectedWeight),
    sprintf('changed_feature_candidates=%d', count($candidates)),
    sprintf('changed_feature_selected=%d', count($selected)),
    sprintf('changed_feature_omitted=%d', count($omitted)),
    'fallback:',
];

foreach ($smokeTests as $smokeTest) {
    $summary[] = sprintf(
        '  %s %s::%s',
        $smokeTest['behavior'],
        $smokeTest['file'],
        $smokeTest['test']
    );
}

$summary[] = 'changed-feature-selected:';

foreach ($selected as $candidate) {
    $summary[] = sprintf(
        '  %.3f%s %s',
        $candidate['weight'],
        $candidate['profiled'] ? '' : ' (conservative-default)',
        $candidate['file']
    );
}

$summary[] = 'changed-feature-omitted:';

foreach ($omitted as $candidate) {
    $summary[] = sprintf(
        '  %.3f%s %s',
        $candidate['weight'],
        $candidate['profiled'] ? '' : ' (conservative-default)',
        $candidate['file']
    );
}

write_lines("{$outputDir}/summary.txt", $summary);

/**
 * @param array<string, mixed> $options
 */
function parse_path_option(array $options, string $name): string
{
    $value = parse_string_option($options, $name);

    if ($value === '') {
        fail("--{$name} must not be empty.");
    }

    return $value;
}

/**
 * @param array<string, mixed> $options
 */
function parse_string_option(array $options, string $name): string
{
    if (! isset($options[$name]) || ! is_scalar($options[$name])) {
        fail("--{$name} is required.");
    }

    return (string) $options[$name];
}

/**
 * @return array<string, mixed>
 */
function decode_json_object(string $path): array
{
    if (! is_file($path)) {
        fail("JSON file [{$path}] does not exist.");
    }

    try {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Unable to decode JSON file [{$path}]: {$exception->getMessage()}");
    }

    if (! is_array($decoded) || array_is_list($decoded)) {
        fail("JSON file [{$path}] must contain an object.");
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $config
 */
function positive_number(array $config, string $key): float
{
    $value = $config[$key] ?? null;

    if ((! is_int($value) && ! is_float($value)) || $value <= 0) {
        fail("Configuration value [{$key}] must be a positive number.");
    }

    return (float) $value;
}

/**
 * @param array<string, mixed> $config
 * @return list<string>
 */
function string_list(array $config, string $key): array
{
    $value = $config[$key] ?? null;

    if (! is_array($value) || $value === []) {
        fail("Configuration value [{$key}] must be a non-empty list.");
    }

    foreach ($value as $item) {
        if (! is_string($item) || $item === '') {
            fail("Configuration value [{$key}] must contain only non-empty strings.");
        }
    }

    return array_values(array_unique($value));
}

/**
 * @param array<string, mixed> $config
 * @return list<array{behavior: string, file: string, test: string}>
 */
function smoke_tests(array $config): array
{
    $value = $config['mysql_smoke'] ?? null;

    if (! is_array($value) || $value === []) {
        fail('Configuration value [mysql_smoke] must be a non-empty list.');
    }

    $tests = [];

    foreach ($value as $item) {
        if (! is_array($item)) {
            fail('Each MySQL smoke entry must be an object.');
        }

        $behavior = $item['behavior'] ?? null;
        $file = $item['file'] ?? null;
        $test = $item['test'] ?? null;

        if (! is_string($behavior) || ! is_string($file) || ! is_string($test)) {
            fail('Each MySQL smoke entry must contain string behavior, file, and test values.');
        }

        $tests[] = compact('behavior', 'file', 'test');
    }

    return $tests;
}

function assert_test_file(string $file, string $prefix): void
{
    $normalized = normalize_path($file);

    if ($normalized !== $file || ! str_starts_with($file, $prefix) || ! str_ends_with($file, 'Test.php')) {
        fail("Test file [{$file}] is outside the expected [{$prefix}] test surface.");
    }

    if (! is_file($file)) {
        fail("Test file [{$file}] does not exist.");
    }
}

/**
 * @return list<string>
 */
function changed_feature_files(?string $path): array
{
    if ($path === null || $path === '' || ! is_file($path)) {
        return [];
    }

    $files = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $file) {
        $file = normalize_path(trim($file));

        if (
            str_starts_with($file, 'tests/Feature/')
            && str_ends_with($file, 'Test.php')
            && is_file($file)
        ) {
            $files[$file] = true;
        }
    }

    $files = array_keys($files);
    sort($files, SORT_STRING);

    return $files;
}

function normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * @param list<string> $lines
 */
function write_lines(string $path, array $lines): void
{
    $contents = $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;

    if (file_put_contents($path, $contents) === false) {
        fail("Unable to write [{$path}].");
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);

    exit(1);
}
