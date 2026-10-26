<?php

declare(strict_types=1);

namespace Workflow\V2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallScheduler;

final class RunServiceCall implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $serviceCallId,
    ) {
        $this->afterCommit();
    }

    public function handle(ServiceControlPlane $controlPlane): void
    {
        if (! $controlPlane instanceof DefaultServiceControlPlane) {
            throw new RuntimeException('scheduled_service_call_executor_unavailable');
        }
        $claim = ServiceCallScheduler::claim($this->serviceCallId);

        if ($claim !== null) {
            $controlPlane->executeScheduledAttempt($claim);
        }
    }
}
