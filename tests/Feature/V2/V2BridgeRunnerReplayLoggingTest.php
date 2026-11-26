<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestLoggedSelectionWorkflow;
use Tests\Fixtures\V2\TestLoggedStepsWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\WorkflowReplayState;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

/**
 * The bridge's cold-replay runner reports the replay boundary, so the
 * workflow logger writes each committed step's line once (#227).
 */
final class V2BridgeRunnerReplayLoggingTest extends TestCase
{
    private const FIRST = 'wwHioz3/VYAiNwoaSGVsbG8sIGZpcnN0IQ==';

    private const SECOND = 'wwHioz3/VYAiNwocSGVsbG8sIHNlY29uZCE=';

    /**
     * @var list<string>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if (preg_match('/^(logged steps|selection )/', $event->message) === 1) {
                $this->logged[] = $event->message;
            }
        });
    }

    public function testColdRunnersLogEachCommittedStepOnce(): void
    {
        $started = [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-11-25T12:00:00+00:00',
        ]];
        $first = [...$started, self::completed(2, 1, self::FIRST)];
        $second = [...$first, self::completed(3, 2, self::SECOND)];

        $this->assertSame('schedule_activity', $this->runner($started)->step()->command['type']);
        $this->assertSame(['logged steps started'], $this->logged);

        // Each wake-up is a fresh runner over the full history so far.
        $this->assertSame('schedule_activity', $this->runner($first)->step()->command['type']);
        $this->assertSame(['logged steps started', 'logged steps first done'], $this->logged);

        $completed = $this->runner($second)
            ->step();
        $this->assertTrue($completed->completed);
        $this->assertSame(['Hello, first!', 'Hello, second!'], $completed->result);
        $this->assertSame(
            ['logged steps started', 'logged steps first done', 'logged steps second done'],
            $this->logged,
        );

        // A cold replay of the closed run writes nothing.
        $closed = [...$second, [
            'sequence' => 4,
            'event_type' => 'WorkflowCompleted',
            'payload' => [],
            'recorded_at' => '2026-11-25T12:00:03+00:00',
        ]];
        $this->runner($closed)
            ->step();
        $this->assertCount(3, $this->logged);
    }

    public function testCodeParkedOnAPendingHandleLogsWhenItMovesPast(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestLoggedSelectionWorkflow::class, 'bridge-runner-selection');
        $workflow->start('Taylor');
        $runId = (string) $workflow->runId();
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        $this->logged = [];

        $history = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])
            ->all();

        // A bridge worker replays inside its task transaction: the code after
        // select() parks on the pending activity handle, so its line is dropped.
        $runner = WorkflowFiberRunner::forClass(
            TestLoggedSelectionWorkflow::class,
            'bridge-runner-selection',
            $runId,
            ['Taylor'],
            'avro',
            $history,
        );
        ConfiguredV2Models::connection()->transaction(static function () use ($runner): void {
            $runner->step();
        });

        $this->assertSame([], $this->logged);
    }

    public function testReplayStateFollowsTheRunnersPosition(): void
    {
        $observed = [];
        $runner = $this->runner([
            [
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'recorded_at' => '2026-11-25T12:00:00+00:00',
            ],
            self::completed(2, 1, self::FIRST),
        ]);
        $reflection = new \ReflectionProperty(WorkflowFiberRunner::class, 'workflow');
        $instance = $reflection->getValue($runner);
        $observed[] = $instance->replayState();

        $runner->step();
        $observed[] = $instance->replayState();

        // Outside the workflow's own code the cursor is at the pending call.
        $this->assertSame([WorkflowReplayState::Replaying, WorkflowReplayState::Executing], $observed);
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function runner(array $history): WorkflowFiberRunner
    {
        return WorkflowFiberRunner::forClass(
            TestLoggedStepsWorkflow::class,
            'bridge-runner-logging',
            'bridge-runner-logging-run',
            [],
            'avro',
            $history,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function completed(int $eventSequence, int $sequence, string $result): array
    {
        return [
            'sequence' => $eventSequence,
            'event_type' => 'ActivityCompleted',
            'payload' => [
                'sequence' => $sequence,
                'activity_type' => 'Tests\\Fixtures\\V2\\TestGreetingActivity',
                'result' => $result,
                'payload_codec' => 'avro',
            ],
            'recorded_at' => sprintf('2026-11-25T12:00:0%d+00:00', $eventSequence - 1),
        ];
    }
}
