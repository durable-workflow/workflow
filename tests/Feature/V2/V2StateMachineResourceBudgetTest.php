<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestBudgetedTransitionWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2StateMachineResourceBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'database',
        ]);
        DB::connection()->getSchemaBuilder()->create('budget_effect_journal', static function (Blueprint $table): void {
            $table->string('action')
                ->primary();
            $table->text('execution');
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        DB::connection()->getSchemaBuilder()->dropIfExists('budget_effect_journal');
        parent::tearDown();
    }

    public function testExactCommandBudgetCompletesAndColdReplayDoesNotRepeatEffects(): void
    {
        $run = $this->execute(6);
        $this->assertSame('committed', $run->workflowOutput()['status']);
        $this->assertSame(2, DB::table('budget_effect_journal')->where('status', 'active')->count());
        $this->assertSame(6, $this->scheduled($run));
        $this->assertColdReplay($run);
    }

    public function testPartialWorkIsBlockedBeforeSecondEffectAndNativeSagaStillCompensates(): void
    {
        $run = $this->execute(3, compensate: true);
        $this->assertSame('blocked', $run->workflowOutput()['status']);
        $this->assertSame('state_machine_commands_limit_exceeded', $run->workflowOutput()['reason']);
        $this->assertSame(['first'], DB::table('budget_effect_journal')->pluck('action')->all());
        $this->assertSame(['compensated'], DB::table('budget_effect_journal')->pluck('status')->all());
        // Three forward commands, then the compensation snapshot and original undo.
        $this->assertSame(5, $this->scheduled($run));
        $this->assertColdReplay($run);
    }

    public static function historyLimits(): array
    {
        return [
            'before any command by events' => [0, null, 'history_events'],
            'before any command by bytes' => [null, 0, 'history_bytes'],
            'after a recorded prefix' => [14, null, 'history_events'],
        ];
    }

    #[DataProvider('historyLimits')]
    public function testActualNativeHistoryAdmissionReplaysTheSameBlockedDecision(
        ?int $events,
        ?int $bytes,
        string $dimension
    ): void {
        $run = $this->execute(100, $events, $bytes);
        $this->assertSame('blocked', $run->workflowOutput()['status']);
        $this->assertSame('state_machine_' . $dimension . '_limit_exceeded', $run->workflowOutput()['reason']);
        if ($events === 0 || $bytes === 0) {
            $this->assertSame(0, $this->scheduled($run));
            $this->assertSame(0, DB::table('budget_effect_journal')->count());
        } else {
            $this->assertGreaterThan(0, $this->scheduled($run));
            $this->assertLessThan(6, $this->scheduled($run));
        }
        $this->assertColdReplay($run);
    }

    private function execute(
        int $commands,
        ?int $events = null,
        ?int $bytes = null,
        bool $compensate = false
    ): WorkflowRun {
        $stub = WorkflowStub::make(TestBudgetedTransitionWorkflow::class, 'state-machine-resource-budget');
        $stub->start($commands, $events, $bytes, $compensate);
        for ($i = 0; $i < 40; ++$i) {
            $task = WorkflowTask::query()->where('status', TaskStatus::Ready)->orderBy('created_at')->orderBy(
                'id'
            )->first();
            if ($task === null) {
                break;
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                default => throw new \LogicException('This finite fixture schedules no timers.'),
            };
            $this->app->call([$job, 'handle']);
        }
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(RunStatus::Completed, $run->status);
        return $run;
    }

    private function scheduled(WorkflowRun $run): int
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityScheduled)->count();
    }

    private function assertColdReplay(WorkflowRun $run): void
    {
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->toArray();
        $journal = DB::table('budget_effect_journal')->orderBy('action')->get()->toArray();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'budget_effect_journal')) {
                $queries[] = $query->sql;
            }
        });
        $replay = app(WorkflowReplayer::class)->replay($run->fresh());
        $this->assertSame([], $queries, 'Cold replay must not read or write the synthetic service.');
        $this->assertNull($replay->current, 'Replay must reach the original completion, not stop at new work.');
        $this->assertInstanceOf(TestBudgetedTransitionWorkflow::class, $replay->workflow);
        $this->assertSame($run->workflowOutput(), $replay->workflow->decision);
        $this->assertEquals($journal, DB::table('budget_effect_journal')->orderBy('action')->get()->toArray());
        $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->toArray());
    }
}
