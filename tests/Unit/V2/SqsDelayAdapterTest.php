<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Activity;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class SqsDelayAdapterTest extends TestCase
{
    private const QUEUE_URL = 'https://sqs.us-east-1.amazonaws.com/123456789012/delayed-contract';

    private SqsClient $client;

    /**
     * @var list<array{MessageId: string, ReceiptHandle: string, Body: string, delay: int}>
     */
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00.123456 UTC');
        config([
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.connection' => 'sqs-adapter',
            'workflows.v2.queue' => self::QUEUE_URL,
            'queue.default' => 'sqs-adapter',
            'queue.connections.sqs-adapter' => [
                'driver' => 'sqs',
                'queue' => self::QUEUE_URL,
            ],
            'cache.default' => 'array',
        ]);
        AdapterRetryActivity::$calls = 0;
        AdapterDelayedWorkflow::$calls = 0;

        // AWS performs normal command validation and request serialization. Its
        // official handler substitutes only the HTTP response, with no live AWS call.
        $reply = function (CommandInterface $command, RequestInterface $request): Result {
            $this->assertSame('POST', $request->getMethod());
            if ($command->getName() === 'DeleteMessage') {
                return new Result([]);
            }
            $this->assertSame('SendMessage', $command->getName());
            $this->assertSame(self::QUEUE_URL, $command['QueueUrl']);
            $body = (string) $request->getBody();
            if (str_contains($request->getHeaderLine('Content-Type'), 'json')) {
                $wire = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } else {
                parse_str($body, $wire);
            }
            $this->assertSame($command['MessageBody'], $wire['MessageBody']);
            $delay = $command['DelaySeconds'] ?? 0;
            $this->assertSame($delay, (int) ($wire['DelaySeconds'] ?? 0));
            $this->assertGreaterThanOrEqual(0, $delay);
            $this->assertLessThanOrEqual(900, $delay);
            $id = 'message-' . count($this->messages);
            $this->messages[] = [
                'MessageId' => $id,
                'ReceiptHandle' => $id . '-receipt',
                'Body' => $command['MessageBody'],
                'delay' => $delay,
            ];
            return new Result([
                'MessageId' => $id,
            ]);
        };
        $this->client = new SqsClient([
            'version' => '2012-11-05',
            'region' => 'us-east-1',
            'credentials' => [
                'key' => 'test-key',
                'secret' => 'test-secret',
            ],
            'handler' => new MockHandler(array_fill(0, 64, $reply)),
        ]);
        $queue = new SqsQueue($this->client, self::QUEUE_URL);
        $this->app['queue']->addConnector('sqs', static fn (): ConnectorInterface => new class(
            $queue
        ) implements ConnectorInterface {
            public function __construct(
                private readonly SqsQueue $queue
            ) {
            }

            public function connect(array $config): SqsQueue
            {
                return $this->queue;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testDayLongActivityRetryCrossesTheRealAdapterWithoutEarlyExecution(): void
    {
        $stub = $this->start(AdapterRetryWorkflow::class);
        $this->deliverLast();
        $this->deliverLast();
        $retry = $this->readyTask($stub->runId(), TaskType::Activity);
        $due = $retry->getRawOriginal('available_at');
        $before = $this->history($stub->runId());
        $this->assertSame(1, AdapterRetryActivity::$calls);
        $this->assertSame(1, $retry->attempt_count);
        $this->assertSame(900, $this->messages[array_key_last($this->messages)]['delay']);

        $queued = count($this->messages);
        Carbon::setTestNow(now()->addSeconds(900));
        $this->deliverLast();
        $this->assertCount($queued + 1, $this->messages);
        $this->assertSame(900, $this->messages[array_key_last($this->messages)]['delay']);
        $this->assertSame($due, $retry->fresh()->getRawOriginal('available_at'));
        $this->assertSame(TaskStatus::Ready, $retry->fresh()->status);
        $this->assertSame(1, $retry->fresh()->attempt_count);
        $this->assertSame(1, ActivityAttempt::query()->count());
        $this->assertSame(1, AdapterRetryActivity::$calls);
        $this->assertSame($before, $this->history($stub->runId()));

        Carbon::setTestNow($retry->available_at);
        $delivery = $this->messages[array_key_last($this->messages)];
        $this->deliver($delivery);
        $this->deliver($delivery);
        $this->assertSame(2, AdapterRetryActivity::$calls);
        $this->assertSame(2, ActivityAttempt::query()->count());
        $this->deliverLast();
        $this->assertSame([9223372036854775807, null, '雪'], $stub->refresh()->output());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('event_type', HistoryEventType::ActivityCompleted)->count());
    }

    public function testEarlyWorkflowMessageRedispatchesThroughTheAdapterBeforeClaiming(): void
    {
        $stub = $this->start(AdapterDelayedWorkflow::class);
        $task = $this->readyTask($stub->runId(), TaskType::Workflow);
        $task->forceFill([
            'available_at' => now()
                ->addHour(),
        ])->save();
        $due = $task->getRawOriginal('available_at');
        $before = $this->history($stub->runId());
        TaskDispatcher::dispatch($task);
        $this->assertSame(900, $this->messages[array_key_last($this->messages)]['delay']);

        $queued = count($this->messages);
        Carbon::setTestNow(now()->addSeconds(900));
        $this->deliverLast();
        $this->assertCount($queued + 1, $this->messages);
        $this->assertSame(900, $this->messages[array_key_last($this->messages)]['delay']);
        $this->assertSame($due, $task->fresh()->getRawOriginal('available_at'));
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertNull($task->fresh()->lease_owner);
        $this->assertSame(0, $task->fresh()->attempt_count);
        $this->assertSame(0, AdapterDelayedWorkflow::$calls);
        $this->assertSame($before, $this->history($stub->runId()));

        Carbon::setTestNow($task->available_at);
        $delivery = $this->messages[array_key_last($this->messages)];
        $this->deliver($delivery);
        $this->deliver($delivery);
        $this->assertSame(1, AdapterDelayedWorkflow::$calls);
        $this->assertSame([9223372036854775807, null, '雪'], $stub->refresh()->output());
    }

    private function start(string $workflow): WorkflowStub
    {
        $stub = WorkflowStub::make($workflow);
        $stub->start(new StartOptions(executionTimeoutSeconds: 259200, runTimeoutSeconds: 172800));
        return $stub;
    }

    private function readyTask(string $runId, TaskType $type): WorkflowTask
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)->where('task_type', $type)
            ->where('status', TaskStatus::Ready)->sole();
    }

    private function history(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()->toArray();
    }

    private function deliverLast(): void
    {
        $this->assertNotEmpty($this->messages);
        $this->deliver($this->messages[array_key_last($this->messages)]);
    }

    /**
     * @param array{MessageId: string, ReceiptHandle: string, Body: string, delay: int} $message
     */
    private function deliver(array $message): void
    {
        $job = new SqsJob($this->app, $this->client, $message, 'sqs-adapter', self::QUEUE_URL);
        $job->fire();
    }
}

final class AdapterRetryWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::activity(AdapterRetryActivity::class, new ActivityOptions(
            maxAttempts: 2,
            backoff: [86400],
            startToCloseTimeout: 30,
            scheduleToCloseTimeout: 172800,
        ));
    }
}

final class AdapterRetryActivity extends Activity
{
    public static int $calls = 0;

    public function handle(): array
    {
        ++self::$calls;
        if ($this->attemptCount() === 1) {
            throw new RuntimeException('Retry once after one day.');
        }
        return [9223372036854775807, null, '雪'];
    }
}

final class AdapterDelayedWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(): array
    {
        ++self::$calls;
        return [9223372036854775807, null, '雪'];
    }
}
