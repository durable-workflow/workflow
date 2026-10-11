<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RequestedTaskScope;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class RequestedTaskScopeTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('connectionScopes')]
    public function testEffectiveDefaultConnectionIncludesInheritedTasksWithoutCrossingOtherScopes(
        string $defaultConnection,
        ?string $requestedConnection,
        array $expected,
    ): void {
        Queue::fake();
        config([
            'queue.default' => $defaultConnection,
        ]);
        $rows = [
            'implicit' => [null, 'default'],
            'empty' => ['', 'default'],
            'symbolic' => ['default', 'default'],
            'redis' => ['redis', 'default'],
            'database' => ['database', 'default'],
            'sqs' => ['sqs', 'default'],
            'other-queue' => [null, 'other-queue'],
        ];
        $labels = [];
        foreach ($rows as $label => [$connection, $queue]) {
            $task = $this->createTask($label, $connection, $queue);
            $labels[$task->id] = $label;
        }

        $query = WorkflowTask::query();
        RequestedTaskScope::apply($query, $requestedConnection, 'default');
        $actual = $query->pluck('id')
            ->map(static fn (string $id): string => $labels[$id])->all();

        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public static function connectionScopes(): array
    {
        return [
            'resolved default Redis' => ['redis', 'redis', ['implicit', 'empty', 'symbolic', 'redis']],
            'resolved default database' => ['database', 'database', ['implicit', 'empty', 'symbolic', 'database']],
            'explicit nondefault database' => ['redis', 'database', ['database']],
            'explicit nondefault Redis' => ['database', 'redis', ['redis']],
            'comma separated includes resolved default' => [
                'redis',
                'redis,sqs',
                ['implicit', 'empty', 'symbolic', 'redis', 'sqs'],
            ],
            'comma separated excludes resolved default' => ['database', 'redis,sqs', ['redis', 'sqs']],
            'existing symbolic default' => ['redis', 'default', ['implicit', 'empty', 'symbolic']],
            'unscoped connection' => ['redis', null, ['implicit', 'empty', 'symbolic', 'redis', 'database', 'sqs']],
        ];
    }

    public function testDefaultWorkerRepairRedispatchesInheritedTaskAndPreservesOtherConnection(): void
    {
        Queue::fake();
        config([
            'queue.default' => 'redis',
        ]);
        $implicit = $this->createTask('implicit-repair', null);
        $other = $this->createTask('other-repair', 'database');
        foreach ([$implicit, $other] as $task) {
            $task->forceFill([
                'available_at' => now()->subMinute(),
                'created_at' => now()->subMinute(),
                'last_dispatched_at' => now()
                    ->subMinute(),
            ])->save();
        }
        Queue::fake();

        $report = TaskWatchdog::runPass(connection: 'redis', queue: 'default');

        $this->assertSame(1, $report['selected_existing_task_candidates']);
        $this->assertSame(1, $report['dispatched_tasks']);
        $this->assertSame([], $report['existing_task_failures']);
        $this->assertSame(1, $implicit->fresh()->repair_count);
        $this->assertSame(0, $other->fresh()->repair_count);
        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $implicit->id
        );
        Queue::assertPushed(RunWorkflowTask::class, 1);
    }

    private function createTask(string $label, ?string $connection, string $queue = 'default'): WorkflowTask
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'scope-' . $label);
        $workflow->start('scope', new WorkflowOptions(queue: $queue));

        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->firstOrFail();
        // Include legacy stored representations without dispatching an
        // envelope through an unconfigured connection during fixture setup.
        $task->forceFill([
            'connection' => $connection,
        ])->save();

        return $task;
    }
}
