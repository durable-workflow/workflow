<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the platform-wide normative protocol-spec catalog mirrored by
 * `Workflow\V2\Support\PlatformProtocolSpecs`. The authority is
 * published at
 * https://durable-workflow.github.io/docs/2.0/platform-protocol-specs
 * and the standalone `workflow-server` re-exports the same manifest from
 * `GET /api/cluster/info` under `platform_protocol_specs`.
 *
 * Adding, removing, or changing a spec entry is a contract change.
 * Update the docs page, the `static/platform-protocol-specs.json`
 * mirror in `durable-workflow.github.io`, and bump
 * `PlatformProtocolSpecs::VERSION` in the same change.
 */
final class PlatformProtocolSpecsTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame('durable-workflow.v2.platform-protocol-specs.catalog', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(
            'https://durable-workflow.github.io/docs/2.0/platform-protocol-specs',
            $manifest['authority_url'],
        );
    }

    public function testManifestEnumeratesFormatsOpenApiJsonSchemaAsyncApi(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(
            ['openapi', 'json_schema', 'asyncapi'],
            array_keys($manifest['formats']),
        );

        foreach ($manifest['formats'] as $format => $definition) {
            $this->assertArrayHasKey('meaning', $definition, "format $format needs meaning");
            $this->assertArrayHasKey(
                'file_extensions',
                $definition,
                "format $format needs file_extensions",
            );
        }
    }

    public function testStatusLevelsCoverPublishedInProgressPlanned(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(
            ['published', 'in_progress', 'planned'],
            array_keys($manifest['status_levels']),
        );

        foreach ($manifest['status_levels'] as $level => $meaning) {
            $this->assertIsString($meaning, "status level $level meaning must be a string");
            $this->assertNotSame('', $meaning, "status level $level must have a non-empty meaning");
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
            $this->assertArrayHasKey('meaning', $definition, "evolution rule $rule needs meaning");
            $this->assertArrayHasKey(
                'applies_to_formats',
                $definition,
                "evolution rule $rule needs applies_to_formats",
            );
            foreach ($definition['applies_to_formats'] as $format) {
                $this->assertContains(
                    $format,
                    PlatformProtocolSpecs::formatValues(),
                    "evolution rule $rule applies_to_formats must be drawn from the format vocabulary",
                );
            }
        }

        $this->assertSame(
            ['json_schema'],
            $manifest['evolution_rules']['parallel_primitive_only']['applies_to_formats'],
            'parallel_primitive_only is the rule that pins frozen wire formats',
        );
    }

    public function testCatalogCoversTheDeliverableSurfaceSet(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $expectedSpecs = [
            'control_plane_api',
            'worker_protocol_api',
            'worker_protocol_stream',
            'history_event_payloads',
            'history_export_bundle',
            'replay_bundle',
            'waterline_read_api',
            'waterline_diagnostic_objects',
            'repair_actionability_objects',
            'mcp_discovery',
            'mcp_tool_results',
            'cluster_info_envelope',
        ];

        $this->assertSame($expectedSpecs, array_keys($manifest['specs']));
    }

    public function testEverySpecEntryIsWellFormed(): void
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
            'owner_symbol',
            'evolution_rule',
            'breaking_change_release',
            'conformance_test',
            'status',
            'spec_path',
        ];

        foreach ($manifest['specs'] as $name => $spec) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $spec,
                    "spec $name is missing required field $field",
                );
            }

            $this->assertContains(
                $spec['format'],
                $allowedFormats,
                "spec $name format must be one of " . implode(', ', $allowedFormats),
            );
            $this->assertContains(
                $spec['status'],
                $allowedStatuses,
                "spec $name status must be one of " . implode(', ', $allowedStatuses),
            );
            $this->assertContains(
                $spec['owner_repo'],
                $allowedOwners,
                "spec $name owner_repo must be one of " . implode(', ', $allowedOwners),
            );
            $this->assertContains(
                $spec['surface_family'],
                $surfaceFamilies,
                "spec $name surface_family must be one of the SurfaceStabilityContract families",
            );

            $this->assertStringStartsWith(
                'durable-workflow.v2.',
                $spec['spec_id'],
                "spec $name spec_id must live in the durable-workflow.v2.* namespace",
            );
            $this->assertStringStartsWith(
                'static/platform-protocol-specs/',
                $spec['spec_path'],
                "spec $name spec_path must live under static/platform-protocol-specs/ in the docs site",
            );
        }
    }

    public function testHistoryEventPayloadsAreFrozenViaParallelPrimitiveRule(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $entry = $manifest['specs']['history_event_payloads'];
        $this->assertSame(
            'parallel_primitive_only',
            $entry['evolution_rule'],
            'history-event payload schemas are frozen wire formats; the only allowed break is a new event type alongside the old one',
        );
        $this->assertSame(
            'parallel_primitive_only',
            $entry['breaking_change_release'],
        );
    }

    public function testReleaseCheckNamesEnforcementGates(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $check = $manifest['release_check'];
        $this->assertArrayHasKey('catalog_aligned_with_surface_families', $check['gates']);
        $this->assertArrayHasKey('owner_repo_known', $check['gates']);
        $this->assertArrayHasKey('format_known', $check['gates']);
        $this->assertArrayHasKey('docs_authority_aligned', $check['gates']);
        $this->assertArrayHasKey('json_mirror_aligned', $check['gates']);
        $this->assertArrayHasKey('spec_path_published_when_status_published', $check['gates']);

        $this->assertStringContainsString(
            'check-platform-protocol-specs.js',
            $check['enforcement']['machine'],
            'docs CI script enforces alignment between the JSON catalog and the doc page',
        );
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
            $this->assertContains(
                $family,
                $surfaceFamilies,
                "platform-protocol-specs catalog references surface_family $family which is not declared by SurfaceStabilityContract",
            );
        }
    }
}
