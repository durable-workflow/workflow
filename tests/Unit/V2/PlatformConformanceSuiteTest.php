<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Workflow\V2\Conformance\PlatformArtifactSourceIdentity;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\SurfaceStabilityContract;

final class PlatformConformanceSuiteTest extends TestCase
{
    private const RUST_SIGNAL_QUERY_BINDING_PROVENANCE = [
        [
            29,
            '0.1.2',
            '009c0257964f33705941466d09777172068b3a26',
            '793c8e6f63310c51aa97380466a58bd68b4c90dc2277351a3bf7ba60be794cba',
        ],
        [
            30,
            '0.1.2',
            '3fa9bff54c8ccef5537a885b167e470a629661b9',
            '1807509b4a56463c37998e91e433ff7cf79c49c9eb9722d6f36fefb38ac615a0',
        ],
        [
            31,
            '2.0.0-beta.3',
            '4275fd11d80e88d4414383e4338144c228eb5a78',
            'eb79b471e654b14a517a077d526b085c56ac55b17405233df2ffbdf11e32e64b',
        ],
        [
            32,
            '2.0.0-beta.4',
            '187746bb19615a8cbb25dfbe1e4e27dbbd933472',
            '4acdcb70da1cebb77a44edb7cce68ef1d0315159d289c94af2f57f526c3cbbea',
        ],
        [
            33,
            '2.0.0-beta.5',
            '08fab5ff5c51fd31ce8306b39edb10996d5a8531',
            'd809264b6394935c0c4b3c30e6ba50252fba0c6743a81a2c747c39a28830277d',
        ],
        [
            34,
            '2.0.0-beta.6',
            '5191a97d90e3e476c4e6a51e90faa559868e4c70',
            'd067aa5e750a804d67fb501704a46394488309e33f6df461ba000b41530a87a0',
        ],
        [
            34,
            '2.0.0-beta.10',
            '0fddbec98b94a5b542480d746759a2c695bba2be',
            '6a90b3ba55b43feb332fb895a23045fdaf85b23357c1bbeff2e79321cd4afca8',
        ],
        [
            34,
            '2.0.0-beta.13',
            '0561e96950c68beabb3535a2f65f7403209885a5',
            'c2e567ba37e68354256e680a53b0890ede7e8f3b69d2ed9aede33ad8aa0af8a4',
        ],
        [
            34,
            '2.0.0-beta.14',
            'ef844b34dfec8cfe54d4bc699fc21d80574ce028',
            'ecc7c1b8427dd89fc370f7aafdd1a5d6089c8c60559f61af112d8d92e516dece',
        ],
        [
            34,
            '2.0.0-beta.16',
            '68262eb8589e8e1142c2e158f50815950a347ef8',
            '4f304b9d2dae9b3f71b800b49d22b7ae4c60fd69e37bece224ccca5818911222',
        ],
        [
            34,
            '2.0.0-beta.17',
            'f1ef7d4edd8b1cea28192bfe360d3a233721c0ca',
            '86cff1043fb2c97490b08a9fee0e6ca993eb2c3f4f03b863c61e4ffd5188cbaf',
        ],
        [
            34,
            '2.0.0-beta.18',
            '8853baf7d42e2bbdf08ed101dc0ba4e7bb0f4a31',
            '8285966e7ed1eb20942ea24bc008725e80e737a895ae43a0c69fdd13728531d5',
        ],
        [
            34,
            '2.0.0-beta.21',
            '636ff3fc90c1a01c8ee74becaa148c9e193969ea',
            'a60b114a3ad2285c4a9796d72de29919d3ba84e713f20fb0f6fa705aa957e525',
        ],
        [
            34,
            '2.0.0-rc.1',
            '864cd6f2e11a60ddbd221548019df8ef0cd8f812',
            'dba857beb24d0cd75adb7146d6e17b7728fb432e7d7e004a3a2553f630eb94cc',
        ],
        [
            34,
            '2.0.0-rc.2',
            '961bc3675b2c1c35577b66bccc77b2e4f4485369',
            '087764682d0f80e6f8f329baa0cab6adec1cfe3733083383dd1d2159cc607457',
        ],
        [
            34,
            '2.0.0-rc.1',
            '6c137c89f5b0efdfc5f5720ef81005dd67751aad',
            'dba857beb24d0cd75adb7146d6e17b7728fb432e7d7e004a3a2553f630eb94cc',
        ],
        [
            35,
            '2.0.0-rc.3',
            '2810085732928e0bae9a7ae16cc55149ad721635',
            'fa4664b9cc826c3573e131a7b88d62b1dbee761d2b1d0e93d3aacb6a5e64cb11',
        ],
        [
            36,
            '2.0.0-rc.4',
            'dd07456686d367c215e2637f586436b9710d7b35',
            'd256640d23b07c3d1a88e40854056074d8abd154360190712f67507c027ca8fc',
        ],
        [
            36,
            '2.0.0-rc.5',
            '257a758c4b20094246ec676f0340bb392cf867c6',
            '4679879192043c5aa725d8e9770719c41a2b991e5dfc565ca3e5c33670ffd0b9',
        ],
        [
            37,
            '2.0.0-rc.5',
            'e779bf19aeb78bdaeb020b23ac80b576ec125af8',
            '3469169d0df27e80386ceca489826200620d6dfc87f42e4deae0949da377652c',
        ],
    ];

    private const RUST_SIGNAL_QUERY_SCENARIOS = [
        'rust_worker_rust_php_python_clients',
        'python_worker_rust_client',
        'php_worker_rust_client',
        'rust_query_error_and_immutability',
        'rust_replayed_instance_state_query_after_cold_restart',
    ];

    public function testManifestExactlyMatchesCommittedPublicAuthorityFixture(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/platform-conformance-contract.json';
        $json = file_get_contents($path);

        $this->assertIsString($json);
        $this->assertSame(
            PlatformConformanceSuite::MIRROR_SHA256,
            hash('sha256', $json),
            'Changing any suite semantics requires a new reviewed authority digest.',
        );

        $authority = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($authority, PlatformConformanceSuite::manifest());
        $this->assertSame(47, $authority['version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $authority['version']);
        $this->assertSame(PlatformConformanceSuite::SCHEMA, $authority['schema']);
        $this->assertSame(SurfaceStabilityContract::SCHEMA, $authority['surface_stability_authority']);
    }

    public function testTargetAndCategorySemanticsAreCompleteAndInternallyResolvable(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $categories = array_keys($manifest['fixture_catalog']);

        $this->assertSame(array_keys($manifest['targets']), PlatformConformanceSuite::targetNames());
        $this->assertSame($categories, PlatformConformanceSuite::fixtureCategoryNames());

        foreach ($manifest['targets'] as $targetName => $target) {
            foreach ($target['required_surface_families'] as $surfaceFamily) {
                $this->assertContains(
                    $surfaceFamily,
                    $surfaceFamilies,
                    "{$targetName} references an unknown surface family.",
                );
            }

            foreach ($target['required_fixture_categories'] as $category) {
                $this->assertContains(
                    $category,
                    $categories,
                    "{$targetName} references an unknown fixture category.",
                );
            }
        }

        foreach ($manifest['fixture_catalog'] as $categoryName => $category) {
            $this->assertContains(
                $category['status'],
                [
                    PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
                    PlatformConformanceSuite::CATEGORY_STATUS_PROVISIONAL,
                ],
                "{$categoryName} has an unknown stability status.",
            );
            $this->assertNotEmpty($category['sources'], "{$categoryName} must declare a source.");
        }
    }

    public function testStableFixtureSourcesUseImmutableRevisionAndMatchVendoredBytes(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $runtimeSourcePrefix = sprintf(
            'https://raw.githubusercontent.com/durable-workflow/workflow/%s/',
            PlatformConformanceSuite::RUNTIME_SOURCE_REVISION,
        );
        $protocolSourcePrefix = sprintf(
            'https://raw.githubusercontent.com/durable-workflow/durable-workflow.github.io/%s/'
                . 'static/platform-protocol-specs/',
            PlatformConformanceSuite::PROTOCOL_SOURCE_REVISION,
        );

        foreach ($manifest['fixture_catalog'] as $category) {
            if ($category['status'] !== PlatformConformanceSuite::CATEGORY_STATUS_STABLE) {
                continue;
            }

            foreach ($category['sources'] as $source) {
                if (str_starts_with(
                    $source['resolver_url'],
                    'https://durable-workflow.github.io/cli-json-envelopes/v3/',
                )) {
                    $this->assertMatchesRegularExpression(
                        '#^https://durable-workflow\.github\.io/cli-json-envelopes/v3/'
                            . '(?:manifest\.json|schemas/[a-z0-9.-]+\.schema\.json)$#',
                        $source['resolver_url'],
                    );
                } elseif (str_contains($source['resolver_url'], '/platform-conformance/')) {
                    $this->assertMatchesRegularExpression(
                        '#^' . preg_quote($runtimeSourcePrefix, '#')
                            . 'resources/conformance/suite-v38/platform-conformance/'
                            . '[a-z0-9.-]+\.json$#',
                        $source['resolver_url'],
                    );
                } elseif (str_starts_with(
                    $source['resolver_url'],
                    'https://durable-workflow.github.io/platform-protocol-specs/v1.19/',
                )) {
                    $this->assertMatchesRegularExpression(
                        '#^https://durable-workflow\.github\.io/platform-protocol-specs/v1\.19/'
                            . 'worker-protocol-(?:api\.openapi|stream\.asyncapi)\.yaml$#',
                        $source['resolver_url'],
                    );
                } else {
                    $this->assertMatchesRegularExpression(
                        '#^' . preg_quote($protocolSourcePrefix, '#') . '[a-z0-9.-]+\.(?:json|ya?ml)$#',
                        $source['resolver_url'],
                    );
                }
            }
        }
    }

    public function testWorkerProtocolHistoryPreservesPriorEvidenceAndAdvancesCurrentAuthority(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $apiHistory = $manifest['artifact_version_history']['worker_protocol_api'];
        $streamHistory = $manifest['artifact_version_history']['worker_protocol_stream'];
        $beta = $apiHistory['bindings'][0];
        $protocol113Api = $apiHistory['bindings'][1];
        $protocol115Api = $apiHistory['bindings'][2];
        $protocol116Api = $apiHistory['bindings'][3];
        $protocol117Api = $apiHistory['bindings'][4];
        $protocol118Api = $apiHistory['bindings'][5];
        $currentApi = $apiHistory['bindings'][6];
        $protocol113Stream = $streamHistory['bindings'][0];
        $protocol115Stream = $streamHistory['bindings'][1];
        $protocol116Stream = $streamHistory['bindings'][2];
        $protocol117Stream = $streamHistory['bindings'][3];
        $protocol118Stream = $streamHistory['bindings'][4];
        $currentStream = $streamHistory['bindings'][5];
        $activeSources = $manifest['fixture_catalog']['worker_task_lifecycle']['sources'];

        $this->assertSame('immutable_lifecycle_bindings', $apiHistory['history_mode']);
        $this->assertSame('immutable_lifecycle_bindings', $streamHistory['history_mode']);
        $this->assertSame('historical', $beta['status']);
        $this->assertSame('durable-workflow.v2.worker-protocol-api@catalog-16-beta-history', $beta['artifact_id']);
        $this->assertSame('historical', $protocol113Api['status']);
        $this->assertSame('historical', $protocol113Stream['status']);
        $this->assertSame('historical', $protocol115Api['status']);
        $this->assertSame('historical', $protocol115Stream['status']);
        $this->assertSame('historical', $protocol116Api['status']);
        $this->assertSame('historical', $protocol116Stream['status']);
        $this->assertSame('historical', $protocol117Api['status']);
        $this->assertSame('historical', $protocol117Stream['status']);
        $this->assertSame('historical', $protocol118Api['status']);
        $this->assertSame('historical', $protocol118Stream['status']);
        $this->assertSame('current', $currentApi['status']);
        $this->assertSame('current', $currentStream['status']);
        $this->assertSame('lifecycle_neutral', $currentApi['lifecycle']);
        $this->assertSame('lifecycle_neutral', $currentStream['lifecycle']);
        $this->assertCount(2, $activeSources);

        foreach ([$currentApi, $currentStream] as $index => $current) {
            foreach (['suite_version', 'status', 'lifecycle'] as $historyField) {
                unset($current[$historyField]);
            }
            $this->assertSame($current, $activeSources[$index]);
        }

        $root = dirname(__DIR__, 3);
        $this->assertSame(
            $beta['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v38/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol113Api['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v41/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol113Stream['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v41/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol115Api['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v43/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol115Stream['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v43/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol116Api['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v44/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol116Stream['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v44/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol117Api['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v45/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol117Stream['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v45/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol118Api['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v46/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $protocol118Stream['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v46/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ),
        );
        $this->assertSame(
            $currentApi['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v47/platform-protocol-specs/worker-protocol-api.openapi.yaml',
            ),
        );
        $this->assertSame(
            $currentStream['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v47/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml',
            ),
        );
    }

    public function testCliJsonEnvelopeManifestBindsItsCompletePublishedSchemaClosure(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['cli_json_envelopes'];
        $manifestSource = array_values(array_filter(
            $category['sources'],
            static fn (array $source): bool =>
                $source['artifact_id'] === 'durable-workflow.cli.output-schema-manifest@3',
        ));
        $schemaSources = array_values(array_filter(
            $category['sources'],
            static fn (array $source): bool =>
                str_starts_with($source['artifact_id'], 'durable-workflow.cli.output-schema/'),
        ));

        $this->assertCount(1, $manifestSource);
        $this->assertCount(61, $schemaSources);

        $publishedManifest = json_decode(
            file_get_contents(
                dirname(__DIR__, 3)
                    . '/resources/conformance/suite-v40/cli-json-envelopes/manifest.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertCount(79, $publishedManifest['commands']);
        $this->assertCount(12, $publishedManifest['jsonl_commands']);
        $this->assertSame(
            ['--output=jsonl'],
            array_values(array_unique(array_column($publishedManifest['jsonl_commands'], 'output'))),
        );

        $validator = new ReflectionMethod(PlatformConformanceSuite::class, 'assertCliJsonEnvelopeClosure');
        $validator->invoke(null, $manifest);
        $this->addToAssertionCount(1);
    }

    public function testCliJsonEnvelopeClosureRejectsMissingAndDriftingBindings(): void
    {
        $validator = new ReflectionMethod(PlatformConformanceSuite::class, 'assertCliJsonEnvelopeClosure');

        foreach ([
            'missing JSONL schema' => static function (array &$manifest): void {
                $manifest['fixture_catalog']['cli_json_envelopes']['sources'] = array_values(array_filter(
                    $manifest['fixture_catalog']['cli_json_envelopes']['sources'],
                    static fn (array $source): bool =>
                        ! str_contains($source['artifact_id'], 'workflow-list-record.schema.json'),
                ));
            },
            'incorrect schema digest' => static function (array &$manifest): void {
                foreach ($manifest['fixture_catalog']['cli_json_envelopes']['sources'] as &$source) {
                    if (str_contains($source['artifact_id'], 'workflow-start.schema.json')) {
                        $source['sha256'] = 'sha256:' . str_repeat('0', 64);
                    }
                }
                unset($source);
            },
            'mutable manifest resolver' => static function (array &$manifest): void {
                foreach ($manifest['fixture_catalog']['cli_json_envelopes']['sources'] as &$source) {
                    if ($source['artifact_id'] === 'durable-workflow.cli.output-schema-manifest@3') {
                        $source['resolver_url'] =
                            'https://durable-workflow.github.io/cli-json-envelopes/current/manifest.json';
                    }
                }
                unset($source);
            },
            'unrelated source' => static function (array &$manifest): void {
                $source = $manifest['fixture_catalog']['cli_json_envelopes']['sources'][1];
                $source['artifact_id'] = 'durable-workflow.example.unrelated@1';
                $manifest['fixture_catalog']['cli_json_envelopes']['sources'][] = $source;
            },
        ] as $case => $mutate) {
            $manifest = PlatformConformanceSuite::manifest();
            $mutate($manifest);

            try {
                $validator->invoke(null, $manifest);
                $this->fail("CLI JSON envelope closure accepted {$case}.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStableSourceDependenciesUseImmutableResolversAndMatchVendoredBytes(): void
    {
        $dependencies = PlatformConformanceSuite::manifest()['source_dependencies'];
        $expectedDigests = [
            'cluster-info-envelope.schema.json' =>
                '7f761da2eda221a6240d1250b6dd774a36c2077642405f0f036e8124121ea4bc',
            'history-export-bundle.schema.json' =>
                '29f9842ca426f231e79a454e95e23520e5b51b5d8f8453fb0c27f278d68bb21b',
            'local-activity-runtime.schema.json' =>
                'de74d7175cda4f761a57263aae2b32046e617783acf0677d4c5aa6c5358619ef',
            'worker-sessions-runtime.schema.json' =>
                '36b16340fe9524653baef7de0a32b2f744562bfc57e91853579a2c94dd512581',
        ];
        $this->assertSame(array_keys($expectedDigests), array_keys($dependencies));

        foreach ($expectedDigests as $filename => $expectedDigest) {
            $dependency = $dependencies[$filename];
            $expectedResolver = $filename === 'history-export-bundle.schema.json'
                ? sprintf(
                    'https://raw.githubusercontent.com/durable-workflow/workflow/%s/'
                        . 'resources/conformance/suite-v43/platform-protocol-specs/'
                        . 'history-export-bundle.schema.json',
                    $dependency['source_release'],
                )
                : sprintf(
                    'https://raw.githubusercontent.com/durable-workflow/durable-workflow.github.io/%s/'
                        . 'static/platform-protocol-specs/%s',
                    PlatformConformanceSuite::PROTOCOL_SOURCE_REVISION,
                    $filename,
                );

            $this->assertSame($expectedResolver, $dependency['resolver_url']);
            if ($filename === 'history-export-bundle.schema.json') {
                $this->assertSame('2.0.0-rc.42', $dependency['source_release']);
            } else {
                $this->assertArrayNotHasKey('source_release', $dependency);
            }
            $this->assertSame('sha256:' . $expectedDigest, $dependency['sha256']);
            $this->assertSame(
                $expectedDigest,
                hash('sha256', file_get_contents(dirname(__DIR__, 3) . '/' . $dependency['source_path'])),
            );
        }
    }

    public function testHistoryExportSchemaHistoryRetainsVersionOneAndBindsVersionTwo(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $history = $manifest['artifact_version_history']['history_export_bundle'];
        $historical = $history['bindings'][0];
        $current = $history['bindings'][1];
        $dependency = $manifest['source_dependencies']['history-export-bundle.schema.json'];
        $root = dirname(__DIR__, 3);
        $this->assertSame('immutable_prerelease_schema_bindings', $history['history_mode']);
        $this->assertSame(42, $historical['suite_version']);
        $this->assertSame('historical', $historical['status']);
        $this->assertSame(1, $historical['schema_version']);
        $this->assertSame(
            $historical['sha256'],
            'sha256:' . hash_file(
                'sha256',
                $root . '/resources/conformance/suite-v42/platform-protocol-specs/'
                    . 'history-export-bundle.schema.json',
            ),
        );

        $this->assertSame(43, $current['suite_version']);
        $this->assertSame('current', $current['status']);
        $this->assertSame(2, $current['schema_version']);
        $this->assertSame('2.0.0-rc.42', $current['source_release']);
        $this->assertSame(
            sprintf('durable-workflow.v2.history-export-bundle@workflow-%s-schema-2', $current['source_release']),
            $current['artifact_id'],
        );
        foreach (['artifact_id', 'resolver_url', 'source_release', 'sha256'] as $field) {
            $this->assertSame($current[$field], $dependency[$field]);
        }

        $schema = json_decode(
            file_get_contents($root . '/' . $dependency['source_path']),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $workflow = $schema['$defs']['workflowSnapshot'];
        $memoProjection = $schema['$defs']['memoJsonProjection'];
        $memoEnvelope = $schema['$defs']['memoPayloadEnvelope'];

        $this->assertSame(2, $schema['properties']['schema_version']['const']);
        $this->assertContains('memo', $workflow['required']);
        $this->assertContains('memo_payload', $workflow['required']);
        $this->assertArrayHasKey('memo_payload', $workflow['properties']);
        $this->assertSame(
            '#/$defs/memoJsonProjection',
            $schema['allOf'][0]['else']['properties']['workflow']['properties']['memo']['$ref'],
        );
        $this->assertSame(
            '#/$defs/memoPayloadEnvelope',
            $schema['allOf'][1]['else']['properties']['workflow']['properties']['memo_payload']['$ref'],
        );
        $this->assertSame(0, $memoProjection['oneOf'][1]['maxItems']);
        $this->assertSame(['codec', 'blob'], $memoEnvelope['required']);
        $this->assertFalse($memoEnvelope['additionalProperties']);
        $this->assertSame('avro', $memoEnvelope['properties']['codec']['const']);
        $this->assertSame('base64', $memoEnvelope['properties']['blob']['contentEncoding']);
    }

    public function testHistoryExportSourceIdentitySeparatesCurrentCarrierFromRetainedOrigin(): void
    {
        $root = dirname(__DIR__, 3);
        $identity = PlatformArtifactSourceIdentity::fromManifest(PlatformConformanceSuite::manifest());

        $this->assertSame(
            'resources/conformance/suite-v47/platform-protocol-specs/history-export-bundle.schema.json',
            $identity['carrier_path'],
        );
        $this->assertSame(
            'resources/conformance/suite-v43/platform-protocol-specs/history-export-bundle.schema.json',
            $identity['origin_path'],
        );
        $this->assertSame('2.0.0-rc.42', $identity['source_release']);
        $this->assertNotSame($identity['carrier_path'], $identity['origin_path']);

        $process = new Process([
            PHP_BINARY,
            $root . '/scripts/ci/verify-history-export-source-identity.php',
            'verify',
            $root . '/resources/platform-conformance-contract.json',
            $root,
            $root . '/' . $identity['origin_path'],
        ]);
        $process->mustRun();

        $this->assertStringContainsString('Verified history-export carrier', $process->getOutput());
    }

    public function testHistoryExportSourceVerificationRejectsChangedResolverBytes(): void
    {
        $root = dirname(__DIR__, 3);
        $identity = PlatformArtifactSourceIdentity::fromManifest(PlatformConformanceSuite::manifest());
        $resolver = tempnam(sys_get_temp_dir(), 'workflow-history-origin-');
        if ($resolver === false) {
            $this->fail('Unable to create a temporary retained-origin fixture.');
        }

        try {
            $originBytes = file_get_contents($root . '/' . $identity['origin_path']);
            $this->assertIsString($originBytes);
            file_put_contents($resolver, $originBytes . "\n");

            $process = new Process([
                PHP_BINARY,
                $root . '/scripts/ci/verify-history-export-source-identity.php',
                'verify',
                $root . '/resources/platform-conformance-contract.json',
                $root,
                $resolver,
            ]);
            $process->run();

            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('resolver bytes do not match', $process->getErrorOutput());
        } finally {
            @unlink($resolver);
        }
    }

    public function testStableSourceReferenceClosureIsCompleteAndBound(): void
    {
        $validator = new ReflectionMethod(PlatformConformanceSuite::class, 'assertStableSourceReferenceClosure');

        $validator->invoke(null, PlatformConformanceSuite::manifest());

        $this->addToAssertionCount(1);
    }

    public function testStableSourceReferenceClosureRejectsInvalidDependencyBindings(): void
    {
        $validator = new ReflectionMethod(PlatformConformanceSuite::class, 'assertStableSourceReferenceClosure');

        foreach ([
            'missing immediate dependency' => static function (array &$manifest): void {
                unset($manifest['source_dependencies']['cluster-info-envelope.schema.json']);
            },
            'missing transitive dependency' => static function (array &$manifest): void {
                unset($manifest['source_dependencies']['local-activity-runtime.schema.json']);
            },
            'path escape' => static function (array &$manifest): void {
                $manifest['source_dependencies']['cluster-info-envelope.schema.json']['source_path'] =
                    'resources/conformance/suite-v38/platform-protocol-specs/../cluster-info-envelope.schema.json';
            },
            'mutable resolver' => static function (array &$manifest): void {
                $manifest['source_dependencies']['cluster-info-envelope.schema.json']['resolver_url'] =
                    'https://durable-workflow.github.io/platform-protocol-specs/cluster-info-envelope.schema.json';
            },
            'non-HTTPS resolver' => static function (array &$manifest): void {
                $manifest['source_dependencies']['cluster-info-envelope.schema.json']['resolver_url'] =
                    str_replace(
                        'https://',
                        'http://',
                        $manifest['source_dependencies']['cluster-info-envelope.schema.json']['resolver_url'],
                    );
            },
            'incorrect digest' => static function (array &$manifest): void {
                $manifest['source_dependencies']['cluster-info-envelope.schema.json']['sha256'] =
                    'sha256:' . str_repeat('0', 64);
            },
            'missing retained source release' => static function (array &$manifest): void {
                unset($manifest['source_dependencies']['history-export-bundle.schema.json']['source_release']);
            },
            'packaged carrier path drift' => static function (array &$manifest): void {
                $manifest['source_dependencies']['history-export-bundle.schema.json']['source_path'] =
                    str_replace(
                        'suite-v47',
                        'suite-v46',
                        $manifest['source_dependencies']['history-export-bundle.schema.json']['source_path'],
                    );
            },
            'retained origin path drift with matching release and digest' => static function (array &$manifest): void {
                $resolver = str_replace(
                    'suite-v43',
                    'suite-v44',
                    $manifest['source_dependencies']['history-export-bundle.schema.json']['resolver_url'],
                );
                $manifest['source_dependencies']['history-export-bundle.schema.json']['resolver_url'] = $resolver;
                $manifest['artifact_version_history']['history_export_bundle']['bindings'][1]['resolver_url'] =
                    $resolver;
            },
            'retained origin resolver drift with matching release and digest' => static function (
                array &$manifest
            ): void {
                $resolver = $manifest['source_dependencies']['history-export-bundle.schema.json']['resolver_url']
                    . '?mutable=1';
                $manifest['source_dependencies']['history-export-bundle.schema.json']['resolver_url'] = $resolver;
                $manifest['artifact_version_history']['history_export_bundle']['bindings'][1]['resolver_url'] =
                    $resolver;
            },
            'retained origin release drift with matching digest' => static function (array &$manifest): void {
                $dependency = &$manifest['source_dependencies']['history-export-bundle.schema.json'];
                $current = &$manifest['artifact_version_history']['history_export_bundle']['bindings'][1];
                $dependency['source_release'] = '2.0.0-rc.43';
                $dependency['artifact_id'] = str_replace('2.0.0-rc.42', '2.0.0-rc.43', $dependency['artifact_id']);
                $dependency['resolver_url'] = str_replace(
                    '2.0.0-rc.42',
                    '2.0.0-rc.43',
                    $dependency['resolver_url'],
                );
                $current['source_release'] = $dependency['source_release'];
                $current['artifact_id'] = $dependency['artifact_id'];
                $current['resolver_url'] = $dependency['resolver_url'];
                unset($dependency, $current);
            },
            'retained origin digest declaration drift' => static function (array &$manifest): void {
                $digest = 'sha256:' . str_repeat('0', 64);
                $manifest['source_dependencies']['history-export-bundle.schema.json']['sha256'] = $digest;
                $manifest['artifact_version_history']['history_export_bundle']['bindings'][1]['sha256'] = $digest;
            },
            'resolver retained release drift' => static function (array &$manifest): void {
                $manifest['source_dependencies']['history-export-bundle.schema.json']['resolver_url'] =
                    str_replace(
                        '2.0.0-rc.42',
                        '2.0.0-rc.43',
                        $manifest['source_dependencies']['history-export-bundle.schema.json']['resolver_url'],
                    );
            },
            'artifact retained release drift' => static function (array &$manifest): void {
                $manifest['source_dependencies']['history-export-bundle.schema.json']['artifact_id'] =
                    str_replace(
                        '2.0.0-rc.42',
                        '2.0.0-rc.43',
                        $manifest['source_dependencies']['history-export-bundle.schema.json']['artifact_id'],
                    );
            },
        ] as $case => $mutate) {
            $manifest = PlatformConformanceSuite::manifest();
            $mutate($manifest);

            try {
                $validator->invoke(null, $manifest);
                $this->fail("Stable source reference qualification accepted {$case}.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStableSourceReferenceClosureRejectsEscapesAndUnresolvedFragments(): void
    {
        $pathResolver = new ReflectionMethod(PlatformConformanceSuite::class, 'localReferencePath');
        $fragmentValidator = new ReflectionMethod(
            PlatformConformanceSuite::class,
            'assertReferenceFragmentExists',
        );

        try {
            $pathResolver->invoke(
                null,
                'resources/conformance/suite-v38/platform-protocol-specs/control-plane-api.openapi.yaml',
                'durable-workflow.v2.control-plane-api@catalog-16',
                '../../../../composer.json',
            );
            $this->fail('Stable source reference qualification accepted a path escape.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        try {
            $fragmentValidator->invoke(
                null,
                [
                    '$defs' => [],
                ],
                '/$defs/missing',
                'durable-workflow.v2.worker-protocol-api@catalog-16',
                './worker-sessions-runtime.schema.json#/$defs/missing',
            );
            $this->fail('Stable source reference qualification accepted an unresolved fragment.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testStableFixtureSourceQualificationRejectsMutableAndUnboundSources(): void
    {
        $validator = new ReflectionMethod(PlatformConformanceSuite::class, 'assertStableFixtureSources');

        foreach ([
            'mutable resolver' => static function (array &$source): void {
                $source['resolver_url'] =
                    'https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json';
            },
            'non-HTTPS resolver' => static function (array &$source): void {
                $source['resolver_url'] = str_replace('https://', 'http://', $source['resolver_url']);
            },
            'short revision' => static function (array &$source): void {
                $source['resolver_url'] = str_replace(
                    PlatformConformanceSuite::FIXTURE_SOURCE_REVISION,
                    substr(PlatformConformanceSuite::FIXTURE_SOURCE_REVISION, 0, 12),
                    $source['resolver_url'],
                );
            },
            'incorrect digest' => static function (array &$source): void {
                $source['sha256'] = 'sha256:' . str_repeat('0', 64);
            },
        ] as $case => $mutate) {
            $manifest = PlatformConformanceSuite::manifest();
            $mutate($manifest['fixture_catalog']['signal_query_runtime_contract']['sources'][0]);

            try {
                $validator->invoke(null, $manifest);
                $this->fail("Stable fixture qualification accepted {$case}.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPhpSdkAndEmbeddedWorkflowReleaseGatesAreIndependent(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame(
            ['embedded_engine'],
            $manifest['release_gates']['gates']['durable-workflow/workflow']['required_targets'],
        );
        $this->assertSame(
            ['official_sdk', 'worker_protocol_implementation'],
            $manifest['release_gates']['gates']['durable-workflow/sdk']['required_targets'],
        );
        $this->assertSame(
            ['history_replay_bundles'],
            $manifest['targets']['embedded_engine']['required_fixture_categories'],
        );
    }

    public function testRustSignalQueryScenariosAreRequiredByStableTargetsAndPassFailRules(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];

        $this->assertSame(PlatformConformanceSuite::CATEGORY_STATUS_STABLE, $category['status']);

        foreach (self::RUST_SIGNAL_QUERY_SCENARIOS as $scenario) {
            $this->assertContains($scenario, $category['required_scenarios']);
        }

        foreach (['standalone_server', 'official_sdk', 'worker_protocol_implementation'] as $target) {
            $this->assertContains(
                'signal_query_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
            );
        }

        $coverageRule = $manifest['pass_fail_rules']['stable_runtime_scenario_coverage'];
        $this->assertContains('signal_query_runtime_contract', $coverageRule['applies_to_categories']);
        $this->assertStringContainsString('every required scenario to pass', $coverageRule['rule']);
        $this->assertStringContainsString('runner-blocked cell is nonconforming', $coverageRule['rule']);
    }

    public function testRustSignalQueryScenarioContractsPreserveArtifactRolesAndImmutability(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];
        $contracts = $category['required_scenario_contracts'];
        $artifact = [
            'package' => 'durable-workflow',
            'version' => '2.0.0-rc.5',
            'source' => 'crates.io',
            'cargo_requirement' => '=2.0.0-rc.5',
        ];

        $this->assertStringContainsString('Rust SDK', $manifest['targets']['official_sdk']['description']);
        $this->assertSame(self::RUST_SIGNAL_QUERY_SCENARIOS, array_keys($contracts));

        foreach ($contracts as $contract) {
            $this->assertSame($artifact, $contract['artifact']);
        }

        $this->assertSame('sdk-rust', $contracts['rust_worker_rust_php_python_clients']['worker_runtime']);
        $this->assertSame(
            ['sdk-rust', 'sdk-php', 'sdk-python'],
            $contracts['rust_worker_rust_php_python_clients']['caller_paths'],
        );
        $this->assertSame('sdk-python', $contracts['python_worker_rust_client']['worker_runtime']);
        $this->assertSame('sdk-php', $contracts['php_worker_rust_client']['worker_runtime']);
        $this->assertSame('client', $contracts['python_worker_rust_client']['rust_role']);
        $this->assertSame('client', $contracts['php_worker_rust_client']['rust_role']);

        $snapshot = $contracts['rust_query_error_and_immutability'];
        $this->assertSame('snapshot_derived_transport_state', $snapshot['query_state_model']);
        foreach ([
            'successful_query_emits_no_workflow_commands',
            'failed_query_emits_no_workflow_commands',
            'successful_query_appends_no_history',
            'failed_query_appends_no_history',
            'failed_query_does_not_change_later_answer',
        ] as $assertion) {
            $this->assertContains($assertion, $snapshot['required_assertions']);
        }

        $replay = $contracts['rust_replayed_instance_state_query_after_cold_restart'];
        $this->assertSame('replayed_workflow_instance_state', $replay['query_state_model']);
        $this->assertSame(
            [
                'start_running_workflow',
                'query_running_state',
                'cold_stop_rust_worker',
                'start_fresh_rust_worker_process',
                'restore_state_from_durable_history',
                'complete_restored_workflow',
                'query_completed_state',
            ],
            $replay['lifecycle'],
        );
        foreach ([
            'successful_replayed_query_emits_no_workflow_commands',
            'failed_replayed_query_emits_no_workflow_commands',
            'successful_replayed_query_appends_no_history',
            'failed_replayed_query_appends_no_history',
            'failed_replayed_query_does_not_change_state_returned_by_later_query',
        ] as $assertion) {
            $this->assertContains($assertion, $replay['required_assertions']);
        }
    }

    public function testRustSignalQueryArtifactHistoryIsImmutableAndCurrent(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $history = $manifest['artifact_version_history']['rust_signal_query'];
        $bindings = $history['bindings'];

        $this->assertSame('observed_bindings_with_provenance', $history['history_mode']);
        $this->assertSame(37, $history['strict_suite_versioning_from']);

        $provenance = array_map(
            static fn (array $binding): array => [
                $binding['suite_version'],
                $binding['artifact']['version'],
                $binding['source_revision'],
                $binding['authority_sha256'],
            ],
            $bindings,
        );
        $knownBindingCount = count(self::RUST_SIGNAL_QUERY_BINDING_PROVENANCE);

        $this->assertGreaterThanOrEqual($knownBindingCount, count($bindings));
        $this->assertSame(
            self::RUST_SIGNAL_QUERY_BINDING_PROVENANCE,
            array_slice($provenance, 0, $knownBindingCount),
            'Recorded artifact bindings are append-only.',
        );

        $previousStrictBinding = null;
        foreach ($bindings as $binding) {
            $artifact = $binding['artifact'];

            $this->assertLessThanOrEqual($manifest['version'], $binding['suite_version']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $binding['source_revision']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $binding['authority_sha256']);
            $this->assertSame('durable-workflow', $artifact['package']);
            $this->assertSame('crates.io', $artifact['source']);
            $this->assertSame('=' . $artifact['version'], $artifact['cargo_requirement']);

            if ($binding['suite_version'] < $history['strict_suite_versioning_from']) {
                continue;
            }

            if ($previousStrictBinding !== null && $artifact !== $previousStrictBinding['artifact']) {
                $this->assertGreaterThan(
                    $previousStrictBinding['suite_version'],
                    $binding['suite_version'],
                    'Every exact artifact change must advance the suite version.',
                );
            }

            $previousStrictBinding = $binding;
        }

        $latestBinding = $bindings[array_key_last($bindings)];
        $this->assertLessThanOrEqual($manifest['version'], $latestBinding['suite_version']);

        $currentArtifact = $latestBinding['artifact'];
        $contracts = $manifest['fixture_catalog']['signal_query_runtime_contract']['required_scenario_contracts'];

        foreach ($contracts as $contract) {
            $this->assertSame($currentArtifact, $contract['artifact']);
        }
    }

    public function testWorkflowSourceReleaseAndQualifiedSdkTupleRemainExplicit(): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        $composerJson = file_get_contents($composerPath);

        $this->assertIsString($composerJson);

        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        $workflowSourceRelease = $composer['extra']['durable-workflow']['product-train'];
        $surfaceManifest = SurfaceStabilityContract::manifest();
        $sdkCompatibility = $surfaceManifest['surface_families']['official_sdks']['package_compatibility'];
        $suiteManifest = PlatformConformanceSuite::manifest();
        $contracts = $suiteManifest['fixture_catalog']['signal_query_runtime_contract']['required_scenario_contracts'];

        $this->assertIsString($workflowSourceRelease);
        $this->assertMatchesRegularExpression('/^2\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D', $workflowSourceRelease);
        $this->assertSame($workflowSourceRelease, PlatformConformanceSuite::workflowSourceRelease());

        foreach ($sdkCompatibility as $sdk) {
            $this->assertSame('2.0.0-rc.5', $sdk['release_line']);
            $this->assertSame('2.0.0-rc.5', $sdk['product_train']);
            $this->assertSame('2.0.0-rc.5', $sdk['supported_server_versions']);
            $this->assertNotSame($workflowSourceRelease, $sdk['release_line']);
        }

        $this->assertSame('2.0.0rc5', $sdkCompatibility['python_sdk']['registry_version']);

        foreach ($contracts as $contract) {
            $artifact = $contract['artifact'];

            $this->assertSame('durable-workflow', $artifact['package']);
            $this->assertSame('crates.io', $artifact['source']);
            $this->assertSame('2.0.0-rc.5', $artifact['version']);
            $this->assertSame('=' . $artifact['version'], $artifact['cargo_requirement']);
            $this->assertNotSame($workflowSourceRelease, $artifact['version']);
        }
    }

    public function testHistoricalReleaseGateCompatibilityNamesRemainDeclared(): void
    {
        $gates = PlatformConformanceSuite::manifest()['release_gates']['gates'];

        $this->assertArrayHasKey('durable-workflow/workflow', $gates);
        $this->assertArrayHasKey('durable-workflow/sdk', $gates);
        $this->assertArrayHasKey('durable_workflow', $gates);
        $this->assertSame(
            $gates['durable-workflow/sdk']['required_targets'],
            $gates['durable_workflow']['required_targets'],
        );
    }
}
