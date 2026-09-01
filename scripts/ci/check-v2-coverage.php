<?php

declare(strict_types=1);

const COVERAGE_CONTRACT = 'resources/v2-coverage-contract.json';
const COVERAGE_OUTPUT = 'build/coverage/v2-coverage-summary.json';

$root = dirname(__DIR__, 2);
$arguments = parse_arguments(array_slice($_SERVER['argv'], 1));

if (isset($arguments['self-test'])) {
    self_test($root);
    exit(0);
}

$cloverArguments = $arguments['clover'] ?? ['coverage.xml'];
$contractArgument = $arguments['contract'] ?? COVERAGE_CONTRACT;
$outputArgument = $arguments['output'] ?? COVERAGE_OUTPUT;

if (! is_array($cloverArguments) || ! is_string($contractArgument) || ! is_string($outputArgument)) {
    fail('Coverage arguments are invalid.');
}

$clovers = array_map(static fn (string $path): string => resolve_path($root, $path), $cloverArguments);
$contractPath = resolve_path($root, $contractArgument);
$output = resolve_path($root, $outputArgument);
$contract = json_object($contractPath);
$summary = summarize_coverage($root, $clovers, $contract);

write_json($output, $summary);
write_step_summary($summary);

$totals = $summary['totals'];
$ratchet = $summary['ratchet'];
printf(
    "v2 line coverage: %s%% (%d/%d); accepted baseline: %s%%; target: %s%%.\n",
    $totals['line_coverage_percent'],
    $totals['covered_lines'],
    $totals['executable_lines'],
    basis_points_percent($ratchet['accepted_baseline_basis_points']),
    basis_points_percent($ratchet['target_basis_points']),
);
printf("Machine-readable coverage summary: %s\n", relative_path($output, $root));

if (! $ratchet['passes']) {
    fail(sprintf(
        'v2 line coverage regressed below the accepted %s%% baseline.',
        basis_points_percent($ratchet['accepted_baseline_basis_points']),
    ));
}

/**
 * @param list<string> $arguments
 * @return array<string, string|true|list<string>>
 */
function parse_arguments(array $arguments): array
{
    $parsed = [];

    foreach ($arguments as $argument) {
        if ($argument === '--self-test') {
            $parsed['self-test'] = true;

            continue;
        }

        if (preg_match('/^--(clover|contract|output)=(.+)$/', $argument, $matches) === 1) {
            if ($matches[1] === 'clover') {
                $parsed['clover'] ??= [];

                if (! is_array($parsed['clover'])) {
                    fail('Coverage Clover arguments are invalid.');
                }

                $parsed['clover'][] = $matches[2];
            } else {
                if (isset($parsed[$matches[1]])) {
                    fail("Coverage argument [--{$matches[1]}] may be provided only once.");
                }

                $parsed[$matches[1]] = $matches[2];
            }

            continue;
        }

        fail(
            'Usage: php scripts/ci/check-v2-coverage.php [--clover=path ...] [--contract=path] [--output=path] [--self-test]'
        );
    }

    return $parsed;
}

/**
 * @param array<string, mixed> $contract
 * @return array<string, mixed>
 */
function summarize_coverage(string $root, array $clovers, array $contract): array
{
    $measurement = require_object($contract, 'measurement');
    $source = require_object($measurement, 'source');
    $ratchet = require_object($contract, 'ratchet');
    $branch = require_string($contract, 'branch');
    $metric = require_string($measurement, 'metric');
    $testSuite = require_string($measurement, 'test_suite');
    $sourceRoot = require_string($source, 'root');
    $suffix = require_string($source, 'suffix');
    $excludedPaths = $source['excluded_paths'] ?? null;
    $acceptedBaseline = require_basis_points($ratchet, 'accepted_baseline_basis_points');
    $target = require_basis_points($ratchet, 'target_basis_points');

    if (($contract['schema_version'] ?? null) !== 1) {
        fail('Coverage contract schema_version must be 1.');
    }

    if ($branch !== 'v2' || $metric !== 'line' || $suffix !== '.php') {
        fail('Coverage contract must measure v2 PHP line coverage.');
    }

    if (! is_array($excludedPaths) || $excludedPaths !== []) {
        fail('Production source exclusions are not allowed in the v2 coverage contract.');
    }

    if ($acceptedBaseline > $target || $target !== 10_000) {
        fail('Coverage target must remain 100% and cannot be lower than the accepted baseline.');
    }

    $sourceDirectory = resolve_path($root, $sourceRoot);
    $inventory = source_inventory($sourceDirectory, $root, $suffix);
    $coveredFiles = clover_files($clovers, $root);
    $missingFiles = array_values(array_diff($inventory, array_keys($coveredFiles)));
    $unexpectedFiles = array_values(array_diff(array_keys($coveredFiles), $inventory));

    if ($missingFiles !== []) {
        fail('Clover report omits production source files: ' . implode(', ', $missingFiles));
    }

    if ($unexpectedFiles !== []) {
        fail('Clover report includes files outside the production source set: ' . implode(', ', $unexpectedFiles));
    }

    $components = [];
    $uncoveredPaths = [];
    $executableLines = 0;
    $coveredLines = 0;

    foreach ($coveredFiles as $path => $file) {
        $component = component_name($path);
        $components[$component] ??= [
            'name' => $component,
            'files' => 0,
            'executable_lines' => 0,
            'covered_lines' => 0,
            'uncovered_lines' => 0,
            'line_coverage_basis_points' => 10_000,
            'line_coverage_percent' => '100.00',
        ];
        $components[$component]['files']++;
        $components[$component]['executable_lines'] += $file['executable_lines'];
        $components[$component]['covered_lines'] += $file['covered_lines'];
        $components[$component]['uncovered_lines'] += count($file['uncovered_line_numbers']);
        $executableLines += $file['executable_lines'];
        $coveredLines += $file['covered_lines'];

        if ($file['uncovered_line_numbers'] !== []) {
            $uncoveredPaths[] = [
                'component' => $component,
                'path' => $path,
                'uncovered_line_numbers' => $file['uncovered_line_numbers'],
            ];
        }
    }

    if ($executableLines === 0) {
        fail('Clover report contains no executable production lines.');
    }

    ksort($components, SORT_STRING);
    foreach ($components as &$component) {
        $componentBasisPoints = coverage_basis_points($component['covered_lines'], $component['executable_lines']);
        $component['line_coverage_basis_points'] = $componentBasisPoints;
        $component['line_coverage_percent'] = basis_points_percent($componentBasisPoints);
    }
    unset($component);

    $basisPoints = coverage_basis_points($coveredLines, $executableLines);
    $commit = getenv('COVERAGE_COMMIT') ?: getenv('GITHUB_SHA');

    return [
        'schema_version' => 1,
        'branch' => $branch,
        'commit' => is_string($commit) && preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : null,
        'generated_at' => gmdate('c'),
        'measurement' => [
            'metric' => $metric,
            'test_suite' => $testSuite,
            'report_count' => count($clovers),
            'source' => [
                'root' => $sourceRoot,
                'suffix' => $suffix,
                'excluded_paths' => [],
                'file_count' => count($inventory),
                'inventory_sha256' => hash('sha256', implode("\n", $inventory) . "\n"),
            ],
        ],
        'totals' => [
            'executable_lines' => $executableLines,
            'covered_lines' => $coveredLines,
            'uncovered_lines' => $executableLines - $coveredLines,
            'line_coverage_basis_points' => $basisPoints,
            'line_coverage_percent' => basis_points_percent($basisPoints),
        ],
        'ratchet' => [
            'accepted_baseline_basis_points' => $acceptedBaseline,
            'target_basis_points' => $target,
            'passes' => $basisPoints >= $acceptedBaseline,
            'target_reached' => $basisPoints === $target,
        ],
        'components' => array_values($components),
        'uncovered_paths' => $uncoveredPaths,
    ];
}

/**
 * @return list<string>
 */
function source_inventory(string $directory, string $root, string $suffix): array
{
    if (! is_dir($directory)) {
        fail("Coverage source directory [{$directory}] does not exist.");
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory,
        FilesystemIterator::SKIP_DOTS,
    ));
    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), $suffix)) {
            continue;
        }

        $files[] = relative_path($file->getPathname(), $root);
    }

    sort($files, SORT_STRING);

    if ($files === []) {
        fail('Coverage source inventory is empty.');
    }

    return $files;
}

/**
 * @param list<string> $clovers
 * @return array<string, array{executable_lines: int, covered_lines: int, uncovered_line_numbers: list<int>}>
 */
function clover_files(array $clovers, string $root): array
{
    if ($clovers === []) {
        fail('At least one Clover report is required.');
    }

    $lineCounts = [];

    foreach ($clovers as $clover) {
        foreach (clover_line_counts($clover, $root) as $path => $lines) {
            $lineCounts[$path] ??= [];

            foreach ($lines as $number => $count) {
                $lineCounts[$path][$number] = max($lineCounts[$path][$number] ?? 0, $count);
            }
        }
    }

    $files = [];

    foreach ($lineCounts as $path => $lines) {
        ksort($lines, SORT_NUMERIC);
        $uncovered = [];
        $covered = 0;

        foreach ($lines as $number => $count) {
            if ($count > 0) {
                $covered++;
            } else {
                $uncovered[] = $number;
            }
        }

        $files[$path] = [
            'executable_lines' => count($lines),
            'covered_lines' => $covered,
            'uncovered_line_numbers' => $uncovered,
        ];
    }

    ksort($files, SORT_STRING);

    return $files;
}

/**
 * @return array<string, array<int, int>>
 */
function clover_line_counts(string $clover, string $root): array
{
    if (! is_file($clover)) {
        fail("Clover report [{$clover}] does not exist.");
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);

    try {
        $loaded = $document->load($clover, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if (! $loaded) {
        fail("Clover report [{$clover}] is not valid XML.");
    }

    $files = [];
    foreach ($document->getElementsByTagName('file') as $file) {
        if (! $file instanceof DOMElement) {
            continue;
        }

        $path = normalize_clover_path($file->getAttribute('name'), $root);
        $lines = [];

        foreach ($file->getElementsByTagName('line') as $line) {
            if (! $line instanceof DOMElement || $line->getAttribute('type') !== 'stmt') {
                continue;
            }

            $number = filter_var($line->getAttribute('num'), FILTER_VALIDATE_INT);
            $count = filter_var($line->getAttribute('count'), FILTER_VALIDATE_INT);

            if (! is_int($number) || $number <= 0 || ! is_int($count) || $count < 0) {
                fail("Clover report has invalid line metrics for [{$path}].");
            }

            if (isset($lines[$number])) {
                fail("Clover report contains duplicate executable line [{$path}:{$number}].");
            }

            $lines[$number] = $count;
        }

        if (isset($files[$path])) {
            fail("Clover report contains duplicate file [{$path}].");
        }

        ksort($lines, SORT_NUMERIC);
        $files[$path] = $lines;
    }

    ksort($files, SORT_STRING);

    return $files;
}

function normalize_clover_path(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

    if (str_starts_with($path, $normalizedRoot . '/')) {
        return substr($path, strlen($normalizedRoot) + 1);
    }

    if (! str_starts_with($path, '/')) {
        return ltrim($path, './');
    }

    $sourceOffset = strpos($path, '/src/');
    if ($sourceOffset !== false) {
        return substr($path, $sourceOffset + 1);
    }

    fail("Unable to map Clover path [{$path}] into the repository source set.");
}

function component_name(string $path): string
{
    $parts = explode('/', $path);

    if (($parts[1] ?? null) === 'V2') {
        $group = $parts[2] ?? 'runtime';
        if (str_ends_with($group, '.php')) {
            $group = 'runtime';
        }

        return 'v2/' . strtolower($group);
    }

    $group = $parts[1] ?? 'runtime';
    if (str_ends_with($group, '.php')) {
        return 'legacy/runtime';
    }

    return 'legacy/' . strtolower($group);
}

function coverage_basis_points(int $covered, int $executable): int
{
    return $executable === 0 ? 10_000 : intdiv($covered * 10_000, $executable);
}

function basis_points_percent(int $basisPoints): string
{
    return number_format($basisPoints / 100, 2, '.', '');
}

/**
 * @param array<string, mixed> $object
 * @return array<string, mixed>
 */
function require_object(array $object, string $key): array
{
    $value = $object[$key] ?? null;

    if (! is_array($value)) {
        fail("Coverage contract field [{$key}] must be an object.");
    }

    return $value;
}

/**
 * @param array<string, mixed> $object
 */
function require_string(array $object, string $key): string
{
    $value = $object[$key] ?? null;

    if (! is_string($value) || $value === '') {
        fail("Coverage contract field [{$key}] must be a non-empty string.");
    }

    return $value;
}

/**
 * @param array<string, mixed> $object
 */
function require_basis_points(array $object, string $key): int
{
    $value = $object[$key] ?? null;

    if (! is_int($value) || $value < 0 || $value > 10_000) {
        fail("Coverage contract field [{$key}] must be an integer from 0 through 10000.");
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function json_object(string $path): array
{
    $contents = @file_get_contents($path);
    if (! is_string($contents)) {
        fail("Unable to read JSON file [{$path}].");
    }

    try {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Invalid JSON file [{$path}]: {$exception->getMessage()}");
    }

    if (! is_array($decoded) || array_is_list($decoded)) {
        fail("JSON file [{$path}] must contain an object.");
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $contents
 */
function write_json(string $path, array $contents): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fail("Unable to create output directory [{$directory}].");
    }

    $encoded = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $encoded) === false) {
        fail("Unable to write coverage summary [{$path}].");
    }
}

/**
 * @param array<string, mixed> $summary
 */
function write_step_summary(array $summary): void
{
    $path = getenv('GITHUB_STEP_SUMMARY');
    if (! is_string($path) || $path === '') {
        return;
    }

    $totals = $summary['totals'];
    $ratchet = $summary['ratchet'];
    $report = sprintf(
        "## v2 line coverage\n\n- Current: %s%% (%d/%d executable lines)\n- Accepted baseline: %s%%\n- Target: %s%%\n- Ratchet: %s\n\n",
        $totals['line_coverage_percent'],
        $totals['covered_lines'],
        $totals['executable_lines'],
        basis_points_percent($ratchet['accepted_baseline_basis_points']),
        basis_points_percent($ratchet['target_basis_points']),
        $ratchet['passes'] ? 'pass' : 'fail',
    );

    file_put_contents($path, $report, FILE_APPEND);
}

function resolve_path(string $root, string $path): string
{
    return str_starts_with($path, '/') ? $path : $root . '/' . $path;
}

function relative_path(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');

    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

function self_test(string $root): void
{
    $fixture = $root . '/build/v2-coverage-self-test-' . getmypid();
    $source = $fixture . '/src/V2/Support';
    mkdir($source, 0777, true);
    file_put_contents($source . '/Example.php', "<?php\nreturn true;\n");

    $clover = $fixture . '/coverage.xml';
    file_put_contents($clover, sprintf(
        '<?xml version="1.0"?><coverage><project><file name="%s"><line num="2" type="stmt" count="1"/><line num="3" type="stmt" count="0"/></file></project></coverage>',
        htmlspecialchars($source . '/Example.php', ENT_XML1),
    ));
    $featureClover = $fixture . '/feature-coverage.xml';
    file_put_contents($featureClover, sprintf(
        '<?xml version="1.0"?><coverage><project><file name="%s"><line num="2" type="stmt" count="0"/><line num="3" type="stmt" count="1"/></file></project></coverage>',
        htmlspecialchars($source . '/Example.php', ENT_XML1),
    ));

    $contract = [
        'schema_version' => 1,
        'branch' => 'v2',
        'measurement' => [
            'metric' => 'line',
            'test_suite' => 'unit',
            'source' => [
                'root' => 'src',
                'suffix' => '.php',
                'excluded_paths' => [],
            ],
        ],
        'ratchet' => [
            'accepted_baseline_basis_points' => 5000,
            'target_basis_points' => 10_000,
        ],
    ];

    try {
        $summary = summarize_coverage($fixture, [$clover], $contract);
        assert_self_test($summary['totals']['line_coverage_basis_points'] === 5000, 'calculates line coverage');
        assert_self_test($summary['ratchet']['passes'] === true, 'accepts coverage at the baseline');
        assert_self_test($summary['components'][0]['name'] === 'v2/support', 'groups uncovered paths by component');
        assert_self_test(
            $summary['uncovered_paths'][0]['uncovered_line_numbers'] === [3],
            'reports uncovered executable lines',
        );
        $combined = summarize_coverage($fixture, [$clover, $featureClover], $contract);
        assert_self_test($combined['totals']['line_coverage_basis_points'] === 10_000, 'unions line coverage reports');
        assert_self_test($combined['measurement']['report_count'] === 2, 'reports merged coverage provenance');

        $contract['ratchet']['accepted_baseline_basis_points'] = 5001;
        $summary = summarize_coverage($fixture, [$clover], $contract);
        assert_self_test($summary['ratchet']['passes'] === false, 'rejects a coverage regression');

        file_put_contents($fixture . '/src/V2/Support/Missing.php', "<?php\nreturn false;\n");
        try {
            summarize_coverage($fixture, [$clover], $contract);
            fail('Coverage self-test did not reject an omitted production source file.');
        } catch (RuntimeException $exception) {
            assert_self_test(
                str_contains($exception->getMessage(), 'omits production source files'),
                'rejects source-set exclusions',
            );
        }
    } finally {
        remove_tree($fixture);
    }

    fwrite(STDOUT, "v2 coverage ratchet self-test passed.\n");
}

function assert_self_test(bool $condition, string $description): void
{
    if (! $condition) {
        fail("Coverage self-test failed: {$description}.");
    }
}

function remove_tree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }

    rmdir($directory);
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}
