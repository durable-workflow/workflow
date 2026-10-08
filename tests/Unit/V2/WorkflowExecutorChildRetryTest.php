<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class WorkflowExecutorChildRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 21:20:00');
        Queue::fake();
        config()
            ->set([
                'queue.default' => 'redis',
                'workflows.v2.task_dispatch_mode' => 'queue',
                'workflows.v2.compatibility.current' => 'child-retry-build',
                'workflows.v2.compatibility.supported' => ['child-retry-build'],
                'workflows.v2.compatibility.namespace' => 'native-child-retry',
            ]);
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{?array<string, mixed>, int, int, bool}>
     */
    public static function policies(): iterable
    {
        yield 'bounded retries preserve execution deadline and repeat backoff' => [[
            'max_attempts' => 3,
            'backoff_seconds' => [5],
        ], 3, 5, true];
        yield 'immediate retry without timeouts' => [[
            'max_attempts' => 2,
            'backoff_seconds' => [],
        ], 2, 0, false];
        yield 'attempt limit prevents retry' => [[
            'max_attempts' => 1,
        ], 1, 0, true];
        yield 'classified failure prevents retry' => [[
            'max_attempts' => 3,
            'non_retryable_error_types' => [RuntimeException::class],
        ], 1, 0, false];
        yield 'absent policy notifies parent immediately' => [null, 1, 0, false];
    }

    /**
     * @param array<string, mixed>|null $policy
     */
    #[DataProvider('policies')]
    public function testNativeChildFailureHonorsPortableParentPolicyBeforeResuming(
        ?array $policy,
        int $attempts,
        int $backoff,
        bool $timeouts,
    ): void {
        $connection = config('database.default');
        $this->assertIsString($connection);
        config()
            ->set('queue.connections.' . $connection, [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'native-child-retry',
            ]);
        $parent = WorkflowStub::make(NativeRetryParent::class, 'parent-one', 'native-child-retry');
        $this->assertTrue($parent->attemptStart(
            new WorkflowOptions(connection: $connection, queue: 'native-child-retry'),
            new StartOptions(memo: [
                'order' => [false, 0, ''],
            ]),
        )->accepted());
        $parentRun = WorkflowRun::query()->findOrFail($parent->runId());
        $parentTask = WorkflowTask::query()->where('workflow_run_id', $parentRun->id)->sole();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($parentTask->id, 'portable-parent')['claimed']);
        $command = [
            'type' => 'start_child_workflow',
            'workflow_type' => NativeRetryChild::class,
            'arguments' => Serializer::serializeWithCodec('avro', [[false, 0, '', null]]),
            'parent_close_policy' => 'abandon',
            ...($policy === null ? [] : [
                'retry_policy' => $policy,
            ]),
            ...($timeouts ? [
                'execution_timeout_seconds' => 600,
                'run_timeout_seconds' => 120,
            ] : []),
        ];
        $original = $command;
        $this->assertTrue($bridge->complete($parentTask->id, [$command])['completed']);
        $this->assertSame($original, $command);
        $scheduled = WorkflowHistoryEvent::query()->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)->sole();
        $expectedPolicy = $policy === null ? null : [
            'snapshot_version' => 1,
            'max_attempts' => $policy['max_attempts'],
            'backoff_seconds' => $policy['backoff_seconds'] ?? [],
            'non_retryable_error_types' => $policy['non_retryable_error_types'] ?? [],
        ];
        $timeoutPolicy = $timeouts ? [
            'snapshot_version' => 1,
            'execution_timeout_seconds' => 600,
            'run_timeout_seconds' => 120,
        ] : null;
        $this->assertSame($this->ordered($expectedPolicy), $this->ordered($scheduled->payload['retry_policy']));
        $this->assertSame($this->ordered($timeoutPolicy), $this->ordered($scheduled->payload['timeout_policy']));
        $initialLink = WorkflowLink::query()->where('parent_workflow_run_id', $parentRun->id)->sole();
        $initialRun = WorkflowRun::query()->findOrFail($initialLink->child_workflow_run_id);
        $executionDeadline = $initialRun->execution_deadline_at?->toIso8601String();
        $current = $initialRun;

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $task = WorkflowTask::query()->where('workflow_run_id', $current->id)->sole();
            $execution = $bridge->execute($task->id);
            $this->assertTrue($execution['executed']);
            $this->assertSame('failed', $execution['run_status']);
            $this->assertNull($execution['next_task_id']);
            $this->assertSame(RunStatus::Failed, $current->fresh()->status);
            $failure = WorkflowFailure::query()->where('workflow_run_id', $current->id)->sole();
            $this->assertSame(RuntimeException::class, $failure->exception_class);
            $this->assertSame('native child failed', $failure->message);
            $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
            $this->assertNull($task->fresh()->lease_expires_at);
            $this->assertSame(
                min($attempt + 1, $attempts),
                WorkflowLink::query()->where('parent_workflow_run_id', $parentRun->id)->count()
            );
            if ($attempt < $attempts) {
                $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $parentRun->id)
                    ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])->count());
                $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $parentRun->id)
                    ->where('event_type', HistoryEventType::ChildRunFailed->value)->count());
                $retry = WorkflowRun::query()->where('workflow_instance_id', $initialRun->workflow_instance_id)
                    ->where('run_number', $attempt + 1)
                    ->sole();
                $retryLink = WorkflowLink::query()->where('parent_workflow_run_id', $parentRun->id)
                    ->where('child_workflow_run_id', $retry->id)
                    ->sole();
                $this->assertSame($attempt + 1, $retry->run_number);
                $this->assertSame(RunStatus::Pending, $retry->status);
                foreach (['workflow_instance_id', 'workflow_class', 'workflow_type', 'namespace',
                    'arguments', 'payload_codec', 'compatibility', 'connection', 'queue'] as $field) {
                    $this->assertSame($initialRun->{$field}, $retry->{$field});
                }
                $this->assertSame($executionDeadline, $retry->execution_deadline_at?->toIso8601String());
                $this->assertSame($timeouts ? 120 : null, $retry->run_timeout_seconds);
                $this->assertSame(
                    $timeouts ? now()->addSeconds(120)->toIso8601String() : null,
                    $retry->run_deadline_at?->toIso8601String()
                );
                $instance = WorkflowInstance::query()->findOrFail($retry->workflow_instance_id);
                $this->assertSame($retry->id, $instance->current_run_id);
                $this->assertSame($attempt + 1, $instance->run_count);
                $retryTask = WorkflowTask::query()->where('workflow_run_id', $retry->id)->sole();
                $this->assertSame(TaskStatus::Ready, $retryTask->status);
                $this->assertSame(
                    now()->addSeconds($backoff)->toIso8601String(),
                    $retryTask->available_at?->toIso8601String()
                );
                $this->assertSame($connection, $retryTask->connection);
                $this->assertSame('native-child-retry', $retryTask->queue);
                $call = WorkflowChildCall::query()->where('parent_workflow_run_id', $parentRun->id)->sole();
                $this->assertSame($retry->id, $call->resolved_child_run_id);
                $this->assertSame($attempt + 1, $call->metadata['attempt_count']);
                $this->assertSame($current->id, $call->metadata['last_retry_of_child_workflow_run_id']);
                $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $parentRun->id)
                    ->where('event_type', HistoryEventType::ChildRunStarted->value)
                    ->orderByDesc('sequence')
                    ->firstOrFail();
                $expectedStart = array_filter([
                    'sequence' => 1,
                    'workflow_link_id' => $retryLink->id,
                    'child_call_id' => $scheduled->payload['child_call_id'],
                    'child_workflow_instance_id' => $retry->workflow_instance_id,
                    'child_workflow_run_id' => $retry->id,
                    'child_workflow_class' => NativeRetryChild::class,
                    'child_workflow_type' => NativeRetryChild::class,
                    'child_run_number' => $attempt + 1,
                    'parent_close_policy' => 'abandon',
                    'retry_attempt' => $attempt + 1,
                    'retry_of_child_workflow_run_id' => $current->id,
                    'retry_backoff_seconds' => $backoff,
                    'retry_policy' => $expectedPolicy,
                    'timeout_policy' => $timeoutPolicy,
                    'execution_timeout_seconds' => $timeouts ? 600 : null,
                    'run_timeout_seconds' => $timeouts ? 120 : null,
                    'execution_deadline_at' => $executionDeadline,
                    'run_deadline_at' => $timeouts ? now()
                        ->addSeconds(120)
                        ->toIso8601String() : null,
                ], static fn (mixed $value): bool => $value !== null);
                $this->assertSame($this->ordered($expectedStart), $this->ordered($started->payload));
                $this->assertSame(
                    [false, 0, ''],
                    WorkflowMemo::query()->where('workflow_run_id', $retry->id)->where(
                        'key',
                        'order'
                    )->sole()->getValue()
                );
                app(RunTimelineProjector::class)->project($retry->fresh());
                $before = $this->records();
                $this->assertFalse($bridge->execute($task->id)['executed']);
                $this->assertSame($before, $this->records());
                if ($backoff > 0) {
                    $this->assertFalse($bridge->execute($retryTask->id)['executed']);
                    $this->assertSame($before, $this->records());
                    Carbon::setTestNow(now()->addSeconds($backoff));
                }
                $current = $retry;
            }
        }
        $this->assertSame($attempts, WorkflowLink::query()->where('parent_workflow_run_id', $parentRun->id)->count());
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunFailed->value)->count());
        $resume = WorkflowTask::query()->where('workflow_run_id', $parentRun->id)
            ->where('status', TaskStatus::Ready->value)->sole();
        $this->assertSame($current->id, $resume->payload['child_workflow_run_id']);
        $this->assertSame(1, $resume->payload['parent_sequence']);
        $this->assertTrue($bridge->execute($resume->id)['executed']);
        $expected = [
            'message' => 'native child failed',
            'exception_class' => RuntimeException::class,
        ];
        $this->assertSame($expected, $parent->refresh()->output());
        app(RunTimelineProjector::class)->project($parentRun->fresh());
        $before = $this->records();
        $this->assertFalse($bridge->execute($resume->id)['executed']);
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $fresh = WorkflowStub::load('parent-one', 'native-child-retry');
            $this->assertSame($expected, $fresh->query('stateSnapshot'));
            $this->assertSame($expected, $fresh->output());
            $this->assertSame($before, $this->records());
        }
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$entry) {
            $entry = $this->ordered($entry);
        }
        unset($entry);
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class, WorkflowCommand::class,
            WorkflowHistoryEvent::class, WorkflowLink::class, WorkflowChildCall::class, WorkflowFailure::class,
            WorkflowMemo::class, WorkflowRunSummary::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }
        return $records;
    }
}

final class NativeRetryChild extends Workflow
{
    public function handle(array $values): never
    {
        if ($values !== [false, 0, '', null]) {
            throw new RuntimeException('child arguments lost strict values');
        }
        throw new RuntimeException('native child failed');
    }
}

final class NativeRetryParent extends Workflow
{
    private array $observed = [];

    public function handle(): array
    {
        try {
            Workflow::child(NativeRetryChild::class, [false, 0, '', null]);
        } catch (Throwable $failure) {
            return $this->observed = [
                'message' => $failure->getMessage(),
                'exception_class' => $failure instanceof RestoredWorkflowException
                    ? $failure->originalExceptionClass() : $failure::class,
            ];
        }
        return $this->observed;
    }

    #[QueryMethod]
    public function stateSnapshot(): array
    {
        return $this->observed;
    }
}
