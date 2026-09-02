<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use RuntimeException;
use Workflow\V2\Support\PlatformConformanceSuite;

final class WorkerProtocolArtifactBindings
{
    private const RETAINED_API_BETA_PATH =
        'resources/conformance/suite-v38/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_API_PROTOCOL_113_PATH =
        'resources/conformance/suite-v41/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_STREAM_PROTOCOL_113_PATH =
        'resources/conformance/suite-v41/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml';

    private const RETAINED_API_PROTOCOL_115_PATH =
        'resources/conformance/suite-v43/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_STREAM_PROTOCOL_115_PATH =
        'resources/conformance/suite-v43/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml';

    private const RETAINED_API_PROTOCOL_116_PATH =
        'resources/conformance/suite-v44/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_STREAM_PROTOCOL_116_PATH =
        'resources/conformance/suite-v44/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml';

    private const RETAINED_API_PROTOCOL_117_PATH =
        'resources/conformance/suite-v45/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_STREAM_PROTOCOL_117_PATH =
        'resources/conformance/suite-v45/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml';

    private const RETAINED_API_PROTOCOL_118_PATH =
        'resources/conformance/suite-v46/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_STREAM_PROTOCOL_118_PATH =
        'resources/conformance/suite-v46/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml';

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function assertManifest(array $manifest): void
    {
        $historicalBetaResolver = 'https://raw.githubusercontent.com/durable-workflow/'
            . 'durable-workflow.github.io/e990bc36731463cc5b2cb2a9175dbccfdea61704/'
            . 'static/platform-protocol-specs/worker-protocol-api.openapi.yaml';
        $historicalProtocol113Base = 'https://raw.githubusercontent.com/durable-workflow/'
            . 'durable-workflow.github.io/' . PlatformConformanceSuite::PROTOCOL_SOURCE_REVISION
            . '/static/platform-protocol-specs/';
        $protocol115Base = 'https://durable-workflow.github.io/platform-protocol-specs/v1.15/';
        $protocol116Base = 'https://durable-workflow.github.io/platform-protocol-specs/v1.16/';
        $protocol117Base = 'https://durable-workflow.github.io/platform-protocol-specs/v1.17/';
        $protocol118Base = 'https://durable-workflow.github.io/platform-protocol-specs/v1.18/';
        $currentBase = 'https://durable-workflow.github.io/platform-protocol-specs/v1.19/';

        $expectedApi = [
            'history_mode' => 'immutable_lifecycle_bindings',
            'bindings' => [
                [
                    'suite_version' => 40,
                    'status' => 'historical',
                    'lifecycle' => 'beta',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-beta-history',
                    'resolver_url' => $historicalBetaResolver,
                    'sha256' => 'sha256:3166ce8ecb4c15005f1d98b1669f1ffaf3aeff7e19d0f006454525b2b19e4035',
                ],
                [
                    'suite_version' => 41,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-protocol-1.13-history',
                    'resolver_url' => $historicalProtocol113Base . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:55dfede6a9742f955911786eeb588ceecaa4266cebad57b92684c2a1bacefe7b',
                ],
                [
                    'suite_version' => 43,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-protocol-1.15-history',
                    'resolver_url' => $protocol115Base . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:d21a59e98ef46419b0792e716bd359c424a5759140474b838b1398083a291df6',
                ],
                [
                    'suite_version' => 44,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-protocol-1.16-history',
                    'resolver_url' => $protocol116Base . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:2dd330d52b8a36d1de0f364fc5f81311e2146f11ba1f77237e9c948e988c6817',
                ],
                [
                    'suite_version' => 45,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-protocol-1.17-history',
                    'resolver_url' => $protocol117Base . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:ebf84ff9443860085e503dfabbe0ccf7f313bed95b2261bef1e56abfbaab188e',
                ],
                [
                    'suite_version' => 46,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-protocol-1.18-history',
                    'resolver_url' => $protocol118Base . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:d704de374bf097bae08421ff293638af6e036f97184ac8f5b732f31dabf6c920',
                ],
                [
                    'suite_version' => PlatformConformanceSuite::VERSION,
                    'status' => 'current',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16',
                    'resolver_url' => $currentBase . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:2b25103fb2260ee8c97e8cb62ff18dfcb8c8091a79f4cc70bb5e4878375a079c',
                ],
            ],
        ];
        $expectedStream = [
            'history_mode' => 'immutable_lifecycle_bindings',
            'bindings' => [
                [
                    'suite_version' => 41,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16-protocol-1.13-history',
                    'resolver_url' => $historicalProtocol113Base . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:15bddb75d0e7183a520e861f87d5315b65e42acdc57a8137f947e00cacbac251',
                ],
                [
                    'suite_version' => 43,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16-protocol-1.15-history',
                    'resolver_url' => $protocol115Base . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:388fd30483c0bb52c6b39cee219be3c9fc933ff815ccf4a06f9063c85902b458',
                ],
                [
                    'suite_version' => 44,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16-protocol-1.16-history',
                    'resolver_url' => $protocol116Base . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:05c966ba9e328a8d73e769f1303bd1d456be363e6dbb22cfa592c5177c47b5d0',
                ],
                [
                    'suite_version' => 45,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16-protocol-1.17-history',
                    'resolver_url' => $protocol117Base . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:2111f2dbd158468e186bc5acca9ab3467910ff64fd44494cc60dda30d020f6df',
                ],
                [
                    'suite_version' => 46,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16-protocol-1.18-history',
                    'resolver_url' => $protocol118Base . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:4842cf99b4e7a036cdc0d96600a6b34ed79a626e6ac422ed9d26afe5ad10b02a',
                ],
                [
                    'suite_version' => PlatformConformanceSuite::VERSION,
                    'status' => 'current',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16',
                    'resolver_url' => $currentBase . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:9b85abd60e1a9c5d41a134691f474e4665726f18f4b51d0851affbb0519582b1',
                ],
            ],
        ];

        if (
            ($manifest['artifact_version_history']['worker_protocol_api'] ?? null) !== $expectedApi
            || ($manifest['artifact_version_history']['worker_protocol_stream'] ?? null) !== $expectedStream
        ) {
            throw new RuntimeException(
                'Worker protocol artifact history must retain prior bytes and identify the current 1.19 authority.'
            );
        }

        self::assertRetainedBinding(self::RETAINED_API_BETA_PATH, $expectedApi['bindings'][0]['sha256']);
        self::assertRetainedBinding(self::RETAINED_API_PROTOCOL_113_PATH, $expectedApi['bindings'][1]['sha256']);
        self::assertRetainedBinding(
            self::RETAINED_STREAM_PROTOCOL_113_PATH,
            $expectedStream['bindings'][0]['sha256'],
        );
        self::assertRetainedBinding(self::RETAINED_API_PROTOCOL_115_PATH, $expectedApi['bindings'][2]['sha256']);
        self::assertRetainedBinding(
            self::RETAINED_STREAM_PROTOCOL_115_PATH,
            $expectedStream['bindings'][1]['sha256'],
        );
        self::assertRetainedBinding(self::RETAINED_API_PROTOCOL_116_PATH, $expectedApi['bindings'][3]['sha256']);
        self::assertRetainedBinding(
            self::RETAINED_STREAM_PROTOCOL_116_PATH,
            $expectedStream['bindings'][2]['sha256'],
        );
        self::assertRetainedBinding(self::RETAINED_API_PROTOCOL_117_PATH, $expectedApi['bindings'][4]['sha256']);
        self::assertRetainedBinding(
            self::RETAINED_STREAM_PROTOCOL_117_PATH,
            $expectedStream['bindings'][3]['sha256'],
        );
        self::assertRetainedBinding(self::RETAINED_API_PROTOCOL_118_PATH, $expectedApi['bindings'][5]['sha256']);
        self::assertRetainedBinding(
            self::RETAINED_STREAM_PROTOCOL_118_PATH,
            $expectedStream['bindings'][4]['sha256'],
        );

        $activeSources = $manifest['fixture_catalog']['worker_task_lifecycle']['sources'] ?? [];
        self::assertActiveBinding($activeSources, $expectedApi['bindings'][6]);
        self::assertActiveBinding($activeSources, $expectedStream['bindings'][5]);
    }

    private static function assertRetainedBinding(string $path, string $expectedDigest): void
    {
        $digest = hash_file('sha256', dirname(__DIR__, 3) . '/' . $path);
        if ($digest === false || ! hash_equals($expectedDigest, 'sha256:' . $digest)) {
            throw new RuntimeException('Historical worker protocol authority does not match its retained bytes.');
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private static function assertActiveBinding(mixed $sources, array $binding): void
    {
        $active = array_values(array_filter(
            is_array($sources) ? $sources : [],
            static fn (mixed $source): bool => is_array($source)
                && ($source['artifact_id'] ?? null) === $binding['artifact_id'],
        ));
        unset($binding['suite_version'], $binding['status'], $binding['lifecycle']);

        if (count($active) !== 1 || $active[0] !== $binding) {
            throw new RuntimeException('Active worker protocol source must match its current retained binding.');
        }
    }
}
