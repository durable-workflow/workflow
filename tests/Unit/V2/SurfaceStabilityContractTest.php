<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the platform-wide compatibility and release-authority contract
 * mirrored by `Workflow\V2\Support\SurfaceStabilityContract`. The
 * authority is published at
 * https://durable-workflow.github.io/docs/2.0/compatibility and the
 * standalone `workflow-server` re-exports the same manifest from
 * `GET /api/cluster/info` under `surface_stability_contract`.
 *
 * Adding a surface family, changing a stability level, or changing the
 * patch/minor/major release rules is a contract change. Update the
 * `compatibility.md` page (durable-workflow.github.io), the static
 * `static/compatibility-contract.json` mirror, the per-package
 * `docs/api-stability.md` references, and bump
 * `SurfaceStabilityContract::VERSION` in the same change.
 */
final class SurfaceStabilityContractTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $this->assertSame('durable-workflow.v2.surface-stability.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(
            'https://durable-workflow.github.io/docs/2.0/compatibility',
            $manifest['authority_url'],
        );
    }

    public function testStabilityLevelsCoverFrozenStablePrereleaseExperimental(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $this->assertSame(
            ['frozen', 'stable', 'prerelease', 'experimental'],
            array_keys($manifest['stability_levels']),
        );

        foreach ($manifest['stability_levels'] as $level => $definition) {
            $this->assertArrayHasKey('meaning', $definition, "stability level $level needs meaning");
            $this->assertArrayHasKey(
                'breaking_change_release',
                $definition,
                "stability level $level needs breaking_change_release"
            );
        }
    }

    public function testReleaseRulesNamePatchMinorMajor(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $this->assertSame(['patch', 'minor', 'major'], array_keys($manifest['release_rules']));

        $this->assertContains(
            'removing or renaming any `stable` or `frozen` field, route, command, or class',
            $manifest['release_rules']['patch']['forbidden_changes'],
            'patch must explicitly forbid breaking changes to stable/frozen surfaces',
        );

        $this->assertContains(
            'removing or renaming any `stable` or `frozen` field, route, command, or class',
            $manifest['release_rules']['minor']['forbidden_changes'],
            'minor must explicitly forbid breaking changes to stable/frozen surfaces',
        );

        $this->assertContains(
            'removing, renaming, or narrowing a `stable` surface',
            $manifest['release_rules']['major']['allowed_changes'],
            'major is the release that is allowed to remove or narrow a stable surface',
        );
    }

    public function testFieldVisibilityRuleSeparatesGuaranteedFromDiagnosticOnly(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $rule = $manifest['field_visibility_rule'];
        $this->assertArrayHasKey('guaranteed_fields', $rule);
        $this->assertArrayHasKey('diagnostic_only_fields', $rule);
        $this->assertArrayHasKey('unknown_field_policy', $rule);

        $this->assertStringContainsString(
            'documented contract',
            $rule['guaranteed_fields']['definition'],
        );
        $this->assertStringContainsString(
            'must not gate behavior on them',
            $rule['diagnostic_only_fields']['definition'],
        );
    }

    public function testEverySurfaceFamilyHasStabilityAndBreakingChangeRule(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $expectedFamilies = [
            'server_api',
            'worker_protocol',
            'cli_json',
            'waterline_api',
            'mcp_discovery_results',
            'official_sdks',
            'history_event_wire_formats',
            'cluster_info_manifests',
        ];
        $this->assertSame($expectedFamilies, array_keys($manifest['surface_families']));

        $allowed = SurfaceStabilityContract::stabilityLevelValues();
        foreach ($manifest['surface_families'] as $name => $family) {
            $this->assertArrayHasKey('description', $family, "$name needs description");
            $this->assertArrayHasKey('stability_level', $family, "$name needs stability_level");
            $this->assertContains(
                $family['stability_level'],
                $allowed,
                "$name stability_level must be one of " . implode(', ', $allowed),
            );
            $this->assertArrayHasKey(
                'breaking_change_release',
                $family,
                "$name needs breaking_change_release"
            );
        }
    }

    public function testHistoryEventWireFormatsAreFrozen(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $this->assertSame(
            'frozen',
            $manifest['surface_families']['history_event_wire_formats']['stability_level'],
            'history event wire formats are frozen for the workflow lifetime',
        );

        $this->assertSame(
            'parallel_primitive_only',
            $manifest['surface_families']['history_event_wire_formats']['breaking_change_release'],
            'frozen wire formats may only break by introducing a parallel primitive with a new type name',
        );
    }

    public function testReleaseCheckNamesEnforcementGates(): void
    {
        $manifest = SurfaceStabilityContract::manifest();

        $check = $manifest['release_check'];
        $this->assertArrayHasKey('docs_authority_aligned', $check['gates']);
        $this->assertArrayHasKey('install_docs_aligned', $check['gates']);
        $this->assertArrayHasKey('package_metadata_aligned', $check['gates']);
        $this->assertArrayHasKey('version_history_aligned', $check['gates']);

        $this->assertStringContainsString(
            'check-compatibility-authority.js',
            $check['enforcement']['machine'],
            'docs CI script enforces alignment between the JSON contract and the doc page',
        );
    }
}
