<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\DeploymentLifecycleState;
use Workflow\V2\Enums\WorkflowCompatibilityPolicy;
use Workflow\V2\Support\WorkerDeployment;

/**
 * Pins the value-object semantics of the first-class
 * {@see WorkerDeployment} surface that the deployment lifecycle
 * contract documents in docs/architecture/worker-deployment.md.
 */
final class WorkerDeploymentTest extends TestCase
{
    public function testForActiveBuildNormalizesAndDefaultsToPinnedPolicy(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: ' default ',
            taskQueue: ' default-queue ',
            buildId: ' build-7 ',
            requiredCompatibility: ' v3 ',
            recordedFingerprint: ' fp-abc ',
            workflowTypes: ['  Foo\Bar  ', 'Foo\Baz', 'Foo\Bar', ' '],
        );

        $this->assertSame('default', $deployment->namespace);
        $this->assertSame('default-queue', $deployment->taskQueue);
        $this->assertSame('build-7', $deployment->buildId);
        $this->assertSame('v3', $deployment->requiredCompatibility);
        $this->assertSame('fp-abc', $deployment->recordedFingerprint);
        $this->assertSame(['Foo\\Bar', 'Foo\\Baz'], $deployment->workflowTypes);
        $this->assertSame(WorkflowCompatibilityPolicy::Pinned, $deployment->compatibilityPolicy);
        $this->assertSame(DeploymentLifecycleState::Active, $deployment->state);
        $this->assertTrue($deployment->acceptsNewWork());
        $this->assertFalse($deployment->isPromoted());
        $this->assertFalse($deployment->isDraining());
    }

    public function testForActiveBuildRejectsEmptyNamespaceOrTaskQueue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerDeployment::forActiveBuild(namespace: '   ', taskQueue: 'q', buildId: 'b');
    }

    public function testNameUsesUnversionedPlaceholderForNullBuildId(): void
    {
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'tenant-a',
            taskQueue: 'work',
            buildId: null,
        );

        $this->assertSame('tenant-a/work@unversioned', $deployment->name());
        $this->assertNull($deployment->buildId);
    }

    public function testFromRolloutRowDerivesDrainingStateFromIntent(): void
    {
        $now = CarbonImmutable::create(2026, 5, 1, 12, 0, 0);

        $deployment = WorkerDeployment::fromRolloutRow([
            'namespace' => 'default',
            'task_queue' => 'queue',
            'build_id' => 'build-9',
            'drain_intent' => 'draining',
            'drained_at' => $now,
            'compatibility_policy' => 'auto_upgrade',
            'workflow_types' => 'Foo\\Bar, Foo\\Baz',
        ]);

        $this->assertSame(DeploymentLifecycleState::Draining, $deployment->state);
        $this->assertFalse($deployment->acceptsNewWork());
        $this->assertTrue($deployment->isDraining());
        $this->assertSame(WorkflowCompatibilityPolicy::AutoUpgrade, $deployment->compatibilityPolicy);
        $this->assertTrue($deployment->compatibilityPolicy->allowsAutoUpgrade());
        $this->assertFalse($deployment->compatibilityPolicy->requiresFingerprintPin());
        $this->assertSame(['Foo\\Bar', 'Foo\\Baz'], $deployment->workflowTypes);
        $this->assertSame($now->toIso8601String(), $deployment->drainedAt?->toIso8601String());
    }

    public function testFromRolloutRowDerivesRolledBackStateAndIsTerminal(): void
    {
        $deployment = WorkerDeployment::fromRolloutRow([
            'namespace' => 'default',
            'task_queue' => 'queue',
            'build_id' => 'b',
            'rolled_back_at' => '2026-04-30T00:00:00Z',
        ]);

        $this->assertSame(DeploymentLifecycleState::RolledBack, $deployment->state);
        $this->assertTrue($deployment->state->isTerminal());
        $this->assertFalse($deployment->acceptsNewWork());
    }

    public function testFromRolloutRowFallsBackToPinnedWhenPolicyMissing(): void
    {
        $deployment = WorkerDeployment::fromRolloutRow([
            'namespace' => 'default',
            'task_queue' => 'queue',
            'build_id' => 'b',
        ]);

        $this->assertSame(WorkflowCompatibilityPolicy::Pinned, $deployment->compatibilityPolicy);
        $this->assertSame(DeploymentLifecycleState::Active, $deployment->state);
    }

    public function testWithStateStampsLifecycleAuditTimestamps(): void
    {
        $now = CarbonImmutable::create(2026, 5, 2, 9, 30, 0);
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'build-1',
        );

        $promoted = $deployment->withState(DeploymentLifecycleState::Promoted, $now);
        $this->assertSame($now->toIso8601String(), $promoted->promotedAt?->toIso8601String());
        $this->assertNull($promoted->drainedAt);
        $this->assertNull($promoted->rolledBackAt);

        $drained = $promoted->withState(DeploymentLifecycleState::Draining, $now);
        $this->assertSame($now->toIso8601String(), $drained->drainedAt?->toIso8601String());
        $this->assertSame($now->toIso8601String(), $drained->promotedAt?->toIso8601String());

        $rolledBack = $promoted->withState(DeploymentLifecycleState::RolledBack, $now);
        $this->assertSame($now->toIso8601String(), $rolledBack->rolledBackAt?->toIso8601String());
    }

    public function testToArrayProjectsTheStableShape(): void
    {
        $now = CarbonImmutable::create(2026, 5, 1, 12, 0, 0);
        $deployment = WorkerDeployment::forActiveBuild(
            namespace: 'default',
            taskQueue: 'queue',
            buildId: 'b1',
            requiredCompatibility: 'v3',
            recordedFingerprint: 'fp',
            compatibilityPolicy: WorkflowCompatibilityPolicy::AutoUpgrade,
            workflowTypes: ['Foo\\Bar'],
        )->withState(DeploymentLifecycleState::Promoted, $now);

        $array = $deployment->toArray();

        $this->assertSame('default/queue@b1', $array['name']);
        $this->assertSame('default', $array['namespace']);
        $this->assertSame('queue', $array['task_queue']);
        $this->assertSame('b1', $array['build_id']);
        $this->assertSame('promoted', $array['state']);
        $this->assertTrue($array['accepts_new_work']);
        $this->assertSame('auto_upgrade', $array['compatibility_policy']);
        $this->assertSame('v3', $array['required_compatibility']);
        $this->assertSame('fp', $array['recorded_fingerprint']);
        $this->assertSame(['Foo\\Bar'], $array['workflow_types']);
        $this->assertSame($now->toIso8601String(), $array['promoted_at']);
        $this->assertNull($array['drained_at']);
        $this->assertNull($array['rolled_back_at']);
    }

    public function testLifecycleStateAcceptsNewWorkRejectsTerminalAndDrainingStates(): void
    {
        $this->assertTrue(DeploymentLifecycleState::Pending->acceptsNewWork());
        $this->assertTrue(DeploymentLifecycleState::Active->acceptsNewWork());
        $this->assertTrue(DeploymentLifecycleState::Promoted->acceptsNewWork());
        $this->assertFalse(DeploymentLifecycleState::Draining->acceptsNewWork());
        $this->assertFalse(DeploymentLifecycleState::Drained->acceptsNewWork());
        $this->assertFalse(DeploymentLifecycleState::RolledBack->acceptsNewWork());

        $this->assertFalse(DeploymentLifecycleState::Pending->isTerminal());
        $this->assertFalse(DeploymentLifecycleState::Active->isTerminal());
        $this->assertFalse(DeploymentLifecycleState::Promoted->isTerminal());
        $this->assertFalse(DeploymentLifecycleState::Draining->isTerminal());
        $this->assertTrue(DeploymentLifecycleState::Drained->isTerminal());
        $this->assertTrue(DeploymentLifecycleState::RolledBack->isTerminal());
    }
}
