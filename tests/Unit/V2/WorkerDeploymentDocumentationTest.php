<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\DeploymentBlockageReason;
use Workflow\V2\Enums\DeploymentLifecycleState;
use Workflow\V2\Enums\WorkflowCompatibilityPolicy;
use Workflow\V2\Support\DeploymentBlockage;
use Workflow\V2\Support\DeploymentLifecyclePlan;
use Workflow\V2\Support\WorkerDeployment;

/**
 * Keeps the worker-deployment document discoverable while pinning stable
 * runtime identifiers and APIs. The Markdown body is intentionally not parsed
 * so editorial changes do not require synchronized test changes.
 */
final class WorkerDeploymentDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/worker-deployment.md';

    public function testContractDocumentExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . self::DOCUMENT);
    }

    public function testDeploymentLifecycleIdentifiersRemainMachineReadable(): void
    {
        $this->assertSame(
            ['pending', 'active', 'promoted', 'draining', 'drained', 'rolled_back'],
            array_map(
                static fn (DeploymentLifecycleState $state): string => $state->value,
                DeploymentLifecycleState::cases(),
            ),
        );
        $this->assertSame(
            [
                'no_compatible_workers',
                'fleet_is_draining',
                'fingerprint_mismatch',
                'replay_safety_failed',
                'missing_worker_heartbeat',
                'incompatible_policy',
                'unknown_deployment',
            ],
            array_map(
                static fn (DeploymentBlockageReason $reason): string => $reason->value,
                DeploymentBlockageReason::cases(),
            ),
        );
        $this->assertSame('pinned', WorkflowCompatibilityPolicy::Pinned->value);
    }

    public function testDeploymentRuntimeAuthoritiesExposeStableApis(): void
    {
        $this->assertTrue(method_exists(DeploymentBlockage::class, 'toArray'));
        $this->assertTrue(method_exists(DeploymentLifecyclePlan::class, 'evaluatePromote'));
        $this->assertTrue(method_exists(DeploymentLifecyclePlan::class, 'evaluateDrain'));
        $this->assertTrue(method_exists(DeploymentLifecyclePlan::class, 'evaluateResume'));
        $this->assertTrue(method_exists(DeploymentLifecyclePlan::class, 'evaluateRollback'));
        $this->assertTrue(method_exists(WorkerDeployment::class, 'forActiveBuild'));
        $this->assertTrue(method_exists(WorkerDeployment::class, 'fromRolloutRow'));
    }
}
