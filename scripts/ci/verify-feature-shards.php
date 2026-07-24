<?php

declare(strict_types=1);

const FEATURE_SHARDS = 4;

$root = dirname(__DIR__, 2);
$workflow = $root . '/.github/workflows/php.yml';
$publicBoundaryWorkflow = $root . '/.github/workflows/public-boundary.yml';
$weightsFile = $root . '/.github/feature-test-timings.json';
$splitter = $root . '/scripts/ci/split-feature-tests.php';
$featureDirectory = $root . '/tests/Feature';
$profiles = [
    'mysql' => 'feature-mysql',
    'postgresql' => 'feature-postgresql',
];

$expectedFiles = feature_files($featureDirectory, $root);
$weights = json_file($weightsFile);
$workflowContents = read_file($workflow);

verify_gate_wiring($workflowContents, read_file($publicBoundaryWorkflow));

foreach ($profiles as $profile => $job) {
    verify_workflow_job($workflowContents, $job, $profile);
    verify_weight_inventory($weights, $profile, $expectedFiles);
    verify_split_inventory($splitter, $featureDirectory, $weightsFile, $profile, $expectedFiles, $root);
}

printf(
    "Feature shard verification passed: %d files represented exactly once across %d shards for %s.\n",
    count($expectedFiles),
    FEATURE_SHARDS,
    implode(' and ', array_keys($profiles)),
);

/**
 * @return list<string>
 */
function feature_files(string $directory, string $root): array
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory,
        FilesystemIterator::SKIP_DOTS
    ));
    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        $files[] = relative_path($file->getPathname(), $root);
    }

    sort($files, SORT_STRING);

    if ($files === []) {
        fail('No feature test files were found.');
    }

    return $files;
}

/**
 * @return array<string, mixed>
 */
function json_file(string $path): array
{
    $decoded = json_decode(read_file($path), true);

    if (! is_array($decoded)) {
        fail("JSON file [{$path}] must contain an object.");
    }

    return $decoded;
}

function read_file(string $path): string
{
    $contents = @file_get_contents($path);

    if (! is_string($contents)) {
        fail("Unable to read [{$path}].");
    }

    return $contents;
}

function verify_workflow_job(string $workflow, string $job, string $profile): void
{
    $jobDefinition = workflow_job_definition($workflow, $job);
    $expectedMatrix = implode(', ', range(0, FEATURE_SHARDS - 1));
    $requirements = [
        'complete-gate routing' => "needs.route.outputs.route == 'complete'",
        'full qualification routing' => "needs.route.outputs.qualification == 'full'",
        'complete shard matrix' => "shard: [{$expectedMatrix}]",
        'splitter shard count' => '--shards=' . FEATURE_SHARDS,
        'weight profile' => "--weight-profile={$profile}",
    ];

    foreach ($requirements as $description => $needle) {
        if (! str_contains($jobDefinition, $needle)) {
            fail("Build workflow job [{$job}] is missing {$description} [{$needle}].");
        }
    }
}

function verify_gate_wiring(string $workflow, string $publicBoundaryWorkflow): void
{
    $routes = [
        'quality' => [
            "needs.route.outputs.route == 'bounded'",
            "needs.route.outputs.route == 'complete'",
            "needs.route.outputs.qualification == 'full'",
        ],
        'pr-unit-contracts' => ["needs.route.outputs.route == 'bounded'"],
        'pr-feature-mysql' => ["needs.route.outputs.route == 'bounded'"],
        'feature-mysql' => [
            "needs.route.outputs.route == 'complete'",
            "needs.route.outputs.qualification == 'full'",
        ],
        'feature-postgresql' => [
            "needs.route.outputs.route == 'complete'",
            "needs.route.outputs.qualification == 'full'",
        ],
        'feature-mariadb' => [
            "needs.route.outputs.route == 'complete'",
            "needs.route.outputs.qualification == 'full'",
        ],
        'coverage' => [
            "needs.route.outputs.route == 'complete'",
            "needs.route.outputs.qualification == 'full'",
        ],
    ];

    foreach ($routes as $job => $conditions) {
        $definition = workflow_job_definition($workflow, $job);

        if (! str_contains($definition, 'needs: route')) {
            fail("Build workflow job [{$job}] does not depend on route classification.");
        }

        foreach ($conditions as $condition) {
            if (! str_contains($definition, $condition)) {
                fail("Build workflow job [{$job}] is not wired to its required condition [{$condition}].");
            }
        }
    }

    $build = workflow_job_definition($workflow, 'build');

    foreach (array_keys($routes) as $job) {
        if (! str_contains($build, "    - {$job}\n")) {
            fail("Final build gate does not wait for [{$job}].");
        }
    }

    foreach (['bounded', 'complete', 'structural', 'local-sentinel'] as $route) {
        if (! str_contains($build, "needs.route.outputs.route == '{$route}'")) {
            fail("Final build gate does not validate route [{$route}].");
        }
    }

    foreach (['full', 'release-recovery'] as $qualification) {
        if (! str_contains($build, "needs.route.outputs.qualification == '{$qualification}'")) {
            fail("Final build gate does not validate qualification [{$qualification}].");
        }
    }

    foreach (
        [
            'selected qualification reporting' => 'Report qualification class and elapsed time',
            'target qualification baseline reporting' => 'baseline_elapsed_seconds',
            'focused matrix exclusion' => 'Focused release and recovery qualification succeeded',
        ] as $description => $needle
    ) {
        if (! str_contains($build, $needle)) {
            fail("Final build gate is missing {$description} [{$needle}].");
        }
    }

    $route = workflow_job_definition($workflow, 'route');

    foreach (
        [
            'route self-tests' => 'resolve-build-route.php --self-test',
            'target qualification self-tests' => 'classify-target-qualification.php --self-test',
            'classification behavior and trust tests' => 'test-build-qualification.py',
            'exact feature inventory verification' => 'verify-feature-shards.php',
            'workflow YAML syntax validation' => "yq eval-all '.' .github/workflows/*.yml",
            'release recovery contract tests' => 'test-component-release-recovery.py',
            'public-boundary validation' => 'scripts/check-public-boundary.sh',
        ] as $description => $needle
    ) {
        if (! str_contains($route, $needle)) {
            fail("Structural candidate gate is missing {$description} [{$needle}].");
        }
    }

    if (
        ! str_contains($publicBoundaryWorkflow, "  pull_request:\n")
        || ! str_contains($publicBoundaryWorkflow, 'scripts/check-public-boundary.sh')
    ) {
        fail('Pull-request public-boundary validation is not enabled.');
    }
}

function workflow_job_definition(string $workflow, string $job): string
{
    $pattern = '/^  ' . preg_quote($job, '/') . ":\R(?<job>.*?)(?=^  [a-z0-9-]+:\R|\z)/ms";

    if (preg_match($pattern, $workflow, $matches) !== 1) {
        fail("Build workflow job [{$job}] was not found.");
    }

    return $matches['job'];
}

/**
 * @param array<string, mixed> $weights
 * @param list<string> $expectedFiles
 */
function verify_weight_inventory(array $weights, string $profile, array $expectedFiles): void
{
    if (! is_array($weights[$profile] ?? null)) {
        fail("Feature timing profile [{$profile}] is missing.");
    }

    $weightedFiles = array_keys($weights[$profile]);
    sort($weightedFiles, SORT_STRING);

    if ($weightedFiles !== $expectedFiles) {
        report_inventory_difference("Feature timing profile [{$profile}]", $expectedFiles, $weightedFiles);
    }
}

/**
 * @param list<string> $expectedFiles
 */
function verify_split_inventory(
    string $splitter,
    string $featureDirectory,
    string $weightsFile,
    string $profile,
    array $expectedFiles,
    string $root,
): void {
    $represented = [];

    for ($shard = 0; $shard < FEATURE_SHARDS; $shard++) {
        $command = [
            PHP_BINARY,
            $splitter,
            '--dir=' . $featureDirectory,
            '--shard=' . $shard,
            '--shards=' . FEATURE_SHARDS,
            '--weights=' . $weightsFile,
            '--weight-profile=' . $profile,
        ];
        [$status, $stdout, $stderr] = run($command, $root);

        if ($status !== 0) {
            fail("Unable to resolve {$profile} shard {$shard}: " . trim($stderr));
        }

        $files = array_values(array_filter(array_map('trim', explode("\n", $stdout))));

        if ($files === []) {
            fail("Feature shard {$profile}/{$shard} is empty.");
        }

        foreach ($files as $file) {
            $represented[] = relative_path($file, $root);
        }
    }

    $counts = array_count_values($represented);
    $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count !== 1));
    sort($represented, SORT_STRING);

    if ($represented !== $expectedFiles || $duplicates !== []) {
        report_inventory_difference("Feature shard profile [{$profile}]", $expectedFiles, $represented, $duplicates);
    }
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 * @param list<string> $duplicates
 */
function report_inventory_difference(string $subject, array $expected, array $actual, array $duplicates = []): never
{
    $missing = array_values(array_diff($expected, $actual));
    $unexpected = array_values(array_diff($actual, $expected));

    fail(sprintf(
        '%s does not represent every feature file exactly once. Missing: [%s]. Unexpected: [%s]. Duplicated: [%s].',
        $subject,
        implode(', ', $missing),
        implode(', ', $unexpected),
        implode(', ', $duplicates),
    ));
}

/**
 * @param list<string> $command
 * @return array{int, string, string}
 */
function run(array $command, string $directory): array
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $directory,
        null,
        [
            'bypass_shell' => true,
        ],
    );

    if (! is_resource($process)) {
        fail('Unable to start feature shard verification.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [$status, (string) $stdout, (string) $stderr];
}

function relative_path(string $path, string $root): string
{
    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

    return str_starts_with($normalizedPath, $normalizedRoot . '/')
        ? substr($normalizedPath, strlen($normalizedRoot) + 1)
        : $normalizedPath;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
