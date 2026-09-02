<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php compare-platform-conformance-mirrors.php <workflow-mirror.json> <public-authority.json>\n");
    exit(2);
}

/**
 * @return array<string, mixed>
 */
function readManifest(string $path, string $label): array
{
    $json = file_get_contents($path);

    if ($json === false) {
        throw new RuntimeException("Unable to read {$label} at {$path}.");
    }

    $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($manifest)) {
        throw new RuntimeException("{$label} must decode to a JSON object.");
    }

    return $manifest;
}

try {
    $workflow = readManifest($argv[1], 'Workflow platform conformance mirror');
    $authority = readManifest($argv[2], 'public platform conformance authority');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

if ($workflow !== $authority) {
    fwrite(
        STDERR,
        sprintf(
            "Platform conformance mirror divergence: Workflow suite %s does not exactly match public suite %s.\n",
            json_encode($workflow['version'] ?? null),
            json_encode($authority['version'] ?? null),
        ),
    );
    exit(1);
}

$digest = hash('sha256', json_encode($authority, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
printf("Workflow and public docs expose identical platform conformance suite %d semantics (sha256:%s).\n", $authority['version'], $digest);
