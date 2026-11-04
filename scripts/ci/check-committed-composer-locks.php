#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Workflow\Ci\CommittedLocks;

use RuntimeException;
use Throwable;

final class LockAuditFailure extends RuntimeException
{
}

/**
 * @return array{int, string, string}
 */
function command(array $arguments, string $directory): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) {
        throw new LockAuditFailure('Cannot create audit output files.');
    }
    try {
        $process = proc_open($arguments, [
            0 => ['pipe', 'r'],
            1 => $stdout,
            2 => $stderr,
        ], $pipes, $directory);
        if (! is_resource($process)) {
            throw new LockAuditFailure('Cannot start audit command.');
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    } finally {
        fclose($stdout);
        fclose($stderr);
    }
}

/**
 * No advisory, ignored result or abandoned package is silently discarded.
 *
 * @param array<string, mixed> $result
 */
function assertReportShape(array $result, int $exit): void
{
    $knownFields = ['advisories', 'abandoned', 'ignored-advisories', 'filter', 'cooldown'];
    foreach (['ignored-advisories', 'filter', 'cooldown'] as $field) {
        if (array_key_exists($field, $result) && $result[$field] !== []) {
            throw new LockAuditFailure('Audit suppressed, delayed or filtered package evidence.');
        }
    }
    if (! in_array($exit, [0, 1], true)
        || array_diff(array_keys($result), $knownFields) !== []
        || ! isset($result['advisories'], $result['abandoned'])
        || ! is_array($result['advisories']) || ! is_array($result['abandoned'])
        || $result['abandoned'] !== []
    ) {
        throw new LockAuditFailure('Audit failed, returned incomplete evidence, or hid/abandoned packages.');
    }
    if ($exit !== ($result['advisories'] === [] ? 0 : 1)) {
        throw new LockAuditFailure('Composer exit status contradicts its advisory evidence.');
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function packageRecords(mixed $lock): array
{
    if (! is_array($lock) || ! array_key_exists('packages', $lock)) {
        throw new LockAuditFailure('Invalid Composer lock package sections.');
    }
    $packages = [];
    foreach (['packages', 'packages-dev'] as $section) {
        $records = array_key_exists($section, $lock) ? $lock[$section] : [];
        if (! is_array($records) || ! array_is_list($records)) {
            throw new LockAuditFailure('Invalid Composer lock package section.');
        }
        foreach ($records as $package) {
            $name = is_array($package) ? ($package['name'] ?? null) : null;
            if (! is_string($name) || $name === '' || array_key_exists($name, $packages)) {
                throw new LockAuditFailure('Malformed or duplicate Composer package record.');
            }
            $packages[$name] = $package;
        }
    }

    return $packages;
}

/**
 * @param array<string, mixed> $package
 */
function assertPublishedWorkflow(array $package, string $reference): void
{
    $expected = [
        'source' => [
            'type' => 'git',
            'url' => 'https://github.com/durable-workflow/workflow.git',
            'reference' => $reference,
        ],
        'dist' => [
            'type' => 'zip',
            'url' => 'https://api.github.com/repos/durable-workflow/workflow/zipball/' . $reference,
            'reference' => $reference,
        ],
    ];
    foreach ($expected as $section => $fields) {
        $actual = $package[$section] ?? null;
        if (! is_array($actual)) {
            throw new LockAuditFailure('Missing published Workflow source or download identity.');
        }
        foreach ($fields as $field => $value) {
            if (($actual[$field] ?? null) !== $value) {
                throw new LockAuditFailure('Published Workflow source or download identity changed.');
            }
        }
    }
    if (array_key_exists('shasum', $package['dist']) && $package['dist']['shasum'] !== '') {
        throw new LockAuditFailure('Unexpected published Workflow download checksum.');
    }
}

/**
 * @param array<string, mixed> $entry
 * @return array<string, array<string, mixed>>
 */
function reviewedExceptions(array $entry): array
{
    $accepted = [];
    foreach ($entry['accepted_advisories'] as $exception) {
        foreach (['id', 'package', 'version', 'cve', 'owner', 'reason', 'review_condition'] as $key) {
            if (! is_string($exception[$key] ?? null) || trim($exception[$key]) === '') {
                throw new LockAuditFailure('Historical advisory exception lacks its exact identity or review scope.');
            }
        }
        if (isset($accepted[$exception['id']])) {
            throw new LockAuditFailure('Duplicate accepted advisory.');
        }
        $accepted[$exception['id']] = $exception;
    }

    return $accepted;
}

/**
 * @param array<string, array<string, mixed>> $accepted
 * @param array<string, array<string, mixed>> $packages
 */
function reviewedAdvisory(string $package, mixed $advisory, array $accepted, array $packages): string
{
    if (! is_array($advisory)) {
        throw new LockAuditFailure('Invalid Composer advisory record.');
    }
    $id = $advisory['advisoryId'] ?? null;
    $exception = is_string($id) ? ($accepted[$id] ?? null) : null;
    if ($exception === null || $exception['package'] !== $package
        || ($advisory['packageName'] ?? null) !== $package
        || ($advisory['cve'] ?? null) !== $exception['cve']
        || ($packages[$package]['version'] ?? null) !== $exception['version']
    ) {
        throw new LockAuditFailure(sprintf('Unaccepted advisory for %s: %s.', $package, (string) $id));
    }

    return $id;
}

/**
 * No advisory, ignored result or abandoned package is silently discarded.
 *
 * @param array<string, mixed> $result
 * @param array<string, mixed> $entry
 * @param array<string, array<string, mixed>> $packages
 * @return list<string>
 */
function acceptedResults(array $result, int $exit, array $entry, array $packages): array
{
    assertReportShape($result, $exit);
    $accepted = reviewedExceptions($entry);
    $seen = [];
    foreach ($result['advisories'] as $package => $advisories) {
        if (! is_array($advisories)) {
            throw new LockAuditFailure('Invalid Composer advisory list.');
        }
        foreach ($advisories as $advisory) {
            $id = reviewedAdvisory($package, $advisory, $accepted, $packages);
            if (isset($seen[$id])) {
                throw new LockAuditFailure('Duplicate advisory evidence.');
            }
            $seen[$id] = true;
        }
    }
    if (array_diff_key($accepted, $seen) !== [] || ($exit !== 0 && $seen === [])) {
        throw new LockAuditFailure(
            'Audit failure has no reviewed advisory, or a historical exception needs reconciliation.'
        );
    }

    return array_keys($seen);
}

function regularFile(string $root, string $path): string
{
    $file = $root . '/' . $path;
    if (is_link($file) || ! is_file($file) || realpath($file) !== $file) {
        throw new LockAuditFailure('Input must be a regular file under the checkout: ' . $path);
    }

    return $file;
}

/**
 * @return array<string, mixed>
 */
function readInventory(string $root): array
{
    $policyFile = regularFile($root, 'resources/committed-composer-lock-audit.json');
    $policy = json_decode((string) file_get_contents($policyFile), true, 512, JSON_THROW_ON_ERROR);
    if (($policy['schema'] ?? null) !== 'durable-workflow.committed-composer-lock-audit/v1'
        || ! is_array($policy['locks'] ?? null) || $policy['locks'] === [] || count($policy['locks']) > 64
    ) {
        throw new LockAuditFailure('Invalid committed lock inventory.');
    }

    return $policy['locks'];
}

/**
 * @param array<string, mixed> $entries
 */
function assertInventory(string $root, array $entries): void
{
    [$exit, $output] = command(['git', 'ls-files', '-z', '--', 'composer.lock', '**/composer.lock'], $root);
    $discovered = array_filter(explode("\0", $output), static fn (string $path): bool => $path !== '');
    $reviewed = array_keys($entries);
    sort($discovered);
    sort($reviewed);
    if ($exit !== 0 || $discovered !== $reviewed) {
        throw new LockAuditFailure('Committed lock inventory changed; review every new or removed path.');
    }
}

/**
 * @return array<string, mixed>
 */
function reviewedEntry(mixed $entry): array
{
    if (! is_array($entry) || ! is_array($entry['accepted_advisories'] ?? null)
        || ! array_key_exists('workflow_reference', $entry)
        || ($entry['workflow_reference'] !== null
            && (! is_string($entry['workflow_reference'])
                || preg_match('/^[a-f0-9]{40}$/D', $entry['workflow_reference']) !== 1))
    ) {
        throw new LockAuditFailure('Invalid reviewed lock identity.');
    }

    return $entry;
}

/**
 * @param array<string, mixed> $entry
 * @return array<string, array<string, mixed>>
 */
function reviewedPackages(string $file, array $entry): array
{
    if ($entry['accepted_advisories'] !== []
        && (! is_string($entry['preserved_sha256'] ?? null)
            || hash_file('sha256', $file) !== $entry['preserved_sha256'])
    ) {
        throw new LockAuditFailure('Historical lock identity changed: ' . $file);
    }
    $lock = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $packages = packageRecords($lock);
    $workflow = $packages['durable-workflow/workflow'] ?? null;
    $reference = $entry['workflow_reference'];
    if ($reference === null && $workflow !== null || $reference !== null && $workflow === null) {
        throw new LockAuditFailure('Published Workflow reference changed without inventory review: ' . $file);
    }
    if ($reference !== null) {
        assertPublishedWorkflow($workflow, $reference);
    }

    return $packages;
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, array<string, mixed>> $packages
 * @return list<string>
 */
function auditReviewedLock(string $root, string $file, array $entry, array $packages): array
{
    $verifier = getenv('COMPOSER_AUDIT_VERIFIER');
    $composer = getenv('COMPOSER_AUDIT_BINARY');
    if (! is_string($verifier) || ! is_file($verifier) || ! is_string($composer) || ! is_file($composer)) {
        throw new LockAuditFailure('The reviewed trust verifier and actual Composer binary are required.');
    }
    [$trustExit, $trustOutput, $trustError] = command([PHP_BINARY, $verifier, $composer, dirname($file)], $root);
    if ($trustExit !== 0) {
        throw new LockAuditFailure('Composer trust refused for ' . $file . ': ' . trim($trustError));
    }
    printf("%s: %s\n", $file, trim($trustOutput));
    [$auditExit, $json, $error] = command([
        $composer, '--no-plugins', '--no-scripts', 'audit', '--locked', '--abandoned=fail', '--format=json', '--no-interaction',
    ], dirname($file));
    try {
        $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new LockAuditFailure('Invalid Composer audit result.');
        }

        return acceptedResults($result, $auditExit, $entry, $packages);
    } catch (Throwable $exception) {
        throw new LockAuditFailure($file . ': ' . $exception->getMessage() . "\n" . trim($error), 0, $exception);
    }
}

function main(string $root, bool $inventoryOnly): void
{
    $entries = readInventory($root);
    assertInventory($root, $entries);
    foreach ($entries as $path => $rawEntry) {
        $entry = reviewedEntry($rawEntry);
        $file = regularFile($root, $path);
        $packages = reviewedPackages($file, $entry);
        if ($inventoryOnly) {
            continue;
        }
        $accepted = auditReviewedLock($root, $file, $entry, $packages);
        printf(
            "%s: %s\n",
            $path,
            $accepted === [] ? 'clean' : 'reviewed historical advisory ' . implode(', ', $accepted)
        );
    }
    printf(
        $inventoryOnly
            ? "Verified %d committed lock identities; no audit requests were made.\n"
            : "Verified %d committed locks and their live audit results.\n",
        count($entries)
    );
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        main(dirname(__DIR__, 2), in_array('--inventory-only', $argv, true));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
