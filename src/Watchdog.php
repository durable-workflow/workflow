<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowPendingStatus;

class Watchdog implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 0;

    public $timeout = 0;

    public static function kick(): void
    {
        $timeout = (int) config('workflows.watchdog_timeout', 300);

        if (Cache::add('workflow:watchdog', true, $timeout)) {
            static::dispatch()->delay($timeout);
        }
    }

    public function handle(): void
    {
        $timeout = (int) config('workflows.watchdog_timeout', 300);

        Cache::put('workflow:watchdog', true, $timeout);

        $model = config('workflows.stored_workflow_model', StoredWorkflow::class);

        $model::where('status', WorkflowPendingStatus::$name)
            ->where('updated_at', '<=', Carbon::now()->subSeconds($timeout))
            ->whereNotNull('arguments')
            ->each(static function (StoredWorkflow $storedWorkflow): void {
                $storedWorkflow->refresh();

                if ($storedWorkflow->status::class !== WorkflowPendingStatus::class) {
                    return;
                }

                $storedWorkflow->touch();

                Cache::lock('laravel_unique_job:' . $storedWorkflow->class . $storedWorkflow->id)
                    ->forceRelease();

                $storedWorkflow->class::dispatch($storedWorkflow, ...$storedWorkflow->workflowArguments());
            });

        $this->release($timeout);
    }
}
