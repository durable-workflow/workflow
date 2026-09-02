<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php list-worker-protocol-resolvers.php <platform-conformance-manifest.json>\n");
    exit(2);
}

$json = file_get_contents($argv[1]);

if ($json === false) {
    fwrite(STDERR, "Unable to read Workflow platform conformance manifest at {$argv[1]}.\n");
    exit(1);
}

try {
    $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "Workflow platform conformance manifest is not valid JSON: {$exception->getMessage()}\n");
    exit(1);
}

$histories = $manifest['artifact_version_history'] ?? null;

if (! is_array($histories)) {
    fwrite(STDERR, "Workflow platform conformance manifest has no artifact version history.\n");
    exit(1);
}

$count = 0;

foreach (['worker_protocol_api', 'worker_protocol_stream'] as $historyName) {
    $bindings = $histories[$historyName]['bindings'] ?? null;

    if (! is_array($bindings) || $bindings === []) {
        fwrite(STDERR, "Workflow platform conformance manifest has no {$historyName} resolver bindings.\n");
        exit(1);
    }

    foreach ($bindings as $binding) {
        if (! is_array($binding)) {
            fwrite(STDERR, "Workflow platform conformance {$historyName} contains a malformed binding.\n");
            exit(1);
        }

        $artifactId = $binding['artifact_id'] ?? null;
        $resolverUrl = $binding['resolver_url'] ?? null;
        $digest = $binding['sha256'] ?? null;

        if (
            ! is_string($artifactId)
            || $artifactId === ''
            || strpbrk($artifactId, "\t\r\n") !== false
            || ! is_string($resolverUrl)
            || $resolverUrl === ''
            || strpbrk($resolverUrl, "\t\r\n") !== false
            || ! is_string($digest)
            || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1
        ) {
            fwrite(STDERR, "Workflow platform conformance {$historyName} contains an invalid resolver binding.\n");
            exit(1);
        }

        printf("%s\t%s\t%s\n", $artifactId, $resolverUrl, $digest);
        $count++;
    }
}

if ($count === 0) {
    fwrite(STDERR, "Workflow platform conformance manifest advertises no worker protocol resolvers.\n");
    exit(1);
}
