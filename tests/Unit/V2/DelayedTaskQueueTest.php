<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestFractionalRetryActivity;
use Tests\Fixtures\V2\TestFractionalRetryWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class DelayedTaskQueueTest extends TestCase
{
    /**
     * @var list<JobFailed>
     */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('jobs');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')
                ->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')
                ->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        TestFractionalRetryActivity::$calls = 0;
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00.500000'));
        $this->app['events']->listen(JobFailed::class, function (JobFailed $event): void {
            $this->failures[] = $event;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function queues(): array
    {
        return [
            'database' => ['database'],
            'redis' => ['redis'],
        ];
    }

    #[DataProvider('queues')]
    public function testFractionalRetryCompletesWithoutTransportFailure(string $driver): void
    {
        $this->configureQueue($driver);
        $workflow = WorkflowStub::make(TestFractionalRetryWorkflow::class);
        $workflow->start();
        $this->runNextJob();
        $this->runNextJob();

        $this->assertSame(1, TestFractionalRetryActivity::$calls);
        $task = WorkflowTask::query()->where('task_type', TaskType::Activity)
            ->where('status', TaskStatus::Ready)->sole();
        $this->assertSame('12:00:01.500000', $task->available_at->format('H:i:s.u'));

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:01.000000'));
        $this->runNextJob();
        $this->assertSame(1, TestFractionalRetryActivity::$calls);
        $this->assertSame(1, $task->fresh()->attempt_count);

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:02.000000'));
        $this->runNextJob();
        $this->runNextJob();

        $this->assertCount(0, $this->failures);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('completed', $workflow->output());
        $this->assertSame(2, TestFractionalRetryActivity::$calls);
        $this->assertSame(2, ActivityAttempt::query()->count());
        $this->assertSame(0, Queue::connection('delivery-test')->size('delayed-tasks'));

        $duplicate = (new RunActivityTask($task->id))
            ->onConnection('delivery-test')
            ->onQueue('delayed-tasks');
        $this->app->make(Dispatcher::class)->dispatch($duplicate);
        $this->runNextJob();
        $this->assertSame(2, TestFractionalRetryActivity::$calls);
        $this->assertSame(2, ActivityAttempt::query()->count());
        $this->assertCount(0, $this->failures);
    }

    public function testEarlyTimerDeliveriesDoNotExhaustTransportAttempts(): void
    {
        $this->configureQueue('database');
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(1);
        $this->runNextJob();

        $task = WorkflowTask::query()->where('task_type', TaskType::Timer)->sole();

        // Force repeated early broker delivery without changing the durable deadline.
        for ($delivery = 0; $delivery < 8; ++$delivery) {
            DB::table('jobs')->update([
                'available_at' => now()
                    ->getTimestamp(),
            ]);
            $this->runNextJob();
            $this->assertCount(0, $this->failures);
            $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
            $this->assertSame(0, $task->fresh()->attempt_count);
            $this->assertSame(0, (int) DB::table('jobs')->sole()->attempts);
        }

        Carbon::setTestNow(now()->addSeconds(2));
        $this->runNextJob();
        $this->runNextJob();
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(1, $task->fresh()->attempt_count);
        $this->assertCount(0, $this->failures);
    }

    public function testQueueWorkCommandCompletesTheFractionalRetryWithoutWatchdogRepair(): void
    {
        $this->configureQueue('database');
        Cache::forever(TaskWatchdog::LOOP_THROTTLE_KEY, true);
        Cache::forever('workflow:watchdog:looping', true);
        $workflow = WorkflowStub::make(TestFractionalRetryWorkflow::class);
        $workflow->start();

        foreach (['12:00:00.500000', '12:00:01.000000', '12:00:02.000000'] as $time) {
            Carbon::setTestNow(Carbon::parse('2026-01-15 ' . $time));
            $this->assertSame(0, Artisan::call('queue:work', [
                'connection' => 'delivery-test',
                '--queue' => 'delayed-tasks',
                '--stop-when-empty' => true,
                '--sleep' => 0,
                '--memory' => 512,
            ]));

            if ($time !== '12:00:02.000000') {
                $this->assertSame(1, TestFractionalRetryActivity::$calls);
                $this->assertFalse($workflow->refresh()->completed());
            }
        }

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('completed', $workflow->output());
        $this->assertSame(2, TestFractionalRetryActivity::$calls);
        $this->assertSame(2, ActivityAttempt::query()->count());
        $this->assertCount(0, $this->failures);
    }

    public function testAnEarlyActivityDeliveryPreservesTheBusinessRetryLimit(): void
    {
        $this->configureQueue('database');
        $workflow = WorkflowStub::make(TestFractionalRetryWorkflow::class);
        $workflow->start(2);
        $this->runNextJob();
        $this->runNextJob();

        DB::table('jobs')->update([
            'available_at' => now()
                ->getTimestamp(),
        ]);
        $this->runNextJob();
        $this->assertSame(1, TestFractionalRetryActivity::$calls);
        $this->assertSame(0, (int) DB::table('jobs')->sole()->attempts);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->runNextJob();
        $this->runNextJob();
        $this->assertTrue($workflow->refresh()->failed());
        $this->assertSame(2, TestFractionalRetryActivity::$calls);
        $this->assertSame(2, ActivityAttempt::query()->count());
        $this->assertCount(0, $this->failures);
    }

    public function testInfrastructureFailureStillExhaustsTheActivityTransportBudget(): void
    {
        $this->configureQueue('database');
        $workflow = WorkflowStub::make(TestFractionalRetryWorkflow::class);
        $workflow->start();
        $this->runNextJob();
        $this->app->offsetUnset(TestFractionalRetryActivity::class);
        $this->app->bind(TestFractionalRetryActivity::class, static function (): never {
            throw new RuntimeException('Activity container resolution failed.');
        });
        $this->runNextJob();

        $this->assertCount(1, $this->failures);
        $this->assertSame('Activity container resolution failed.', $this->failures[0]->exception->getMessage());
        $this->assertSame(1, $this->failures[0]->job->attempts());
        $this->assertSame(0, TestFractionalRetryActivity::$calls);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function configureQueue(string $driver): void
    {
        config([
            'workflows.v2.connection' => 'delivery-test',
            'workflows.v2.queue' => 'delayed-tasks',
            'queue.default' => 'delivery-test',
            'queue.connections.delivery-test' => $driver === 'database' ? [
                'driver' => 'database',
                'connection' => config('database.default'),
                'table' => 'jobs',
                'queue' => 'delayed-tasks',
                'retry_after' => 60,
            ] : [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'delayed-tasks',
                'retry_after' => 60,
                'block_for' => null,
            ],
        ]);
    }

    private function runNextJob(): void
    {
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');
        $worker->runNextJob('delivery-test', 'delayed-tasks', new WorkerOptions(sleep: 0));
    }
}
