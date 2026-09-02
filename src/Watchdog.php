<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowPendingStatus;

class Watchdog implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const DEFAULT_TIMEOUT = 300;

    private const CACHE_KEY = 'workflow:watchdog';

    private const CHAIN_LOCK_KEY = 'workflow:watchdog:chain';

    private const LOOP_THROTTLE_KEY = 'workflow:watchdog:looping';

    private const RECOVERY_LOCK_PREFIX = 'workflow:watchdog:recovering:';

    public int $tries = 3;

    public int $maxExceptions = 3;

    public $timeout = 0;

    public ?string $generation = null;

    public function __construct(?string $generation = null)
    {
        $this->generation = $generation;
    }

    public static function wake(string $connection, ?string $queue = null): void
    {
        if (! self::storedWorkflowTableExists()) {
            return;
        }

        $timeout = self::timeout();

        $queue = self::normalizeQueue($queue);

        DB::afterCommit(static function () use ($connection, $queue, $timeout): void {
            $generation = self::newGeneration();

            $watchdog = Cache::lock(self::CHAIN_LOCK_KEY, self::leaseDuration($timeout))
                ->get(static function () use ($connection, $queue, $timeout, $generation): ?self {
                    if (! self::storedWorkflowTableExists() || Cache::has(self::CACHE_KEY)) {
                        return null;
                    }

                    if (! Cache::add(self::LOOP_THROTTLE_KEY, $generation, self::bootstrapWindow($timeout))) {
                        return null;
                    }

                    if (! self::hasRecoverablePendingWorkflows($timeout) || Cache::has(self::CACHE_KEY)) {
                        return null;
                    }

                    Cache::put(self::CACHE_KEY, $generation, self::leaseDuration($timeout));

                    return self::make($generation, $connection, $queue);
                });

            if ($watchdog instanceof self) {
                self::dispatch($watchdog, $generation, $timeout);
            }
        });
    }

    public function handle(): void
    {
        if (! self::storedWorkflowTableExists()) {
            return;
        }

        $timeout = self::timeout();

        $nextWatchdog = Cache::lock(self::CHAIN_LOCK_KEY, self::leaseDuration($timeout))
            ->block(self::bootstrapWindow($timeout), function () use ($timeout): ?self {
                if (! $this->claim($timeout)) {
                    return null;
                }

                $model = config('workflows.stored_workflow_model', StoredWorkflow::class);

                $model::where('status', WorkflowPendingStatus::$name)
                    ->where('updated_at', '<=', Carbon::now()->subSeconds($timeout))
                    ->whereNotNull('arguments')
                    ->each(static function (StoredWorkflow $storedWorkflow) use ($timeout): void {
                        self::recover($storedWorkflow, $timeout);
                    });

                if ($this->job === null || $this->job instanceof SyncJob) {
                    return null;
                }

                if ($this->generation === null || Cache::get(self::CACHE_KEY) !== $this->generation) {
                    return null;
                }

                $nextGeneration = self::newGeneration();

                Cache::put(self::CACHE_KEY, $nextGeneration, self::leaseDuration($timeout));
                Cache::put(self::LOOP_THROTTLE_KEY, $nextGeneration, self::bootstrapWindow($timeout));

                return self::make($nextGeneration, $this->connection, $this->queue)
                    ->delay($timeout);
            });

        if ($nextWatchdog instanceof self && $nextWatchdog->generation !== null) {
            self::dispatch($nextWatchdog, $nextWatchdog->generation, $timeout);
        }
    }

    private function claim(int $timeout): bool
    {
        $generation = $this->generation;

        if ($this->job !== null && $generation === null) {
            return false;
        }

        if ($generation === null) {
            $generation = self::newGeneration();
            $this->generation = $generation;
        }

        $marker = Cache::get(self::CACHE_KEY);

        if ($marker !== null && $marker !== $generation) {
            return false;
        }

        Cache::put(self::CACHE_KEY, $generation, self::leaseDuration($timeout));

        return true;
    }

    private static function dispatch(self $watchdog, string $generation, int $timeout): void
    {
        try {
            app(Dispatcher::class)->dispatch($watchdog);
        } catch (\Throwable $exception) {
            self::releaseOwnership($generation, $timeout);

            throw $exception;
        }
    }

    private static function releaseOwnership(string $generation, int $timeout): void
    {
        Cache::lock(self::CHAIN_LOCK_KEY, self::leaseDuration($timeout))
            ->block(self::leaseDuration($timeout) + 1, static function () use ($generation): void {
                if (Cache::get(self::CACHE_KEY) !== $generation) {
                    return;
                }

                Cache::forget(self::CACHE_KEY);

                if (Cache::get(self::LOOP_THROTTLE_KEY) === $generation) {
                    Cache::forget(self::LOOP_THROTTLE_KEY);
                }
            });
    }

    private static function make(string $generation, ?string $connection, ?string $queue): self
    {
        $watchdog = new self($generation);

        if ($connection !== null) {
            $watchdog->onConnection($connection);
        }

        if ($queue !== null) {
            $watchdog->onQueue($queue);
        }

        return $watchdog;
    }

    private static function newGeneration(): string
    {
        return (string) Str::uuid();
    }

    private static function recover(StoredWorkflow $storedWorkflow, int $timeout): bool
    {
        $claimTtl = self::bootstrapWindow($timeout);

        return (bool) (Cache::lock(self::RECOVERY_LOCK_PREFIX . $storedWorkflow->id, $claimTtl)
            ->get(static function () use ($storedWorkflow): bool {
                $storedWorkflow->refresh();

                if ($storedWorkflow->status::class !== WorkflowPendingStatus::class) {
                    return false;
                }

                $workflowStub = $storedWorkflow->toWorkflow();
                $workflowClass = $storedWorkflow->class;
                $workflowJob = new $workflowClass($storedWorkflow, ...$storedWorkflow->workflowArguments());

                $storedWorkflow->touch();

                (new UniqueLock(Cache::driver()))->release($workflowJob);

                $workflowStub->resume();

                return true;
            }) ?? false);
    }

    private static function timeout(): int
    {
        return self::DEFAULT_TIMEOUT;
    }

    private static function hasRecoverablePendingWorkflows(int $timeout): bool
    {
        if (! self::storedWorkflowTableExists()) {
            return false;
        }

        $model = config('workflows.stored_workflow_model', StoredWorkflow::class);

        return $model::where('status', WorkflowPendingStatus::$name)
            ->where('updated_at', '<=', Carbon::now()->subSeconds($timeout))
            ->whereNotNull('arguments')
            ->exists();
    }

    private static function bootstrapWindow(int $timeout): int
    {
        return max(1, min($timeout, 60));
    }

    private static function leaseDuration(int $timeout): int
    {
        return $timeout + self::bootstrapWindow($timeout);
    }

    private static function normalizeQueue(?string $queue): ?string
    {
        if ($queue === null) {
            return null;
        }

        foreach (explode(',', $queue) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private static function storedWorkflowTableExists(): bool
    {
        try {
            $model = config('workflows.stored_workflow_model', StoredWorkflow::class);

            if (! is_string($model) || ! is_a($model, StoredWorkflow::class, true)) {
                $model = StoredWorkflow::class;
            }

            return Schema::hasTable((new $model())->getTable());
        } catch (\Throwable) {
            return false;
        }
    }
}
