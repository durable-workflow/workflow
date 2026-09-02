<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskPriority;
use Workflow\V2\Support\TaskQueuePriorityFairnessContract;
use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * Pins the machine-readable mirror of the task-queue priority + fairness
 * dispatch contract. The authority is published in
 * `docs/architecture/task-queue-priority-fairness.md`; this contract gives
 * polyglot SDK authors and operator tooling a single source of truth so
 * they don't have to read the prose.
 */
final class TaskQueuePriorityFairnessContractTest extends TestCase
{
    public function testManifestAdvertisesIdentity(): void
    {
        $manifest = TaskQueuePriorityFairnessContract::manifest();

        $this->assertSame('durable-workflow.v2.task-queue-priority-fairness.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('task_queue_priority_fairness', $manifest['feature']);
        $this->assertSame('docs/architecture/task-queue-priority-fairness.md', $manifest['authority_doc']);
    }

    public function testFieldsCoverPriorityFairnessKeyAndWeight(): void
    {
        $fields = TaskQueuePriorityFairnessContract::fields();

        $this->assertSame(['priority', 'fairness_key', 'fairness_weight'], array_keys($fields));
    }

    public function testPriorityFieldRangeMatchesNormalizer(): void
    {
        $priority = TaskQueuePriorityFairnessContract::fields()['priority'];

        $this->assertSame('integer', $priority['type']);
        $this->assertSame(TaskPriority::MIN, $priority['min']);
        $this->assertSame(TaskPriority::MIN_USER, $priority['min_user']);
        $this->assertSame(TaskPriority::MAX, $priority['max']);
        $this->assertSame(TaskPriority::DEFAULT, $priority['default']);
        $this->assertTrue($priority['lower_is_more_urgent']);
    }

    public function testFairnessKeyFieldMatchesNormalizer(): void
    {
        $fairnessKey = TaskQueuePriorityFairnessContract::fields()['fairness_key'];

        $this->assertSame('string', $fairnessKey['type']);
        $this->assertTrue($fairnessKey['nullable']);
        $this->assertSame(TaskFairnessKey::MAX_LENGTH, $fairnessKey['max_length']);
        $this->assertSame(TaskFairnessKey::DEFAULT_CLASS, $fairnessKey['default_class_label']);
        $this->assertNull($fairnessKey['default']);
        $this->assertSame('trim_then_lowercase', $fairnessKey['normalization']);
        $this->assertSame(1, preg_match('/' . trim($fairnessKey['pattern'], '/') . '/', 'tenant.alpha'));
    }

    public function testFairnessWeightFieldRange(): void
    {
        $weight = TaskQueuePriorityFairnessContract::fields()['fairness_weight'];

        $this->assertSame('integer', $weight['type']);
        $this->assertSame(1, $weight['min']);
        $this->assertSame(1000, $weight['max']);
        $this->assertSame(1, $weight['default']);
    }

    public function testInheritanceRuleNamesActivityOverride(): void
    {
        $inheritance = TaskQueuePriorityFairnessContract::manifest()['inheritance'];

        $this->assertSame('inherits_priority_and_fairness_from_parent_run', $inheritance['workflow_task']);
        $this->assertSame('inherits_from_run_unless_activity_options_overrides', $inheritance['activity_task']);
        $this->assertSame(
            ['priority', 'fairness_key', 'fairness_weight'],
            $inheritance['activity_options_override_fields'],
        );
        $this->assertSame('Workflow\\V2\\Support\\TaskSchedulingFields', $inheritance['override_resolver']);
    }

    public function testPersistenceColumnsAndIndexesAreEnumerated(): void
    {
        $persistence = TaskQueuePriorityFairnessContract::manifest()['persistence'];

        $this->assertSame(
            ['priority', 'fairness_key', 'fairness_weight'],
            $persistence['columns_per_table']['workflow_runs'],
        );
        $this->assertSame(
            ['priority', 'fairness_key', 'fairness_weight'],
            $persistence['columns_per_table']['workflow_tasks'],
        );

        $purposes = array_column($persistence['indexes'], 'purpose');
        $this->assertContains('priority_ordered_dispatch', $purposes);
        $this->assertContains('observability_by_class', $purposes);
    }

    public function testDispatchSemanticsAreFrozen(): void
    {
        $dispatch = TaskQueuePriorityFairnessContract::manifest()['dispatch'];

        $this->assertSame('priority_asc_then_available_at_asc_then_id', $dispatch['poll_order']);
        $this->assertSame('within_priority_tier_only', $dispatch['fairness_reorder_scope']);
        $this->assertSame(
            'deficit_round_robin_by_recent_dispatch_score_over_weight',
            $dispatch['fairness_algorithm'],
        );
        $this->assertGreaterThan(0, $dispatch['fairness_state_half_life_seconds']);
        $this->assertTrue($dispatch['workflow_and_activity_buckets_isolated']);
        $this->assertTrue($dispatch['urgency_wins_over_fairness']);
    }

    public function testObservabilitySurfaceIsExposed(): void
    {
        $observability = TaskQueuePriorityFairnessContract::manifest()['observability'];

        $this->assertSame('GET', $observability['method']);
        $this->assertStringContainsString('/task-queues/{queue}/priority-fairness', $observability['route']);
        $this->assertTrue($observability['separates_workflow_and_activity']);
        $this->assertSame('priority_fairness_surface', $observability['response_shape']['workflow_task']);
        $this->assertSame('priority_fairness_surface', $observability['response_shape']['activity_task']);

        $shape = $observability['priority_fairness_surface_shape'];
        $this->assertArrayHasKey('ready_tasks', $shape);
        $this->assertArrayHasKey('priority_tiers', $shape);
        $this->assertArrayHasKey('recent_dispatch', $shape);
    }

    public function testAuthoringApisPointAtRealClasses(): void
    {
        $apis = TaskQueuePriorityFairnessContract::manifest()['authoring_apis'];

        foreach (
            ['start_options', 'activity_options', 'priority_normalizer', 'fairness_key_normalizer'] as $key
        ) {
            $this->assertArrayHasKey($key, $apis);
            $this->assertTrue(class_exists($apis[$key]), "Class {$apis[$key]} must exist");
        }
    }

    public function testWorkerProtocolDescribeExposesContract(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('task_queue_priority_fairness', $summary);
        $this->assertSame(TaskQueuePriorityFairnessContract::manifest(), $summary['task_queue_priority_fairness']);
    }

    public function testWorkerProtocolSemanticsHelperReturnsManifest(): void
    {
        $this->assertSame(
            TaskQueuePriorityFairnessContract::manifest(),
            WorkerProtocolVersion::taskQueuePriorityFairnessSemantics(),
        );
    }
}
