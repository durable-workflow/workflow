<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/ci/select-feature-shard.php mysql|postgresql SHARD SHARD_COUNT\n");
    exit(2);
}

$database = $argv[1];
$shard = filter_var($argv[2], FILTER_VALIDATE_INT);
$shardCount = filter_var($argv[3], FILTER_VALIDATE_INT);

$hasValidDatabase = in_array($database, ['mysql', 'postgresql'], true);
$hasValidShard = $shard !== false && $shardCount !== false && $shardCount >= 1 && $shard >= 0 && $shard < $shardCount;

if (! $hasValidDatabase || ! $hasValidShard) {
    fwrite(STDERR, "Invalid arguments. Expected mysql|postgresql, a zero-based shard, and a positive shard count.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$timingsPath = $root . '/.github/feature-test-timings.json';
$timings = [];

if (is_file($timingsPath)) {
    $decoded = json_decode((string) file_get_contents($timingsPath), true, 512, JSON_THROW_ON_ERROR);
    if (is_array($decoded[$database] ?? null)) {
        $timings = $decoded[$database];
    }
}

$files = discoverFeatureTests($root . '/tests/Feature');
$defaultWeight = defaultWeight($timings);
$weightedFiles = array_map(
    static fn (string $file): array => [
        'file' => $file,
        'weight' => (float) ($timings[$file] ?? $defaultWeight),
    ],
    $files,
);

usort($weightedFiles, static function (array $left, array $right): int {
    $byWeight = $right['weight'] <=> $left['weight'];

    return $byWeight !== 0 ? $byWeight : strcmp($left['file'], $right['file']);
});

$assignments = array_fill(0, $shardCount, []);
$loads = array_fill(0, $shardCount, 0.0);

foreach ($weightedFiles as $weightedFile) {
    $target = 0;

    for ($index = 1; $index < $shardCount; $index++) {
        $hasLowerLoad = $loads[$index] < $loads[$target];
        $hasSameLoadWithFewerFiles = $loads[$index] === $loads[$target]
            && count($assignments[$index]) < count($assignments[$target]);

        if ($hasLowerLoad || $hasSameLoadWithFewerFiles) {
            $target = $index;
        }
    }

    $assignments[$target][] = $weightedFile['file'];
    $loads[$target] += $weightedFile['weight'];
}

$selected = $assignments[$shard];
sort($selected, SORT_STRING);

fwrite(STDERR, sprintf(
    "%s feature shard %d/%d selected %d files with %.2fs estimated weight\n",
    $database,
    $shard,
    $shardCount,
    count($selected),
    $loads[$shard],
));

echo implode(PHP_EOL, $selected);
echo PHP_EOL;

/**
 * @return list<string>
 */
function discoverFeatureTests(string $directory): array
{
    $files = [];
    $directoryIterator = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directoryIterator);

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        $files[] = normalizePath($file->getPathname());
    }

    sort($files, SORT_STRING);

    return $files;
}

function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $marker = '/tests/Feature/';
    $position = strpos($path, $marker);

    if ($position === false) {
        return $path;
    }

    return 'tests/Feature/' . substr($path, $position + strlen($marker));
}

/**
 * @param array<string, mixed> $timings
 */
function defaultWeight(array $timings): float
{
    $weights = array_values(array_filter(
        array_map(static fn (mixed $weight): float => is_numeric($weight) ? (float) $weight : 0.0, $timings),
        static fn (float $weight): bool => $weight > 0.0,
    ));

    if ($weights === []) {
        return 10.0;
    }

    sort($weights, SORT_NUMERIC);

    return $weights[(int) floor(count($weights) / 2)];
}
