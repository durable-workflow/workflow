<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the packaged consumer-facing protocol catalog. The same JSON document
 * is published at https://durable-workflow.github.io/platform-protocol-specs.json
 * and re-exported by the standalone server under `platform_protocol_specs`.
 */
final class PlatformProtocolSpecsTest extends TestCase
{
    public function testPackagedCatalogIsTheDigestedRuntimeAuthority(): void
    {
        $path = dirname(__DIR__, 3) . '/' . PlatformProtocolSpecs::PACKAGE_CONTRACT_PATH;
        $json = file_get_contents($path);

        $this->assertIsString($json);
        $this->assertSame(PlatformProtocolSpecs::MIRROR_SHA256, hash('sha256', $json));
        $this->assertSame(json_decode($json, true, 512, JSON_THROW_ON_ERROR), PlatformProtocolSpecs::manifest());
    }

    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(PlatformProtocolSpecs::SCHEMA, $manifest['schema']);
        $this->assertSame(15, $manifest['version']);
        $this->assertSame(PlatformProtocolSpecs::CATALOG_URL, $manifest['catalog_url']);
        $this->assertSame(PlatformProtocolSpecs::AUTHORITY_URL, $manifest['authority_url']);
    }

    public function testManifestEnumeratesFormatsOpenApiJsonSchemaAsyncApi(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(['openapi', 'json_schema', 'asyncapi'], array_keys($manifest['formats']));

        foreach ($manifest['formats'] as $format => $definition) {
            $this->assertArrayHasKey('meaning', $definition, "format {$format} needs meaning");
            $this->assertArrayHasKey('file_extensions', $definition, "format {$format} needs file_extensions");
        }
    }

    public function testStatusLevelsCoverPublishedInProgressPlanned(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(['published', 'in_progress', 'planned'], array_keys($manifest['status_levels']));

        foreach ($manifest['status_levels'] as $level => $meaning) {
            $this->assertIsString($meaning, "status level {$level} meaning must be a string");
            $this->assertNotSame('', $meaning, "status level {$level} must have a non-empty meaning");
        }
    }

    public function testEvolutionRulesNameAdditiveParallelExperimental(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(
            ['additive_minor_breaking_major', 'parallel_primitive_only', 'experimental_any_release'],
            array_keys($manifest['evolution_rules']),
        );

        foreach ($manifest['evolution_rules'] as $rule => $definition) {
            $this->assertArrayHasKey('meaning', $definition, "evolution rule {$rule} needs meaning");
            $this->assertArrayHasKey(
                'applies_to_formats',
                $definition,
                "evolution rule {$rule} needs applies_to_formats",
            );
            foreach ($definition['applies_to_formats'] as $format) {
                $this->assertContains(
                    $format,
                    PlatformProtocolSpecs::formatValues(),
                    "evolution rule {$rule} applies_to_formats must use the format vocabulary",
                );
            }
        }

        $this->assertSame(
            ['json_schema'],
            $manifest['evolution_rules']['parallel_primitive_only']['applies_to_formats'],
        );
    }

    public function testCatalogCoversTheDeliverableSurfaceSet(): void
    {
        $this->assertSame(
            [
                'control_plane_api',
                'worker_protocol_api',
                'worker_protocol_stream',
                'worker_sessions_runtime',
                'local_activity_runtime',
                'history_event_payloads',
                'history_export_bundle',
                'replay_bundle',
                'waterline_read_api',
                'waterline_diagnostic_objects',
                'repair_actionability_objects',
                'cli_json_envelopes',
                'mcp_discovery',
                'mcp_tool_results',
                'cluster_info_envelope',
                'invocable_carrier_execution',
            ],
            PlatformProtocolSpecs::specNames(),
        );
    }

    public function testEverySpecEntryHasAConsumablePublicReference(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();
        $allowedFormats = PlatformProtocolSpecs::formatValues();
        $allowedStatuses = PlatformProtocolSpecs::statusValues();
        $allowedOwners = PlatformProtocolSpecs::ownerRepoValues();
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $requiredFields = [
            'description',
            'format',
            'spec_id',
            'surface_family',
            'authority_manifest',
            'owner_repo',
            'object_families',
            'evolution_rule',
            'breaking_change_release',
            'status',
        ];

        foreach ($manifest['specs'] as $name => $spec) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $spec, "spec {$name} is missing {$field}");
            }

            $this->assertContains($spec['format'], $allowedFormats);
            $this->assertContains($spec['status'], $allowedStatuses);
            $this->assertContains($spec['owner_repo'], $allowedOwners);
            $this->assertContains($spec['surface_family'], $surfaceFamilies);
            $this->assertStringStartsWith('durable-workflow.v2.', $spec['spec_id']);
            if ($spec['status'] === 'planned') {
                $this->assertArrayNotHasKey('spec_url', $spec);
            } else {
                $this->assertArrayHasKey('spec_url', $spec);
                $this->assertStringStartsWith(
                    'https://durable-workflow.github.io/platform-protocol-specs/',
                    $spec['spec_url'],
                    "spec {$name} must expose a public HTTPS reference",
                );
                $this->assertSame('https', parse_url($spec['spec_url'], PHP_URL_SCHEME));
                $this->assertSame('durable-workflow.github.io', parse_url($spec['spec_url'], PHP_URL_HOST));
            }

            $this->assertNotEmpty($spec['object_families']);
            foreach ($spec['object_families'] as $family) {
                $this->assertSame(['name', 'owner_repo'], array_keys($family));
                $this->assertContains($family['owner_repo'], $allowedOwners);
            }
        }
    }

    public function testCatalogDoesNotExposeRepositoryLocalAuthority(): void
    {
        $json = json_encode(PlatformProtocolSpecs::manifest(), JSON_THROW_ON_ERROR);

        foreach ([
            '"spec_path"',
            '"owner_symbol"',
            '"conformance_test"',
            '"schema_authority"',
            '"version_authority"',
            'tests/',
            'scripts/',
            'static/',
            '::',
            '\\\\',
        ] as $repositoryLocalReference) {
            $this->assertStringNotContainsString(
                $repositoryLocalReference,
                $json,
                "public catalog must not expose {$repositoryLocalReference}",
            );
        }
    }

    public function testWorkerProtocolApiCatalogCoversQueryTasks(): void
    {
        $entry = PlatformProtocolSpecs::manifest()['specs']['worker_protocol_api'];

        $this->assertStringContainsString('query tasks', $entry['description']);
        $this->assertStringContainsString('query_tasks', $entry['description']);
        $families = array_column($entry['object_families'], 'name');
        $this->assertContains('worker_query_task_poll_request', $families);
        $this->assertContains('worker_query_task_result', $families);
    }

    public function testFrozenBundlesUseTheParallelPrimitiveRule(): void
    {
        foreach (['history_event_payloads', 'history_export_bundle'] as $name) {
            $entry = PlatformProtocolSpecs::manifest()['specs'][$name];
            $this->assertSame('parallel_primitive_only', $entry['evolution_rule']);
            $this->assertSame('parallel_primitive_only', $entry['breaking_change_release']);
        }
    }

    public function testReleaseCheckDescribesTheMachineChecksThatRun(): void
    {
        $check = PlatformProtocolSpecs::manifest()['release_check'];

        foreach ([
            'catalog_aligned_with_surface_families',
            'owner_repo_known',
            'format_known',
            'public_spec_references_resolve',
            'repository_local_authority_fields_rejected',
            'workflow_package_mirror_aligned',
            'server_owned_spec_mirrors_aligned',
            'diagnostic_provenance_complete',
            'object_family_metadata_declared',
            'breaking_change_release_consistent_with_evolution_rule',
            'deliverable_specs_published',
        ] as $gate) {
            $this->assertArrayHasKey($gate, $check['gates']);
        }

        $this->assertArrayNotHasKey('docs_authority_aligned', $check['gates']);
        $this->assertStringNotContainsString('docs/platform-protocol-specs.md', $check['enforcement']['machine']);
        $this->assertStringNotContainsString('walks', $check['enforcement']['machine']);
    }

    public function testOwnerRepoVocabularyMatchesTheFleet(): void
    {
        $this->assertSame(
            [
                'durable-workflow/workflow',
                'durable-workflow/server',
                'durable-workflow/waterline',
                'durable-workflow/durable-workflow.github.io',
                'durable-workflow/cli',
                'durable-workflow/sdk-python',
            ],
            PlatformProtocolSpecs::ownerRepoValues(),
        );
    }

    public function testCatalogReferencesOnlyDeclaredSurfaceFamilies(): void
    {
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $referenced = [];
        foreach (PlatformProtocolSpecs::manifest()['specs'] as $spec) {
            $referenced[$spec['surface_family']] = true;
        }

        foreach (array_keys($referenced) as $family) {
            $this->assertContains($family, $surfaceFamilies);
        }
    }
}
