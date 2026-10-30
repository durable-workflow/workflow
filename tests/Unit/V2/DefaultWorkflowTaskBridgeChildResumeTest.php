<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class DefaultWorkflowTaskBridgeChildResumeTest extends TestCase
{
    private WorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function lateChildOutcomes(): iterable
    {
        foreach (['complete', 'fail', 'cancel', 'terminate'] as $outcome) {
            foreach (['timer', 'signal'] as $wait) {
                yield $outcome . '/' . $wait => [$outcome, $wait, false];
            }
        }
        yield 'legacy claim/timer' => ['complete', 'timer', true];
        yield 'terminal parent' => ['complete', 'terminal', false];
    }

    #[DataProvider('lateChildOutcomes')]
    public function testChildResolutionDuringParentLeaseCreatesAFollowUpAfterWaitingCompletion(
        string $outcome,
        string $wait,
        bool $legacyClaim,
    ): void {
        $parentRun = $this->createWaitingRun();
        $parentTask = $this->createLeasedTask($parentRun);
        $started = $this->bridge->complete($parentTask->id, [
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['first-child']),
            ],
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize(['second-child']),
            ],
        ]);
        $this->assertTrue($started['completed']);

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();
        $this->assertCount(2, $links);
        $childTasks = $links->map(static fn (WorkflowLink $link): WorkflowTask => WorkflowTask::query()
            ->where('workflow_run_id', $link->child_workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->sole());

        $this->assertTrue($this->bridge->claimStatus($childTasks[0]->id, 'first-child-worker')['claimed']);
        $this->assertTrue($this->bridge->complete($childTasks[0]->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('first-result'),
        ]])['completed']);
        $resumeTask = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->assertTrue($this->bridge->claimStatus($resumeTask->id, 'parent-worker')['claimed']);
        if ($legacyClaim) {
            $payload = $resumeTask->fresh()
->payload;
            unset($payload['history_sequence_at_claim']);
            $resumeTask->forceFill([
                'payload' => $payload,
            ])->save();
        }
        $leasedHistory = $this->bridge->historyPayload($resumeTask->id);
        $this->assertCount(1, array_filter(
            $leasedHistory['history_events'],
            static fn (array $event): bool => $event['event_type'] === HistoryEventType::ChildRunCompleted->value
        ));

        if (in_array($outcome, ['cancel', 'terminate'], true)) {
            $closed = $this->app->make(WorkflowControlPlane::class)->{$outcome}($links[1]->child_workflow_instance_id);
            $this->assertTrue($closed['accepted'], json_encode($closed, JSON_THROW_ON_ERROR));
        } else {
            $this->assertTrue($this->bridge->claimStatus($childTasks[1]->id, 'second-child-worker')['claimed']);
            $closed = $this->bridge->complete($childTasks[1]->id, $outcome === 'complete' ? [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('second-result'),
            ]] : [[
                'type' => 'fail_workflow',
                'message' => 'second child failed',
            ]]);
            $this->assertTrue($closed['completed'], json_encode($closed, JSON_THROW_ON_ERROR));
        }
        $readyBeforeCompletion = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->count();
        $this->assertLessThanOrEqual(1, $readyBeforeCompletion);
        if (in_array($outcome, ['complete', 'fail'], true)) {
            $this->assertSame(0, $readyBeforeCompletion);
        }

        if ($wait === 'terminal') {
            $completed = $this->bridge->complete($resumeTask->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('parent-finished'),
            ]]);
            $this->assertTrue($completed['completed']);
            $this->assertSame(RunStatus::Completed->value, $completed['run_status']);
            $this->assertSame(0, $this->openParentTaskCount($parentRun));
            return;
        }

        $waiting = $this->bridge->complete($resumeTask->id, $wait === 'timer' ? [[
            'type' => 'start_timer',
            'delay_seconds' => 3600,
        ]] : [[
            'type' => 'open_signal_wait',
            'signal_name' => 'unrelated-signal',
        ]]);
        $this->assertTrue($waiting['completed'], json_encode($waiting, JSON_THROW_ON_ERROR));
        $this->assertSame(RunStatus::Waiting->value, $waiting['run_status']);
        $readyTasks = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->get();
        $this->assertCount(1, $readyTasks, 'A child result outside the leased history needs a new delivery turn.');
        $followUp = $readyTasks->sole();
        $this->assertNotSame($resumeTask->id, $followUp->id);
        $this->assertTrue($followUp->available_at->lessThanOrEqualTo(now()));
        if ($readyBeforeCompletion === 0) {
            $this->assertContains($followUp->id, $waiting['created_task_ids']);
        }
        $this->assertTrue($this->bridge->claimStatus($followUp->id, 'next-parent-worker')['claimed']);
        $history = $this->bridge->historyPayload($followUp->id);
        $this->assertCount(2, array_filter(
            $history['history_events'],
            static fn (array $event): bool => in_array($event['event_type'], [
                HistoryEventType::ChildRunCompleted->value,
                HistoryEventType::ChildRunFailed->value,
                HistoryEventType::ChildRunCancelled->value,
                HistoryEventType::ChildRunTerminated->value,
            ], true)
        ));
        $completed = $this->bridge->complete($followUp->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(['first-result', 'second-result']),
        ]]);
        $this->assertTrue($completed['completed']);
        $this->assertSame(RunStatus::Completed->value, $completed['run_status']);
        $this->assertSame('task_not_leased', $this->bridge->complete($followUp->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(['first-result', 'second-result']),
        ]])['reason']);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
    }

    public function testResolutionsVisibleAtClaimStayCoalescedWithoutAnotherReplay(): void
    {
        $parent = $this->createWaitingRun();
        $started = $this->bridge->complete(
            $this->createLeasedTask($parent)
->id,
            array_map(static fn (string $name): array => [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize([$name]),
            ], ['first', 'second'])
        );
        $this->assertTrue($started['completed']);
        $links = WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->orderBy('sequence')->get();
        $this->assertCount(2, $links);
        foreach ($links as $link) {
            $child = WorkflowTask::query()->where('workflow_run_id', $link->child_workflow_run_id)->sole();
            $this->assertTrue($this->bridge->claimStatus($child->id, 'child-worker')['claimed']);
            $this->assertTrue($this->bridge->complete($child->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('child-result'),
            ]])['completed']);
        }
        $this->assertSame(1, $this->openParentTaskCount($parent));
        $resume = WorkflowTask::query()->where('workflow_run_id', $parent->id)
            ->where('status', TaskStatus::Ready->value)->sole();
        $this->assertTrue($this->bridge->claimStatus($resume->id, 'parent-worker')['claimed']);
        $waiting = $this->bridge->complete($resume->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 3600,
        ]]);
        $this->assertTrue($waiting['completed']);
        $this->assertSame(0, $this->openParentTaskCount($parent));
        $this->assertCount(1, $waiting['created_task_ids']);
    }

    public function testPartialParallelGroupDoesNotWakeParentBeforeItsOtherChild(): void
    {
        $parent = $this->createWaitingRun();
        $commands = [];
        foreach ([0, 1] as $index) {
            $entry = [
                'parallel_group_id' => 'parallel-children:1:2',
                'parallel_group_kind' => 'child',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 2,
                'parallel_group_index' => $index,
            ];
            $commands[] = [
                'type' => 'start_child_workflow',
                'workflow_type' => 'test-greeting-workflow',
                'arguments' => Serializer::serialize([$index]),
                ...$entry,
                'parallel_group_path' => [$entry],
            ];
        }
        $this->assertTrue($this->bridge->complete($this->createLeasedTask($parent)->id, $commands)['completed']);
        $links = WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)->orderBy('sequence')->get();
        $this->assertCount(2, $links);
        // An unrelated delivery turn can be leased while the group is waiting.
        $resume = $this->createLeasedTask($parent);
        $resume->forceFill([
            'status' => TaskStatus::Ready,
        ])->save();
        $this->assertTrue($this->bridge->claimStatus($resume->id, 'parent-worker')['claimed']);
        foreach ($links as $index => $link) {
            $child = WorkflowTask::query()->where('workflow_run_id', $link->child_workflow_run_id)->sole();
            $this->assertTrue($this->bridge->claimStatus($child->id, 'child-worker')['claimed']);
            $this->assertTrue($this->bridge->complete($child->id, [[
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('child-result'),
            ]])['completed']);
            if ($index === 0) {
                $waiting = $this->bridge->complete($resume->id, [[
                    'type' => 'start_timer',
                    'delay_seconds' => 3600,
                ]]);
                $this->assertTrue($waiting['completed']);
                $this->assertSame(0, $this->openParentTaskCount($parent));
            }
        }
        $this->assertSame(1, $this->openParentTaskCount($parent));
    }

    private function openParentTaskCount(WorkflowRun $run): int
    {
        return WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])->count();
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        return $task;
    }

    private function createWaitingRun(?string $namespace = null): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'namespace' => $namespace,
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'namespace' => $namespace,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
