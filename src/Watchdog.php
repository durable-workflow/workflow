<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowPendingStatus;

class Watchdog implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const DEFAULT_TIMEOUT = 300;

    private const CACHE_KEY = 'workflow:watchdog';

    private const LOOP_THROTTLE_KEY = 'workflow:watchdog:looping';

    private const RECOVERY_LOCK_PREFIX = 'workflow:watchdog:recovering:';

    public int $tries = 0;

    public int $maxExceptions = 0;

    public $timeout = 0;

    public static function wake(string $connection, ?string $queue = null): void
    {
        $timeout = self::timeout();

        if (Cache::has(self::CACHE_KEY)) {
            return;
        }

        if (! Cache::add(self::LOOP_THROTTLE_KEY, true, 60)) {
            return;
        }

        if (! self::hasRecoverablePendingWorkflows($timeout)) {
            return;
        }

        $queue = self::normalizeQueue($queue);

        DB::afterCommit(static function () use ($connection, $queue, $timeout): void {
            if (! Cache::add(self::CACHE_KEY, true, $timeout)) {
                return;
            }

            $watchdog = (new self())
                ->onConnection($connection);

            if ($queue !== null) {
                $watchdog->onQueue($queue);
            }

            try {
                app(Dispatcher::class)->dispatch($watchdog);
            } catch (\Throwable $exception) {
                Cache::forget(self::CACHE_KEY);
                Cache::forget(self::LOOP_THROTTLE_KEY);

                throw $exception;
            }
        });
    }

    public function handle(): void
    {
        $timeout = self::timeout();

        Cache::put(self::CACHE_KEY, true, $timeout);

        $model = config('workflows.stored_workflow_model', StoredWorkflow::class);

        $model::where('status', WorkflowPendingStatus::$name)
            ->where('updated_at', '<=', Carbon::now()->subSeconds($timeout))
            ->whereNotNull('arguments')
            ->each(static function (StoredWorkflow $storedWorkflow) use ($timeout): void {
                self::recover($storedWorkflow, $timeout);
            });

        if ($this->job !== null) {
            $this->release($timeout);
        }
    }

    private static function recover(StoredWorkflow $storedWorkflow, int $timeout): bool
    {
        $claimTtl = self::bootstrapWindow($timeout);
        $workflowStub = $storedWorkflow->toWorkflow();
        $workflowJob = new $storedWorkflow->class($storedWorkflow, ...$storedWorkflow->workflowArguments());

        return (bool) (Cache::lock(self::RECOVERY_LOCK_PREFIX . $storedWorkflow->id, $claimTtl)
            ->get(static function () use ($storedWorkflow, $workflowJob, $workflowStub): bool {
                $storedWorkflow->refresh();

                if ($storedWorkflow->status::class !== WorkflowPendingStatus::class) {
                    return false;
                }

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
}
