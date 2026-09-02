#!/usr/bin/env php
<?php

declare(strict_types=1);

const POLICY_SCHEMA = 'durable-workflow.laravel-dependency-security-policy';

$root = dirname(__DIR__, 2);
$policyOnly = in_array('--policy-only', array_slice($argv, 1), true);
$policy = readJson($root . '/resources/laravel-dependency-security-policy.json');
$composer = readJson($root . '/composer.json');
$upgradeContract = readJson($root . '/resources/laravel-embedded-upgrade-contract.json');

validatePolicy($root, $policy, $composer, $upgradeContract);

if ($policyOnly) {
    echo "Laravel dependency security policy is internally consistent.\n";
    exit(0);
}

$temporaryRoot = createTemporaryDirectory();

try {
    auditSupportedMajors($temporaryRoot, $policy);
} finally {
    removeTemporaryDirectory($temporaryRoot);
}

echo "Every supported Laravel major matches the declared dependency security policy.\n";

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        fail(sprintf('Unable to read JSON file [%s].', $path));
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        fail(sprintf('JSON file [%s] must decode to an object.', $path));
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $policy
 * @param array<string, mixed> $composer
 * @param array<string, mixed> $upgradeContract
 */
function validatePolicy(string $root, array $policy, array $composer, array $upgradeContract): void
{
    if (($policy['schema'] ?? null) !== POLICY_SCHEMA || ($policy['version'] ?? null) !== 1) {
        fail('Laravel dependency security policy identity is invalid.');
    }

    $package = $policy['package'] ?? null;
    $dependabot = $policy['dependabot'] ?? null;
    $majors = $policy['supported_majors'] ?? null;
    $acceptedAdvisories = $policy['accepted_advisories'] ?? null;

    if (! is_string($package) || ! is_array($dependabot) || ! is_array($majors) || ! is_array($acceptedAdvisories)) {
        fail('Laravel dependency security policy is missing required objects.');
    }

    if (($dependabot['dependency_alerts'] ?? null) !== 'enabled'
        || ($dependabot['automated_security_fixes'] ?? null) !== 'disabled'
        || ($dependabot['root_composer_updates'] ?? null) !== 'prohibited'
    ) {
        fail('The Dependabot repository posture must keep alerts enabled and prohibit root Composer updates.');
    }

    $constraints = [];

    foreach ($majors as $majorKey => $configuration) {
        $major = (string) $majorKey;

        if (! ctype_digit($major) || ! is_array($configuration)) {
            fail('Every supported Laravel major must have an object configuration.');
        }

        $minimum = $configuration['minimum'] ?? null;
        $disposition = $configuration['disposition'] ?? null;

        if (! is_string($minimum) || ! str_starts_with($minimum, $major . '.')) {
            fail(sprintf('Laravel %s must declare a minimum within its major.', $major));
        }

        if (! in_array($disposition, ['secure', 'accepted-risk'], true)) {
            fail(sprintf('Laravel %s has an invalid security disposition.', $major));
        }

        $constraints[] = '^' . $minimum;
    }

    $expectedConstraint = implode('|', $constraints);

    if (($composer['require'][$package] ?? null) !== $expectedConstraint) {
        fail('composer.json does not match the per-major Laravel security minimums.');
    }

    if (($upgradeContract['supported_intersection']['authority']['embedded_v2']['laravel'] ?? null) !== $expectedConstraint) {
        fail('The embedded upgrade contract does not match the Laravel security minimums.');
    }

    $qualifiedMajors = array_map(
        static fn (string $constraint): string => substr($constraint, 0, strpos($constraint, '.')),
        array_keys($upgradeContract['supported_intersection']['authority']['laravel_minimum_php'] ?? []),
    );

    if (array_map('strval', array_keys($majors)) !== $qualifiedMajors) {
        fail('The dependency security policy and embedded qualification matrix cover different Laravel majors.');
    }

    $policyPath = $composer['extra']['durable-workflow']['laravel-dependency-security-policy'] ?? null;

    if ($policyPath !== 'resources/laravel-dependency-security-policy.json'
        || ! is_file($root . '/' . $policyPath)
        || ($upgradeContract['qualification']['dependency_security_policy'] ?? null) !== $policyPath
    ) {
        fail('The shipped Laravel dependency security policy is not discoverable from package metadata.');
    }

    validateAcceptedAdvisories($majors, $acceptedAdvisories);
    validateDependabotConfiguration($root);
}

/**
 * @param array<string, mixed> $majors
 * @param array<string, mixed> $acceptedAdvisories
 */
function validateAcceptedAdvisories(array $majors, array $acceptedAdvisories): void
{
    $riskMajors = [];

    foreach ($majors as $majorKey => $configuration) {
        $major = (string) $majorKey;

        if (($configuration['disposition'] ?? null) === 'accepted-risk') {
            $riskMajors[] = $major;
        }
    }

    $declaredRiskMajors = [];

    foreach ($acceptedAdvisories as $composerId => $acceptance) {
        if (! is_string($composerId) || ! str_starts_with($composerId, 'PKSA-') || ! is_array($acceptance)) {
            fail('Accepted Composer advisories must be keyed by PKSA identifier.');
        }

        $githubId = $acceptance['github_advisory'] ?? null;
        $affectedMajors = $acceptance['affected_supported_majors'] ?? null;
        $reviewBy = $acceptance['review_by'] ?? null;
        $reason = $acceptance['reason'] ?? null;

        if (! is_string($githubId) || ! preg_match('/^GHSA-[a-z0-9-]+$/', $githubId)) {
            fail(sprintf('Accepted advisory %s must map to a GitHub advisory.', $composerId));
        }

        if (! is_array($affectedMajors) || $affectedMajors === []) {
            fail(sprintf('Accepted advisory %s must name affected supported majors.', $composerId));
        }

        foreach ($affectedMajors as $major) {
            if (! is_string($major) || ! isset($majors[$major])) {
                fail(sprintf('Accepted advisory %s names unsupported Laravel major %s.', $composerId, (string) $major));
            }

            $declaredRiskMajors[$major] = true;
        }

        if (! is_string($reviewBy) || DateTimeImmutable::createFromFormat('!Y-m-d', $reviewBy) === false) {
            fail(sprintf('Accepted advisory %s must have a valid review date.', $composerId));
        }

        if ($reviewBy < gmdate('Y-m-d')) {
            fail(sprintf('Accepted advisory %s is past its review date.', $composerId));
        }

        if (! is_string($reason) || trim($reason) === '') {
            fail(sprintf('Accepted advisory %s must explain the remaining risk.', $composerId));
        }
    }

    $declaredRiskMajorList = array_map('strval', array_keys($declaredRiskMajors));
    sort($riskMajors);
    sort($declaredRiskMajorList);

    if ($riskMajors !== $declaredRiskMajorList) {
        fail('Every accepted-risk Laravel major must have a current advisory record.');
    }
}

function validateDependabotConfiguration(string $root): void
{
    $path = $root . '/.github/dependabot.yml';

    if (! is_file($path)) {
        return;
    }

    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        fail('The Dependabot configuration cannot be read.');
    }

    $matchCount = preg_match_all(
        '/^\s*-\s+package-ecosystem:\s*["\']?composer["\']?\s*$(.*?)(?=^\s*-\s+package-ecosystem:|\z)/ms',
        $contents,
        $matches,
    );

    if ($matchCount === false) {
        fail('The Dependabot configuration cannot be inspected.');
    }

    foreach ($matches[0] as $composerBlock) {
        if (composerBlockTargetsRoot($composerBlock)) {
            fail(
                'Dependabot root Composer updates are unsupported for this unlocked library; use the per-major security audit.'
            );
        }
    }
}

function composerBlockTargetsRoot(string $block): bool
{
    if (preg_match('/^\s+directory:\s*["\']?\/["\']?\s*$/m', $block) === 1) {
        return true;
    }

    if (preg_match('/^\s+directories:\s*\[[^\]]*["\']?\/["\']?[^\]]*\]\s*$/m', $block) === 1) {
        return true;
    }

    return preg_match('/^\s+directories:\s*$\R(?:(?!^\s+\w[\w-]*:).*$\R)*^\s+-\s*["\']?\/["\']?\s*$/m', $block) === 1;
}

/**
 * @param array<string, mixed> $policy
 */
function auditSupportedMajors(string $temporaryRoot, array $policy): void
{
    $package = (string) $policy['package'];
    $majors = $policy['supported_majors'];
    $acceptedAdvisories = $policy['accepted_advisories'];

    foreach ($majors as $majorKey => $configuration) {
        $major = (string) $majorKey;
        $expected = [];

        foreach ($acceptedAdvisories as $composerId => $acceptance) {
            if (in_array($major, $acceptance['affected_supported_majors'], true)) {
                $expected[$composerId] = $acceptance['github_advisory'];
            }
        }

        ksort($expected);
        $floor = resolveAndAudit(
            $temporaryRoot . '/laravel-' . $major . '-floor',
            $major . '-floor',
            (string) $configuration['minimum'],
            $package,
        );
        $latest = resolveAndAudit(
            $temporaryRoot . '/laravel-' . $major . '-latest',
            $major . '-latest',
            $major . '.*',
            $package,
        );

        assertAdvisoriesMatch($major, 'minimum', $floor['advisories'], $expected);
        assertAdvisoriesMatch($major, 'latest', $latest['advisories'], $expected);

        $summary = $floor['advisories'] === []
            ? 'clean'
            : 'accepted ' . implode(', ', array_keys($floor['advisories']));
        echo sprintf(
            "Laravel %s floor %s / latest %s: %s\n",
            $major,
            $floor['version'],
            $latest['version'],
            $summary,
        );
    }
}

/**
 * @return array{version: string, advisories: array<string, list<string>>}
 */
function resolveAndAudit(string $auditRoot, string $name, string $constraint, string $package): array
{
    if (! mkdir($auditRoot, 0700, true) && ! is_dir($auditRoot)) {
        fail(sprintf('Unable to create audit directory [%s].', $name));
    }

    $manifest = [
        'name' => 'durable-workflow/laravel-' . $name . '-security-audit',
        'type' => 'project',
        'require' => [
            $package => $constraint,
        ],
    ];

    file_put_contents(
        $auditRoot . '/composer.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );

    $update = runCommand([
        'composer',
        'update',
        '--no-install',
        '--no-interaction',
        '--no-progress',
        '--no-blocking',
        '--no-audit',
    ], $auditRoot);

    if ($update['exit_code'] !== 0) {
        fail(sprintf("Unable to resolve %s:\n%s", $name, trim($update['stderr'])));
    }

    $audit = runCommand(['composer', 'audit', '--locked', '--format=json', '--no-interaction'], $auditRoot);

    if (! in_array($audit['exit_code'], [0, 1, 2, 3], true)) {
        fail(sprintf("Unable to audit %s:\n%s", $name, trim($audit['stderr'])));
    }

    $result = json_decode($audit['stdout'], true, 512, JSON_THROW_ON_ERROR);

    if (($result['abandoned'] ?? []) !== []) {
        fail(sprintf('%s resolved abandoned packages.', $name));
    }

    return [
        'version' => resolvePackageVersion(readJson($auditRoot . '/composer.lock'), $package),
        'advisories' => collectAdvisories($result['advisories'] ?? []),
    ];
}

/**
 * @param array<string, list<string>> $actual
 * @param array<string, string> $expected
 */
function assertAdvisoriesMatch(string $major, string $resolution, array $actual, array $expected): void
{
    ksort($actual);

    if (array_keys($actual) !== array_keys($expected)) {
        fail(sprintf(
            'Laravel %s %s advisory drift: expected [%s], found [%s].',
            $major,
            $resolution,
            implode(', ', array_keys($expected)),
            implode(', ', array_keys($actual)),
        ));
    }

    foreach ($actual as $composerId => $githubIds) {
        if (! in_array($expected[$composerId], $githubIds, true)) {
            fail(sprintf(
                'Laravel %s %s advisory %s no longer maps to declared GitHub alert %s.',
                $major,
                $resolution,
                $composerId,
                $expected[$composerId],
            ));
        }
    }
}

/**
 * @param mixed $advisories
 * @return array<string, list<string>>
 */
function collectAdvisories($advisories): array
{
    if ($advisories === []) {
        return [];
    }

    if (! is_array($advisories)) {
        fail('Composer audit returned an invalid advisories value.');
    }

    $collected = [];

    foreach ($advisories as $packageAdvisories) {
        if (! is_array($packageAdvisories)) {
            fail('Composer audit returned an invalid package advisory list.');
        }

        foreach ($packageAdvisories as $advisory) {
            if (! is_array($advisory) || ! is_string($advisory['advisoryId'] ?? null)) {
                fail('Composer audit returned an advisory without an identifier.');
            }

            $githubIds = [];

            foreach ($advisory['sources'] ?? [] as $source) {
                $remoteId = is_array($source) ? ($source['remoteId'] ?? null) : null;

                if (is_string($remoteId) && str_starts_with($remoteId, 'GHSA-')) {
                    $githubIds[] = $remoteId;
                }
            }

            $link = $advisory['link'] ?? null;

            if (is_string($link) && preg_match('/(GHSA-[a-z0-9-]+)$/', $link, $matches) === 1) {
                $githubIds[] = $matches[1];
            }

            $githubIds = array_values(array_unique($githubIds));
            sort($githubIds);
            $collected[$advisory['advisoryId']] = $githubIds;
        }
    }

    return $collected;
}

/**
 * @param array<string, mixed> $lock
 */
function resolvePackageVersion(array $lock, string $package): string
{
    foreach ($lock['packages'] ?? [] as $lockedPackage) {
        if (is_array($lockedPackage) && ($lockedPackage['name'] ?? null) === $package) {
            return (string) ($lockedPackage['version'] ?? 'unknown');
        }
    }

    fail(sprintf('Composer did not resolve required package %s.', $package));
}

/**
 * @param list<string> $command
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runCommand(array $command, string $workingDirectory): array
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
        $workingDirectory,
    );

    if (! is_resource($process)) {
        fail(sprintf('Unable to start command [%s].', implode(' ', $command)));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function createTemporaryDirectory(): string
{
    $base = getenv('RUNNER_TEMP');

    if (! is_string($base) || $base === '' || ! is_dir($base) || ! is_writable($base)) {
        $base = sys_get_temp_dir();
    }

    $path = tempnam($base, 'workflow-laravel-security-');

    if (! is_string($path)) {
        fail('Unable to reserve a temporary Laravel security audit path.');
    }

    if (! unlink($path) || ! mkdir($path, 0700)) {
        fail('Unable to create a temporary Laravel security audit directory.');
    }

    return $path;
}

function removeTemporaryDirectory(string $path): void
{
    if (! str_starts_with(basename($path), 'workflow-laravel-security-') || ! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
