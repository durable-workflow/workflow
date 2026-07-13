<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SdkNeutralityContract;
use Workflow\V2\Support\SurfaceStabilityContract;

/**
 * Pins the SDK neutrality contract mirrored by
 * `Workflow\V2\Support\SdkNeutralityContract`. The authority is
 * `docs/architecture/sdk-neutrality.md`.
 *
 * Adding a neutrality rule, tightening an existing rule, adding a
 * required audit step, adding a surface family to the audit scope, or
 * changing the official-SDK breadth policy is a contract change.
 * Update the architecture doc, the static JSON mirror at
 * `static/sdk-neutrality-contract.json` on the docs site, and bump
 * `SdkNeutralityContract::VERSION` in the same change.
 */
final class SdkNeutralityContractTest extends TestCase
{
    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $this->assertSame('durable-workflow.v2.sdk-neutrality.contract', $manifest['schema']);
        $this->assertSame(2, $manifest['version']);
        $this->assertSame(
            'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/sdk-neutrality.md',
            $manifest['authority_doc'],
        );
    }

    public function testManifestPointsAtUpstreamAuthorities(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $this->assertSame(
            SurfaceStabilityContract::SCHEMA,
            $manifest['surface_stability_authority'],
            'SDK neutrality is downstream of the surface stability contract',
        );
        $this->assertSame(
            PlatformProtocolSpecs::SCHEMA,
            $manifest['protocol_specs_authority'],
            'SDK neutrality is downstream of the protocol-spec catalog',
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['conformance_suite_authority'],
            'SDK neutrality is downstream of the platform conformance suite',
        );
    }

    public function testScopeNamesPriorityNonGoalAndFuturePosture(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $scope = $manifest['scope'];
        $this->assertArrayHasKey('goal', $scope);
        $this->assertArrayHasKey('non_goal', $scope);
        $this->assertArrayHasKey('present_priority', $scope);
        $this->assertArrayHasKey('future_posture', $scope);

        $this->assertStringContainsString(
            'TypeScript, Go, Java, or .NET',
            $scope['goal'],
            'goal explicitly names the languages this contract protects against',
        );
        $this->assertStringContainsString(
            'narrow',
            $scope['non_goal'],
            'non-goal makes clear that broad first-party SDK breadth is not a release goal',
        );
        $this->assertStringContainsString(
            'Python',
            $scope['present_priority'],
            'present priority is Python as the highest-value non-PHP path',
        );
        $this->assertStringContainsString(
            'demand-driven',
            $scope['future_posture'],
            'future posture is explicitly demand-driven',
        );
    }

    public function testNeutralityRulesCoverProtocolCodecErrorTypeReplayDiscoveryAndDocumentation(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $expected = [
            'protocol_neutrality',
            'codec_neutrality',
            'error_shape_neutrality',
            'type_identity_neutrality',
            'replay_fixture_neutrality',
            'discovery_neutrality',
            'documentation_neutrality',
        ];
        $this->assertSame($expected, array_keys($manifest['neutrality_rules']));
        $this->assertSame($expected, SdkNeutralityContract::ruleNames());

        foreach ($manifest['neutrality_rules'] as $name => $rule) {
            $this->assertArrayHasKey('requirement', $rule, "rule $name needs requirement");
            $this->assertArrayHasKey('rationale', $rule, "rule $name needs rationale");
            $this->assertArrayHasKey('authority', $rule, "rule $name needs authority pointer");
            $this->assertArrayHasKey('how_to_apply', $rule, "rule $name needs how_to_apply");
        }
    }

    public function testCodecNeutralityRuleNamesUniversalCodecAuthority(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $codecRule = $manifest['neutrality_rules']['codec_neutrality'];
        $this->assertStringContainsString(
            'CodecRegistry::universal()',
            $codecRule['authority'],
            'codec neutrality must point at the universal codec authority',
        );
        $this->assertStringContainsString(
            'universal codec',
            $codecRule['requirement'],
            'codec neutrality must require advertising at least one universal codec',
        );
    }

    public function testReplayFixtureRuleRequiresPublishedJsonSchemas(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $rule = $manifest['neutrality_rules']['replay_fixture_neutrality'];
        $this->assertStringContainsString(
            'history_event_payloads',
            $rule['requirement'],
            'replay fixtures must validate against the history-event payload schema',
        );
        $this->assertStringContainsString(
            'replay_bundle',
            $rule['requirement'],
            'replay fixtures must validate against the replay-bundle schema',
        );
    }

    public function testAuditChecklistEnumeratesEveryNeutralityRule(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $declared = SdkNeutralityContract::ruleNames();
        $checklist = $manifest['audit_checklist']['steps'];

        $coveredRules = [];
        foreach ($checklist as $stepName => $step) {
            $this->assertArrayHasKey('rule', $step, "audit step $stepName needs rule");
            $this->assertArrayHasKey('check', $step, "audit step $stepName needs check");
            $this->assertContains(
                $step['rule'],
                $declared,
                "audit step $stepName references unknown rule {$step['rule']}",
            );
            $coveredRules[$step['rule']] = true;
        }

        foreach ($declared as $rule) {
            $this->assertArrayHasKey(
                $rule,
                $coveredRules,
                "neutrality rule $rule has no matching audit step",
            );
        }

        $this->assertArrayHasKey(
            'future_sdk_thought_experiment',
            $checklist,
            'audit checklist must include the future-SDK thought experiment',
        );
    }

    public function testAuditScopeReferencesOnlyDeclaredSurfaceFamilies(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        foreach ($manifest['audit_scope_surface_families'] as $family) {
            $this->assertContains(
                $family,
                $surfaceFamilies,
                "audit scope references surface family $family which is not declared by SurfaceStabilityContract",
            );
        }

        $this->assertContains('worker_protocol', $manifest['audit_scope_surface_families']);
        $this->assertContains('server_api', $manifest['audit_scope_surface_families']);
        $this->assertContains('cli_json', $manifest['audit_scope_surface_families']);
        $this->assertContains('waterline_api', $manifest['audit_scope_surface_families']);
        $this->assertContains('mcp_discovery_results', $manifest['audit_scope_surface_families']);
    }

    public function testSdkBreadthPolicyMarksPhpPythonAndRustAsPriorityAndOthersAsDemandDriven(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $policy = $manifest['sdk_breadth_policy'];
        $this->assertArrayHasKey('php_workflow_package', $policy['first_party']);
        $this->assertArrayHasKey('python_sdk', $policy['first_party']);
        $this->assertArrayHasKey('rust_sdk', $policy['first_party']);

        $this->assertSame(
            SdkNeutralityContract::POSTURE_PRIORITY,
            $policy['first_party']['php_workflow_package']['posture'],
        );
        $this->assertSame(
            SdkNeutralityContract::POSTURE_PRIORITY,
            $policy['first_party']['python_sdk']['posture'],
        );
        $this->assertSame(
            SdkNeutralityContract::POSTURE_PRIORITY,
            $policy['first_party']['rust_sdk']['posture'],
        );

        $expectedDemandDriven = ['typescript_sdk', 'go_sdk', 'java_sdk', 'dotnet_sdk'];
        $this->assertSame($expectedDemandDriven, array_keys($policy['demand_driven']));

        foreach ($policy['demand_driven'] as $name => $entry) {
            $this->assertSame(
                SdkNeutralityContract::POSTURE_DEMAND_DRIVEN,
                $entry['posture'],
                "$name must be marked demand-driven",
            );
        }

        $criteria = $policy['expansion_criteria'];
        $this->assertArrayHasKey('adoption_signal', $criteria);
        $this->assertArrayHasKey('maintenance_commitment', $criteria);
        $this->assertArrayHasKey('no_protocol_redesign', $criteria);
    }

    public function testPostureVocabularyIsClosed(): void
    {
        $this->assertSame(
            ['priority', 'demand_driven', 'out_of_scope'],
            SdkNeutralityContract::postureValues(),
        );
    }

    public function testReleaseGatesAreDeclared(): void
    {
        $manifest = SdkNeutralityContract::manifest();

        $gates = $manifest['release_gates']['gates'];
        $this->assertArrayHasKey('audit_recorded', $gates);
        $this->assertArrayHasKey('no_php_or_python_only_required_fields', $gates);
        $this->assertArrayHasKey('universal_codec_advertised', $gates);
        $this->assertArrayHasKey('fixture_schema_validated', $gates);
        $this->assertArrayHasKey('discovery_entry_present', $gates);

        $enforcement = $manifest['release_gates']['enforcement'];
        $this->assertStringContainsString(
            'SdkNeutralityContractTest.php',
            $enforcement['machine'],
            'machine enforcement names the pinning test',
        );
        $this->assertStringContainsString(
            'thought experiment',
            $enforcement['human'],
            'human enforcement carries the future-SDK thought experiment',
        );
    }

    public function testClusterInfoEnvelopeAdvertisesTheNeutralityContract(): void
    {
        $envelope = PlatformProtocolSpecs::manifest()['specs']['cluster_info_envelope'];

        $found = false;
        foreach ($envelope['object_families'] as $family) {
            if ($family['name'] === 'sdk_neutrality_contract') {
                $found = true;
                $this->assertSame(
                    'durable-workflow/workflow',
                    $family['owner_repo'],
                    'sdk_neutrality_contract is owned by the workflow repo',
                );
                $this->assertSame(
                    'Workflow\\V2\\Support\\SdkNeutralityContract::SCHEMA',
                    $family['schema_authority'],
                );
                $this->assertSame(
                    'Workflow\\V2\\Support\\SdkNeutralityContract::VERSION',
                    $family['version_authority'],
                );
            }
        }
        $this->assertTrue(
            $found,
            'cluster_info_envelope must advertise the sdk_neutrality_contract object family so the contract is discoverable',
        );
    }
}
