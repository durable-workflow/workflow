<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ServiceChildFanOutLiteralTest extends TestCase
{
    public function testLiteralServiceCompletionStartsAndClosesOneThousandChildren(): void
    {
        if (getenv('FANOUT_LITERAL') !== '1') {
            $this->markTestSkipped('The literal service completion runs in the fan-out scaling job.');
        }

        Queue::fake();
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $pendingCountQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$pendingCountQueries): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select count(') && str_contains($sql, 'workflow_links')
                && str_contains($sql, 'exists (') && str_contains($sql, 'not in')) {
                ++$pendingCountQueries;
            }
        });

        $this->measure($bridge, 3);
        $reference = $this->measure($bridge, 100);
        $literal = $this->measure($bridge, 1_000);
        fwrite(STDOUT, json_encode(compact('reference', 'literal', 'pendingCountQueries'), JSON_THROW_ON_ERROR) . "\n");
        $this->assertSame(3, $pendingCountQueries, 'Each admission counts pending children once.');

        foreach (['start', 'close'] as $phase) {
            $this->assertLessThan(
                60.0,
                $literal[$phase],
                "The 1,000-child service completion took too long to {$phase}."
            );
            $this->assertLessThanOrEqual(25.0 * max(0.25, $reference[$phase]), $literal[$phase]);
        }
    }

    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }

    /**
     * @return array{start: float, close: float}
     */
    private function measure(WorkflowTaskBridge $bridge, int $width): array
    {
        $identity = [
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
        ];
        $instance = WorkflowInstance::query()->create([
            ...$identity,
            'run_count' => 1,
            'reserved_at' => now(),
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            ...$identity,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'status' => RunStatus::Waiting,
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $commands = [];
        for ($position = 0; $position < $width; ++$position) {
            $group = [
                'parallel_group_id' => "parallel-children:1:{$width}",
                'parallel_group_kind' => 'child',
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => $width,
                'parallel_group_index' => $position,
            ];
            $commands[] = [
                'type' => 'start_child_workflow',
                'workflow_type' => $identity['workflow_type'],
                'arguments' => Serializer::serialize([str_repeat(chr(97 + $position % 26), 1_024)]),
                'parent_close_policy' => 'terminate',
                ...$group,
                'parallel_group_path' => [$group],
            ];
        }

        $task = self::lease($run);
        $started = hrtime(true);
        $result = $bridge->complete($task->id, $commands);
        $start = (hrtime(true) - $started) / 1_000_000_000;
        $this->assertTrue($result['completed'], $result['reason'] ?? '');
        $this->assertSame(RunStatus::Waiting->value, $result['run_status']);
        $this->assertCount($width, $result['created_task_ids']);
        $links = WorkflowLink::query()->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->pluck('child_workflow_run_id')
            ->all();
        $this->assertCount($width, array_unique($links));
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id);
        $this->assertSame($width, (clone $events)->where('event_type', HistoryEventType::ChildRunStarted)->count());
        $this->assertSame($width, WorkflowTask::query()->whereKey($result['created_task_ids'])
            ->whereIn('workflow_run_id', $links)
            ->where('status', TaskStatus::Ready)->count());

        $task = self::lease($run);
        $started = hrtime(true);
        $result = $bridge->complete($task->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('finished'),
        ]]);
        $close = (hrtime(true) - $started) / 1_000_000_000;
        $this->assertTrue($result['completed'], $result['reason'] ?? '');
        $this->assertSame(RunStatus::Completed->value, $result['run_status']);
        $this->assertSame(
            $width,
            (clone $events)->where('event_type', HistoryEventType::ParentClosePolicyApplied)->count()
        );
        $this->assertSame(0, $events->where('event_type', HistoryEventType::ParentClosePolicyFailed)->count());
        $this->assertSame(
            $width,
            WorkflowRun::query()->whereKey($links)->where('status', RunStatus::Terminated)->count()
        );

        return compact('start', 'close');
    }

    private static function lease(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'lease_owner' => 'service-worker-fixture',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);
    }
}
