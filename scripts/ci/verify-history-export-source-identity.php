<?php

declare(strict_types=1);

use Workflow\V2\Conformance\PlatformArtifactSourceIdentity;

require dirname(__DIR__, 2) . '/src/V2/Conformance/PlatformArtifactSourceIdentity.php';

if ($argc < 3 || ! in_array($argv[1], ['resolver-url', 'verify'], true)) {
    fwrite(
        STDERR,
        "Usage:\n"
            . "  php verify-history-export-source-identity.php resolver-url <manifest.json>\n"
            . "  php verify-history-export-source-identity.php verify <manifest.json> <package-root> <resolver-file>\n",
    );
    exit(2);
}

/**
 * @return array<string, mixed>
 */
function readHistoryExportManifest(string $path): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Unable to read the Workflow conformance manifest at {$path}.");
    }

    $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest)) {
        throw new RuntimeException('The Workflow conformance manifest must decode to a JSON object.');
    }

    return $manifest;
}

try {
    $identity = PlatformArtifactSourceIdentity::fromManifest(readHistoryExportManifest($argv[2]));

    if ($argv[1] === 'resolver-url') {
        if ($argc !== 3) {
            throw new InvalidArgumentException('The resolver-url command accepts exactly one manifest path.');
        }

        echo $identity['resolver_url'];
        exit(0);
    }

    if ($argc !== 5) {
        throw new InvalidArgumentException(
            'The verify command requires a manifest path, package root, and downloaded resolver file.'
        );
    }

    $carrierFile = rtrim($argv[3], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $identity['carrier_path'];
    $resolverFile = $argv[4];
    if (! is_file($carrierFile)) {
        throw new RuntimeException('The packaged history-export schema carrier is missing.');
    }
    if (! is_file($resolverFile)) {
        throw new RuntimeException('The downloaded history-export schema resolver is missing.');
    }

    $carrierDigest = hash_file('sha256', $carrierFile);
    $resolverDigest = hash_file('sha256', $resolverFile);
    $carrierBytes = file_get_contents($carrierFile);
    $resolverBytes = file_get_contents($resolverFile);
    if (
        ! is_string($carrierDigest)
        || ! is_string($resolverDigest)
        || ! is_string($carrierBytes)
        || ! is_string($resolverBytes)
        || ! hash_equals($identity['sha256'], 'sha256:' . $carrierDigest)
        || ! hash_equals($carrierDigest, $resolverDigest)
        || ! hash_equals($carrierBytes, $resolverBytes)
    ) {
        throw new RuntimeException(
            'Published history-export resolver bytes do not match the packaged retained-origin binding.'
        );
    }

    printf(
        "Verified history-export carrier [%s] against retained origin [%s/%s] (%s).\n",
        $identity['carrier_path'],
        $identity['source_release'],
        $identity['origin_path'],
        $identity['sha256'],
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
