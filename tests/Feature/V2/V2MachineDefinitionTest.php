<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\DefinitionNativeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2MachineDefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.namespaces' => [DefinitionNativeWorkflow::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        DB::connection()->getSchemaBuilder()->create(
            DefinitionNativeWorkflow::TABLE,
            static function (Blueprint $table): void {
                $table->string('run_id')
                    ->primary();
                $table->boolean('owner_current')
                    ->nullable();
                $table->boolean('after_owner_current')
                    ->nullable();
                $table->boolean('target_ready');
                $table->string('state');
                $table->integer('effect_count');
                $table->integer('commit_count');
                $table->string('proof_corruption')
                    ->nullable();
            }
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::connection()->getSchemaBuilder()->dropIfExists(DefinitionNativeWorkflow::TABLE);
        parent::tearDown();
    }

    public static function boundaries(): array
    {
        return [
            'already-satisfied target cannot bypass false invariant' => [false, true, true, 'blocked', 0, 0],
            'already-satisfied target cannot bypass missing invariant' => [null, true, true, 'unknown', 0, 0],
            'no work when both proofs satisfied' => [true, true, true, 'committed', 0, 1],
            'work and fresh proof then commit' => [true, false, true, 'committed', 1, 1],
            'revocation after work blocks commit' => [true, false, false, 'blocked', 1, 0],
            'missing proof after work remains unknown' => [true, false, null, 'unknown', 1, 0],
        ];
    }

    #[DataProvider('boundaries')]
    public function testMachineInvariantGatesActualNativeActivitiesAndRecordedReplay(
        ?bool $before,
        bool $target,
        ?bool $after,
        string $outcome,
        int $effects,
        int $commits
    ): void {
        $stub = $this->start($before, $target, $after);
        $this->drain();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(
            RunStatus::Completed,
            $run->status,
            json_encode(WorkflowFailure::query()->pluck('message')->all(), JSON_THROW_ON_ERROR)
        );
        $this->assertSame($outcome, $run->workflowOutput()['status']);
        $row = DB::table(DefinitionNativeWorkflow::TABLE)->sole();
        $this->assertSame($effects, (int) $row->effect_count);
        $this->assertSame($commits, (int) $row->commit_count);
        $this->assertSame($commits ? 'Processing' : 'Received', $row->state);
        $queries = [];
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening && str_contains($query->sql, DefinitionNativeWorkflow::TABLE)) {
                $queries[] = $query->sql;
            }
        });
        try {
            app(WorkflowReplayer::class)->replay($run->fresh());
        } finally {
            $listening = false;
        }
        $this->assertSame(
            [],
            $queries,
            'Replay must use recorded invariant results without collecting live facts or repeating counters.'
        );
    }

    public static function corruptions(): array
    {
        return [['definition'], ['missing']];
    }

    #[DataProvider('corruptions')]
    public function testUnboundOrMissingProofFailsTheNativeRunBeforeWorkOrCommit(string $corruption): void
    {
        $stub = $this->start(true, true, true, $corruption);
        $this->drain();
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($stub->runId())->status);
        $row = DB::table(DefinitionNativeWorkflow::TABLE)->sole();
        $this->assertSame(0, (int) $row->effect_count);
        $this->assertSame(0, (int) $row->commit_count);
        $this->assertSame('Received', $row->state);
    }

    private function start(?bool $before, bool $target, ?bool $after, ?string $corruption = null): WorkflowStub
    {
        $stub = WorkflowStub::make(
            DefinitionNativeWorkflow::class,
            'defined-native-case',
            DefinitionNativeWorkflow::NAMESPACE
        );
        $stub->start();
        DB::table(DefinitionNativeWorkflow::TABLE)->insert([
            'run_id' => $stub->runId(),
            'owner_current' => $before,
            'after_owner_current' => $after,
            'target_ready' => $target,
            'state' => 'Received',
            'effect_count' => 0,
            'commit_count' => 0,
            'proof_corruption' => $corruption,
        ]);
        return $stub;
    }

    private function drain(): void
    {
        for ($i = 0; $i < 80; ++$i) {
            $task = WorkflowTask::query()->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy(
                'created_at'
            )->orderBy('id')
                ->first();
            if ($task === null) {
                return;
            }if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),TaskType::Activity => new RunActivityTask(
                    $task->id
                ),TaskType::Timer => new RunTimerTask($task->id)
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded native definition test drain did not settle.');
    }
}
