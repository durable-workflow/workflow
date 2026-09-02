<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\Watchdog;

final class WatchdogDatabaseQueueTest extends TestCase
{
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

        config([
            'queue.connections.watchdog-database' => [
                'driver' => 'database',
                'connection' => config('database.default'),
                'table' => 'jobs',
                'queue' => 'watchdogs',
                'retry_after' => 60,
                'after_commit' => false,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDatabaseQueueUsesFreshAttemptsAcrossMoreThanUnsignedTinyIntegerTicks(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $generation = 'initial-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT + 60);

        $watchdog = (new Watchdog($generation))
            ->onConnection('watchdog-database')
            ->onQueue('watchdogs');

        app(Dispatcher::class)->dispatch($watchdog);

        $queue = $this->databaseQueue();
        $attempts = [];

        for ($tick = 0; $tick < 260; $tick++) {
            /** @var DatabaseJob|null $job */
            $job = $queue->pop('watchdogs');

            $this->assertNotNull($job, "Watchdog tick {$tick} was not available.");
            $attempts[] = $job->attempts();
            $this->assertSame(3, $job->maxTries());
            $this->assertSame(3, $job->maxExceptions());
            $this->assertSame('watchdog-database', $job->getConnectionName());
            $this->assertSame('watchdogs', $job->getQueue());

            $job->fire();

            if (! $job->isDeletedOrReleased()) {
                $job->delete();
            }

            $queuedJob = DB::table('jobs')->sole();
            $this->assertSame('watchdogs', $queuedJob->queue);
            $this->assertSame(0, (int) $queuedJob->attempts);

            Carbon::setTestNow(now()->addSeconds(Watchdog::DEFAULT_TIMEOUT));
        }

        $this->assertSame(1, max($attempts));
        $this->assertCount(260, $attempts);
    }

    public function testPreExistingDatabaseDuplicatesConvergeToOneContinuingChain(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $generation = 'duplicated-generation';
        Cache::put('workflow:watchdog', $generation, Watchdog::DEFAULT_TIMEOUT + 60);

        $watchdog = (new Watchdog($generation))
            ->onConnection('watchdog-database')
            ->onQueue('watchdogs');

        app(Dispatcher::class)->dispatch($watchdog);
        app(Dispatcher::class)->dispatch($watchdog);

        $queue = $this->databaseQueue();

        $this->runNextJob($queue);
        $this->runNextJob($queue);

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, (int) DB::table('jobs')->sole()->attempts);

        Carbon::setTestNow(now()->addSeconds(Watchdog::DEFAULT_TIMEOUT));
        $continuingJob = $this->runNextJob($queue);

        $this->assertSame(1, $continuingJob->attempts());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    private function databaseQueue(): DatabaseQueue
    {
        /** @var DatabaseQueue $queue */
        $queue = app('queue')
            ->connection('watchdog-database');

        return $queue;
    }

    private function runNextJob(DatabaseQueue $queue): DatabaseJob
    {
        /** @var DatabaseJob|null $job */
        $job = $queue->pop('watchdogs');

        $this->assertNotNull($job);
        $job->fire();

        if (! $job->isDeletedOrReleased()) {
            $job->delete();
        }

        return $job;
    }
}
