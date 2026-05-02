<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the platform conformance suite manifest mirrored by
 * `Workflow\V2\Support\PlatformConformanceSuite`. The authority is
 * `docs/architecture/platform-conformance-suite.md`. The standalone
 * `workflow-server` re-exports the same manifest from
 * `GET /api/cluster/info` under `platform_conformance_suite`.
 *
 * Adding a target, adding a fixture category, promoting a provisional
 * category to required, or changing a pass / fail rule is a contract
 * change. Update the spec doc, the static mirror, the per-repo
 * conformance claim docs, and bump
 * `PlatformConformanceSuite::VERSION` in the same change.
 */
final class PlatformConformanceSuiteTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame('durable-workflow.v2.platform-conformance.suite', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(
            'docs/architecture/platform-conformance-suite.md',
            $manifest['authority_doc'],
        );
        $this->assertSame(
            SurfaceStabilityContract::SCHEMA,
            $manifest['surface_stability_authority'],
            'the conformance suite is downstream of the surface stability contract',
        );
    }

    public function testResultDocumentSchemaIsAdvertised(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame('durable-workflow.v2.platform-conformance.result', $manifest['result_schema']);
        $this->assertSame(1, $manifest['result_version']);
    }

    public function testConformanceLevelsCoverFullPartialProvisionalNonconforming(): void
    {
        $this->assertSame(
            ['full', 'partial', 'provisional', 'nonconforming'],
            PlatformConformanceSuite::CONFORMANCE_LEVELS,
        );
    }

    public function testTargetMatrixCoversIssueDeliverables(): void
    {
        $expected = [
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
            'repair_actionability_surface',
            'mcp_discovery_surface',
        ];
        $this->assertSame($expected, PlatformConformanceSuite::targetNames());
    }

    public function testEveryTargetReferencesOnlyKnownSurfaceFamiliesAndCategories(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $surfaceFamilies = array_keys(
            SurfaceStabilityContract::manifest()['surface_families'],
        );
        $categoryNames = array_keys($manifest['fixture_catalog']);

        foreach ($manifest['targets'] as $name => $target) {
            $this->assertArrayHasKey('description', $target, "$name needs description");
            $this->assertArrayHasKey('required_surface_families', $target, "$name needs required_surface_families");
            $this->assertArrayHasKey('required_fixture_categories', $target, "$name needs required_fixture_categories");

            foreach ($target['required_surface_families'] as $family) {
                $this->assertContains(
                    $family,
                    $surfaceFamilies,
                    "$name requires unknown surface family `$family`; declare it in SurfaceStabilityContract first",
                );
            }
            foreach ($target['required_fixture_categories'] as $category) {
                $this->assertContains(
                    $category,
                    $categoryNames,
                    "$name requires unknown fixture category `$category`",
                );
            }
        }
    }

    public function testFixtureCatalogCoversIssueDeliverables(): void
    {
        $expected = [
            'control_plane_request_response',
            'worker_task_lifecycle',
            'history_replay_bundles',
            'failure_repair_actionability',
            'cli_json_envelopes',
            'waterline_observer_envelopes',
            'mcp_discovery_envelopes',
        ];
        $this->assertSame($expected, PlatformConformanceSuite::fixtureCategoryNames());
    }

    public function testEveryFixtureCategoryDeclaresStatusDescriptionAndAtLeastOneSource(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $allowedStatus = [
            PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
            PlatformConformanceSuite::CATEGORY_STATUS_PROVISIONAL,
        ];

        foreach ($manifest['fixture_catalog'] as $name => $category) {
            $this->assertArrayHasKey('status', $category, "$name needs status");
            $this->assertContains($category['status'], $allowedStatus, "$name has unknown status");
            $this->assertArrayHasKey('description', $category, "$name needs description");
            $this->assertArrayHasKey('sources', $category, "$name needs sources[]");
            $this->assertNotEmpty($category['sources'], "$name needs at least one source-of-truth pointer");

            foreach ($category['sources'] as $source) {
                $this->assertArrayHasKey('repository', $source, "$name source needs repository");
                $this->assertArrayHasKey('path', $source, "$name source needs path");
            }
        }
    }

    public function testHistoryReplayBundlesAreFlaggedForFrozenExactMatch(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $rule = $manifest['pass_fail_rules']['frozen_shape_exact_match'] ?? null;
        $this->assertNotNull($rule, 'frozen_shape_exact_match rule must be declared');
        $this->assertContains(
            'history_replay_bundles',
            $rule['applies_to_categories'],
            'history_replay_bundles must be subject to exact-match because the underlying surface is frozen',
        );
    }

    public function testPassFailRulesNameTheCoreContract(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $rules = $manifest['pass_fail_rules'];

        $this->assertArrayHasKey('guaranteed_field_equality', $rules);
        $this->assertArrayHasKey('unknown_additive_fields_tolerated', $rules);
        $this->assertArrayHasKey('frozen_shape_exact_match', $rules);
        $this->assertArrayHasKey('required_fixtures_must_pass', $rules);
        $this->assertArrayHasKey('provisional_categories_warn_only', $rules);
        $this->assertArrayHasKey('diagnostic_only_mismatches_pass', $rules);

        $this->assertSame(
            SurfaceStabilityContract::SCHEMA . '#field_visibility_rule',
            $rules['guaranteed_field_equality']['follows'],
            'the equality rule must defer to the surface stability contract field visibility rule',
        );
    }

    public function testHarnessContractRequiresStructuredResultDocument(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $contract = $manifest['harness_contract'];

        $this->assertArrayHasKey('requirements', $contract);
        $this->assertArrayHasKey('emit_result_document', $contract['requirements']);
        $this->assertArrayHasKey('exit_code', $contract['requirements']);

        $this->assertContains(
            'conformance_level',
            $contract['result_document_required_fields'],
            'the result document must always declare a conformance_level',
        );
        $this->assertContains(
            'suite_version',
            $contract['result_document_required_fields'],
            'the result document must always pin the suite version it ran against',
        );
    }

    public function testReleaseGatesCoverEveryFirstPartySurface(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $gates = $manifest['release_gates']['gates'];

        $this->assertArrayHasKey('durable-workflow/server', $gates);
        $this->assertArrayHasKey('durable-workflow/workflow', $gates);
        $this->assertArrayHasKey('durable_workflow', $gates);
        $this->assertArrayHasKey('dw', $gates);
        $this->assertArrayHasKey('waterline', $gates);

        $serverGate = $gates['durable-workflow/server'];
        $this->assertContains('standalone_server', $serverGate['required_targets']);
        $this->assertContains('worker_protocol_implementation', $serverGate['required_targets']);
        $this->assertContains('repair_actionability_surface', $serverGate['required_targets']);

        $this->assertTrue(
            $manifest['release_gates']['enforcement']['block_on_nonconforming'],
            'a nonconforming harness result must block the release',
        );
    }
}
