<?php

declare(strict_types=1);

$options = getopt('', ['dir::', 'shard:', 'shards:', 'weights::', 'summary::']);

$dir = normalize_path((string) ($options['dir'] ?? 'tests/Feature'));
$shard = parse_int_option($options, 'shard');
$shards = parse_int_option($options, 'shards');
$weightsFile = isset($options['weights']) ? (string) $options['weights'] : null;
$summaryFile = isset($options['summary']) ? (string) $options['summary'] : null;

if ($shards < 1) {
    fail('--shards must be greater than zero.');
}

if ($shard < 0 || $shard >= $shards) {
    fail('--shard must be between 0 and --shards minus one.');
}

if (! is_dir($dir)) {
    fail("Test directory [{$dir}] does not exist.");
}

$weights = $weightsFile !== null ? load_weights($weightsFile) : [];
$files = find_test_files($dir);

if ($files === []) {
    fail("No test files found under [{$dir}].");
}

$assignments = assign_weighted_shards($files, $weights, $shards);
$selected = $assignments[$shard]['files'];
sort($selected, SORT_STRING);

foreach ($selected as $file) {
    echo $file . PHP_EOL;
}

if ($summaryFile !== null) {
    write_summary($summaryFile, $assignments, $weights);
}

/**
 * @param array<string, mixed> $options
 */
function parse_int_option(array $options, string $name): int
{
    if (! isset($options[$name]) || ! is_scalar($options[$name]) || $options[$name] === '') {
        fail("--{$name} is required.");
    }

    if (! preg_match('/^\d+$/', (string) $options[$name])) {
        fail("--{$name} must be a non-negative integer.");
    }

    return (int) $options[$name];
}

function normalize_path(string $path): string
{
    return str_replace('\\', '/', rtrim($path, '/'));
}

/**
 * @return array<string, float>
 */
function load_weights(string $path): array
{
    if (! is_file($path)) {
        fail("Weights file [{$path}] does not exist.");
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        fail("Weights file [{$path}] must contain a JSON object.");
    }

    $weights = [];

    foreach ($decoded as $file => $weight) {
        if (! is_string($file) || (! is_int($weight) && ! is_float($weight))) {
            fail("Weights file [{$path}] must map test file paths to numeric weights.");
        }

        if ($weight <= 0) {
            fail("Weight for [{$file}] must be greater than zero.");
        }

        $weights[normalize_path($file)] = (float) $weight;
    }

    return $weights;
}

/**
 * @return list<string>
 */
function find_test_files(string $dir): array
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $path = normalize_path($file->getPathname());

        if (str_ends_with($path, 'Test.php')) {
            $files[] = $path;
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * @param list<string> $files
 * @param array<string, float> $weights
 * @return list<array{files: list<string>, weight: float}>
 */
function assign_weighted_shards(array $files, array $weights, int $shards): array
{
    $weightedFiles = [];

    foreach ($files as $file) {
        $weightedFiles[] = [
            'file' => $file,
            'weight' => $weights[$file] ?? 1.0,
        ];
    }

    usort(
        $weightedFiles,
        static fn (array $a, array $b): int => ($b['weight'] <=> $a['weight'])
            ?: strcmp($a['file'], $b['file'])
    );

    $assignments = array_fill(0, $shards, null);

    for ($i = 0; $i < $shards; $i++) {
        $assignments[$i] = [
            'files' => [],
            'weight' => 0.0,
        ];
    }

    foreach ($weightedFiles as $weightedFile) {
        $target = 0;

        for ($i = 1; $i < $shards; $i++) {
            if ($assignments[$i]['weight'] < $assignments[$target]['weight']) {
                $target = $i;

                continue;
            }

            if (
                $assignments[$i]['weight'] === $assignments[$target]['weight']
                && count($assignments[$i]['files']) < count($assignments[$target]['files'])
            ) {
                $target = $i;
            }
        }

        $assignments[$target]['files'][] = $weightedFile['file'];
        $assignments[$target]['weight'] += $weightedFile['weight'];
    }

    return $assignments;
}

/**
 * @param list<array{files: list<string>, weight: float}> $assignments
 * @param array<string, float> $weights
 */
function write_summary(string $path, array $assignments, array $weights): void
{
    $rows = [];

    foreach ($assignments as $index => $assignment) {
        $rows[] = sprintf(
            'shard=%d files=%d estimated_weight=%.3f',
            $index,
            count($assignment['files']),
            $assignment['weight']
        );

        $files = $assignment['files'];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $rows[] = sprintf('  %.3f %s', $weights[$file] ?? 1.0, $file);
        }
    }

    file_put_contents($path, implode(PHP_EOL, $rows) . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);

    exit(1);
}
