<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use RuntimeException;

/**
 * Immutable origin identity for a retained platform artifact.
 *
 * The carrier path follows the current conformance suite. The origin tuple
 * remains fixed until the artifact bytes themselves advance.
 */
final class PlatformArtifactSourceIdentity
{
    public const FILENAME = 'history-export-bundle.schema.json';

    private const ORIGIN = [
        'suite_version' => 43,
        'schema_version' => 2,
        'source_release' => '2.0.0-rc.42',
        'origin_path' => 'resources/conformance/suite-v43/platform-protocol-specs/'
            . self::FILENAME,
        'artifact_id' => 'durable-workflow.v2.history-export-bundle@workflow-2.0.0-rc.42-schema-2',
        'resolver_url' => 'https://raw.githubusercontent.com/durable-workflow/workflow/2.0.0-rc.42/'
            . 'resources/conformance/suite-v43/platform-protocol-specs/' . self::FILENAME,
        'sha256' => 'sha256:29f9842ca426f231e79a454e95e23520e5b51b5d8f8453fb0c27f278d68bb21b',
    ];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     carrier_path: string,
     *     origin_path: string,
     *     source_release: string,
     *     artifact_id: string,
     *     resolver_url: string,
     *     sha256: string
     * }
     */
    public static function fromManifest(array $manifest): array
    {
        $suiteVersion = $manifest['version'] ?? null;
        if (! is_int($suiteVersion) || $suiteVersion < 1) {
            throw new RuntimeException('Platform conformance suite version is unavailable for source validation.');
        }

        $dependencies = $manifest['source_dependencies'] ?? null;
        $dependency = is_array($dependencies) ? ($dependencies[self::FILENAME] ?? null) : null;
        if (! is_array($dependency)) {
            throw new RuntimeException('The history-export schema source identity is missing.');
        }

        $carrierPath = $dependency['source_path'] ?? null;
        $expectedCarrierPath = sprintf(
            'resources/conformance/suite-v%d/platform-protocol-specs/%s',
            $suiteVersion,
            self::FILENAME,
        );
        if (! is_string($carrierPath) || $carrierPath !== $expectedCarrierPath) {
            throw new RuntimeException(
                'The history-export schema carrier path does not match the packaged conformance suite.'
            );
        }

        foreach (['source_release', 'artifact_id', 'resolver_url', 'sha256'] as $field) {
            if (($dependency[$field] ?? null) !== self::ORIGIN[$field]) {
                throw new RuntimeException(
                    "The history-export schema retained origin has an invalid [{$field}] binding."
                );
            }
        }

        $history = $manifest['artifact_version_history']['history_export_bundle']['bindings'] ?? null;
        if (! is_array($history)) {
            throw new RuntimeException('The history-export schema binding history is missing.');
        }

        $currentBindings = array_values(array_filter(
            $history,
            static fn (mixed $binding): bool => is_array($binding) && ($binding['status'] ?? null) === 'current',
        ));
        if (count($currentBindings) !== 1) {
            throw new RuntimeException('The history-export schema must have exactly one current origin binding.');
        }

        $current = $currentBindings[0];
        foreach (
            ['suite_version', 'schema_version', 'source_release', 'artifact_id', 'resolver_url', 'sha256']
            as $field
        ) {
            if (($current[$field] ?? null) !== self::ORIGIN[$field]) {
                throw new RuntimeException(
                    "The current history-export schema history has an invalid [{$field}] origin binding."
                );
            }
        }

        return [
            'carrier_path' => $carrierPath,
            'origin_path' => self::ORIGIN['origin_path'],
            'source_release' => self::ORIGIN['source_release'],
            'artifact_id' => self::ORIGIN['artifact_id'],
            'resolver_url' => self::ORIGIN['resolver_url'],
            'sha256' => self::ORIGIN['sha256'],
        ];
    }
}
