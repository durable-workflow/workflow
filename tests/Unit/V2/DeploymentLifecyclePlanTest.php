<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\DeploymentBlockageReason;
use Workflow\V2\Enums\DeploymentLifecycleState;
use Workflow\V2\Support\DeploymentLifecyclePlan;
use Workflow\V2\Support\WorkerDeployment;

/**
 * Pins the planner that connects worker-deployment lifecycle
 * transitions (promote, drain, resume, rollback) to the fleet
 * snapshot, the recorded workflow definition fingerprint, and the
 * replay-safety guardrail. Pinned by the contract documented in
 * docs/architecture/worker-deployment.md.
 */
final class DeploymentLifecyclePlanTest extends TestCase
{
    public function testPromotePassesWhenFleetSupportsRequiredCompatibility(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
            recordedFingerprint: 'fp-1',
        );

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 2,
            'active_workers_supporting_required' => 2,
            'advertised_compatibility' => ['v3'],
            'advertised_fingerprints' => ['fp-1'],
            'replay_safety_severity' => null,
        ]);

        $this->assertSame([], $blockages);
    }

    public function testPromoteReportsNoCompatibleWorkersWhenFleetIsEmpty(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
        );

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 0,
            'active_workers_supporting_required' => 0,
            'advertised_compatibility' => [],
            'advertised_fingerprints' => [],
            'replay_safety_severity' => null,
        ]);

        $this->assertCount(1, $blockages);
        $this->assertSame(DeploymentBlockageReason::NoCompatibleWorkers, $blockages[0]->reason);
        $this->assertSame('default', $blockages[0]->scope['namespace']);
        $this->assertSame('queue', $blockages[0]->scope['task_queue']);
        $this->assertSame('build-3', $blockages[0]->scope['build_id']);
        $this->assertSame('v3', $blockages[0]->scope['required_compatibility']);
        $this->assertNotNull($blockages[0]->expectedResolution);
    }

    public function testPromoteDistinguishesMissingHeartbeatFromEmptyFleet(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
        );

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 4,
            'active_workers_supporting_required' => 0,
            'advertised_compatibility' => ['v1', 'v2'],
            'advertised_fingerprints' => [],
            'replay_safety_severity' => null,
        ]);

        $this->assertCount(1, $blockages);
        $this->assertSame(DeploymentBlockageReason::MissingWorkerHeartbeat, $blockages[0]->reason);
        $this->assertStringContainsString('v3', $blockages[0]->message);
        $this->assertStringContainsString('v1, v2', $blockages[0]->message);
    }

    public function testPromoteReportsFingerprintMismatchWhenFleetAdvertisesAnotherFingerprint(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
            recordedFingerprint: 'fp-recorded',
        );

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 1,
            'active_workers_supporting_required' => 1,
            'advertised_compatibility' => ['v3'],
            'advertised_fingerprints' => ['fp-other'],
            'replay_safety_severity' => null,
        ]);

        $this->assertCount(1, $blockages);
        $this->assertSame(DeploymentBlockageReason::FingerprintMismatch, $blockages[0]->reason);
        $this->assertSame('fp-recorded', $blockages[0]->scope['recorded_fingerprint']);
    }

    public function testPromoteSurfacesReplaySafetyFailureWithMessages(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
            recordedFingerprint: 'fp-1',
        );

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 1,
            'active_workers_supporting_required' => 1,
            'advertised_compatibility' => ['v3'],
            'advertised_fingerprints' => ['fp-1'],
            'replay_safety_severity' => 'error',
            'replay_safety_messages' => [
                'Foo\\Bar uses non-deterministic time().',
                'Foo\\Baz emits unstructured side effects.',
            ],
        ]);

        $this->assertCount(1, $blockages);
        $this->assertSame(DeploymentBlockageReason::ReplaySafetyFailed, $blockages[0]->reason);
        $this->assertStringContainsString('Foo\\Bar', $blockages[0]->message);
        $this->assertStringContainsString('Foo\\Baz', $blockages[0]->message);
    }

    public function testPromoteAccumulatesEveryApplicableBlockage(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
            recordedFingerprint: 'fp-recorded',
        );

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 2,
            'active_workers_supporting_required' => 0,
            'advertised_compatibility' => ['v1'],
            'advertised_fingerprints' => ['fp-other'],
            'replay_safety_severity' => 'error',
            'replay_safety_messages' => ['determinism guard failed'],
        ]);

        $reasons = array_map(static fn ($b) => $b->reason, $blockages);

        $this->assertContains(DeploymentBlockageReason::MissingWorkerHeartbeat, $reasons);
        $this->assertContains(DeploymentBlockageReason::FingerprintMismatch, $reasons);
        $this->assertContains(DeploymentBlockageReason::ReplaySafetyFailed, $reasons);
    }

    public function testPromoteRefusesAgainstTerminalDeployments(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
        )->withState(DeploymentLifecycleState::RolledBack);

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 1,
            'active_workers_supporting_required' => 1,
            'advertised_compatibility' => ['v3'],
            'advertised_fingerprints' => [],
            'replay_safety_severity' => null,
        ]);

        $this->assertCount(1, $blockages);
        $this->assertSame(DeploymentBlockageReason::IncompatiblePolicy, $blockages[0]->reason);
    }

    public function testPromoteRefusesWhenDeploymentIsDraining(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-3',
            requiredCompatibility: 'v3',
        )->withState(DeploymentLifecycleState::Draining);

        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, [
            'active_worker_count' => 2,
            'active_workers_supporting_required' => 2,
            'advertised_compatibility' => ['v3'],
            'advertised_fingerprints' => [],
            'replay_safety_severity' => null,
        ]);

        $reasons = array_map(static fn ($b) => $b->reason, $blockages);
        $this->assertContains(DeploymentBlockageReason::FleetIsDraining, $reasons);
    }

    public function testDrainAllowsActiveAndPromotedAndOnlyRefusesRolledBack(): void
    {
        $base = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'b',
        );

        $this->assertSame([], DeploymentLifecyclePlan::evaluateDrain($base));
        $this->assertSame([], DeploymentLifecyclePlan::evaluateDrain(
            $base->withState(DeploymentLifecycleState::Promoted),
        ));

        $blockages = DeploymentLifecyclePlan::evaluateDrain(
            $base->withState(DeploymentLifecycleState::RolledBack),
        );
        $this->assertCount(1, $blockages);
        $this->assertSame(DeploymentBlockageReason::IncompatiblePolicy, $blockages[0]->reason);
    }

    public function testResumeRefusesTerminalStatesAndIsIdempotentOtherwise(): void
    {
        $base = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'b',
        );

        $this->assertSame([], DeploymentLifecyclePlan::evaluateResume($base));
        $this->assertSame([], DeploymentLifecyclePlan::evaluateResume(
            $base->withState(DeploymentLifecycleState::Draining),
        ));
        $this->assertCount(1, DeploymentLifecyclePlan::evaluateResume(
            $base->withState(DeploymentLifecycleState::Drained),
        ));
        $this->assertCount(1, DeploymentLifecyclePlan::evaluateResume(
            $base->withState(DeploymentLifecycleState::RolledBack),
        ));
    }

    public function testRollbackRefusesPendingAndAlreadyRolledBackDeployments(): void
    {
        $base = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'b',
        );

        $this->assertSame([], DeploymentLifecyclePlan::evaluateRollback(
            $base->withState(DeploymentLifecycleState::Promoted),
        ));
        $this->assertSame([], DeploymentLifecyclePlan::evaluateRollback($base));

        $pending = $base->withState(DeploymentLifecycleState::Pending);
        $this->assertCount(1, DeploymentLifecyclePlan::evaluateRollback($pending));

        $rolledBack = $base->withState(DeploymentLifecycleState::RolledBack);
        $this->assertCount(1, DeploymentLifecyclePlan::evaluateRollback($rolledBack));
    }
}
