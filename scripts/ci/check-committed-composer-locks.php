#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Workflow\Ci\CommittedLocks;

use RuntimeException;
use Throwable;

/**
 * @return array{int, string, string}
 */
function command(array $arguments, string $directory): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('Cannot create audit output files.');
    }
    try {
        $process = proc_open($arguments, [
            0 => ['pipe', 'r'],
            1 => $stdout,
            2 => $stderr,
        ], $pipes, $directory);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start audit command.');
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
 * @param array<string, mixed> $entry
 * @param array<string, array<string, mixed>> $packages
 * @return list<string>
 */
function acceptedResults(array $result, int $exit, array $entry, array $packages): array
{
    $knownFields = ['advisories', 'abandoned', 'ignored-advisories', 'filter', 'cooldown'];
    foreach (['ignored-advisories', 'filter', 'cooldown'] as $field) {
        if (array_key_exists($field, $result) && $result[$field] !== []) {
            throw new RuntimeException('Audit suppressed, delayed or filtered package evidence.');
        }
    }
    if (! in_array($exit, [0, 1], true)
        || array_diff(array_keys($result), $knownFields) !== []
        || ! isset($result['advisories'], $result['abandoned'])
        || ! is_array($result['advisories']) || ! is_array($result['abandoned'])
        || $result['abandoned'] !== []
    ) {
        throw new RuntimeException('Audit failed, returned incomplete evidence, or hid/abandoned packages.');
    }
    if ($exit !== ($result['advisories'] === [] ? 0 : 1)) {
        throw new RuntimeException('Composer exit status contradicts its advisory evidence.');
    }
    $accepted = [];
    foreach ($entry['accepted_advisories'] as $exception) {
        foreach (['id', 'package', 'version', 'cve', 'owner', 'reason', 'review_condition'] as $key) {
            if (! is_string($exception[$key] ?? null) || trim($exception[$key]) === '') {
                throw new RuntimeException('Historical advisory exception lacks its exact identity or review scope.');
            }
        }
        if (isset($accepted[$exception['id']])) {
            throw new RuntimeException('Duplicate accepted advisory.');
        }
        $accepted[$exception['id']] = $exception;
    }
    $seen = [];
    foreach ($result['advisories'] as $package => $advisories) {
        if (! is_array($advisories)) {
            throw new RuntimeException('Invalid Composer advisory list.');
        }
        foreach ($advisories as $advisory) {
            $id = $advisory['advisoryId'] ?? null;
            $exception = is_string($id) ? ($accepted[$id] ?? null) : null;
            if ($exception === null || $exception['package'] !== $package
                || ($advisory['packageName'] ?? null) !== $package
                || ($advisory['cve'] ?? null) !== $exception['cve']
                || ($packages[$package]['version'] ?? null) !== $exception['version']
            ) {
                throw new RuntimeException(sprintf('Unaccepted advisory for %s: %s.', $package, (string) $id));
            }
            if (isset($seen[$id])) {
                throw new RuntimeException('Duplicate advisory evidence.');
            }
            $seen[$id] = true;
        }
    }
    if (array_diff_key($accepted, $seen) !== [] || ($exit !== 0 && $seen === [])) {
        throw new RuntimeException(
            'Audit failure has no reviewed advisory, or a historical exception needs reconciliation.'
        );
    }

    return array_keys($seen);
}

function main(string $root, bool $inventoryOnly): void
{
    $policyFile = $root . '/resources/committed-composer-lock-audit.json';
    if (is_link($policyFile) || ! is_file($policyFile) || realpath($policyFile) !== $policyFile) {
        throw new RuntimeException('Audit inventory must be a regular file under the checkout.');
    }
    $policy = json_decode((string) file_get_contents($policyFile), true, 512, JSON_THROW_ON_ERROR);
    if (($policy['schema'] ?? null) !== 'durable-workflow.committed-composer-lock-audit/v1'
        || ! is_array($policy['locks'] ?? null) || $policy['locks'] === [] || count($policy['locks']) > 64
    ) {
        throw new RuntimeException('Invalid committed lock inventory.');
    }
    [$exit, $output] = command(['git', 'ls-files', '-z', '--', 'composer.lock', '**/composer.lock'], $root);
    $discovered = array_filter(explode("\0", $output), static fn (string $path): bool => $path !== '');
    $reviewed = array_keys($policy['locks']);
    sort($discovered);
    sort($reviewed);
    if ($exit !== 0 || $discovered !== $reviewed) {
        throw new RuntimeException('Committed lock inventory changed; review every new or removed path.');
    }
    foreach ($policy['locks'] as $path => $entry) {
        $file = $root . '/' . $path;
        if (is_link($file) || ! is_file($file) || realpath($file) !== $file
            || ! is_array($entry) || ! is_array($entry['accepted_advisories'] ?? null)
            || ! array_key_exists('workflow_reference', $entry)
            || ($entry['workflow_reference'] !== null
                && (! is_string($entry['workflow_reference'])
                    || preg_match('/^[a-f0-9]{40}$/D', $entry['workflow_reference']) !== 1))
        ) {
            throw new RuntimeException('Lock must be a regular reviewed file: ' . $path);
        }
        if ($entry['accepted_advisories'] !== []
            && (! is_string($entry['preserved_sha256'] ?? null)
                || hash_file('sha256', $file) !== $entry['preserved_sha256'])
        ) {
            throw new RuntimeException('Historical lock identity changed: ' . $path);
        }
        $lock = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $packages = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $packages[$package['name']] = $package;
        }
        if (($entry['workflow_reference'] === null && isset($packages['durable-workflow/workflow']))
            || ($packages['durable-workflow/workflow']['source']['reference'] ?? null) !== $entry['workflow_reference']
        ) {
            throw new RuntimeException('Published Workflow reference changed without inventory review: ' . $path);
        }
        if ($inventoryOnly) {
            continue;
        }
        $verifier = getenv('COMPOSER_AUDIT_VERIFIER');
        $composer = getenv('COMPOSER_AUDIT_BINARY');
        if (! is_string($verifier) || ! is_file($verifier) || ! is_string($composer) || ! is_file($composer)) {
            throw new RuntimeException('The reviewed trust verifier and actual Composer binary are required.');
        }
        [$trustExit, $trustOutput, $trustError] = command([PHP_BINARY, $verifier, $composer, dirname($file)], $root);
        if ($trustExit !== 0) {
            throw new RuntimeException('Composer trust refused for ' . $path . ': ' . trim($trustError));
        }
        printf("%s: %s\n", $path, trim($trustOutput));
        [$auditExit, $json, $error] = command([
            $composer, '--no-plugins', '--no-scripts', 'audit', '--locked', '--abandoned=fail', '--format=json', '--no-interaction',
        ], dirname($file));
        try {
            $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('Invalid Composer audit result.');
            }
            $accepted = acceptedResults($result, $auditExit, $entry, $packages);
        } catch (Throwable $exception) {
            throw new RuntimeException($path . ': ' . $exception->getMessage() . "\n" . trim($error), 0, $exception);
        }
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
        count($reviewed)
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
