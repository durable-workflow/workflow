<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\RecordFeatureWorkerState;
use Tests\Fixtures\TestAwaitWorkflow;
use Tests\Fixtures\TestSimpleWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Watchdog;
use Workflow\WorkflowStub;

final class WorkflowPollingTest extends TestCase
{
    public function testIsolatedFeatureWorkersCannotScheduleWatchdog(): void
    {
        Cache::forget('workflow:watchdog');
        Cache::forget('workflow:watchdog:looping');

        $workflow = WorkflowStub::make(TestSimpleWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->forceFill([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
            'updated_at' => now()
                ->subSeconds(Watchdog::DEFAULT_TIMEOUT + 1),
        ])->save();

        $marker = 'workflow:test:isolated-worker:' . $workflow->id();

        RecordFeatureWorkerState::dispatch($marker)
            ->onConnection('redis')
            ->onQueue('default');

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => is_array(Cache::get($marker)),
            'an isolated feature worker to report its configuration',
            10.0,
        );

        $workerState = Cache::get($marker);

        $this->assertIsArray($workerState);
        $this->assertNotSame(getmypid(), $workerState['pid']);
        $this->assertFalse($workerState['watchdog_enabled']);
        $this->assertFalse(
            Cache::has('workflow:watchdog'),
            'The isolated feature worker scheduled Watchdog while processing a stale pending workflow.',
        );
    }

    public function testNonProgressingWorkflowFailsQuicklyWithDiagnostics(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);
        $startedAt = hrtime(true);

        try {
            $this->waitForWorkflow($workflow, timeoutSeconds: 0.05);

            $this->fail('The non-progressing workflow unexpectedly reached a terminal state.');
        } catch (AssertionFailedError $failure) {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

            $this->assertLessThan(1.0, $elapsedSeconds);
            $this->assertStringContainsString(
                'waiting for workflow ' . $workflow->id() . ' to reach a terminal state',
                $failure->getMessage(),
            );
            $this->assertStringContainsString('status=' . WorkflowCreatedStatus::class, $failure->getMessage());
            $this->assertStringContainsString('logs=0', $failure->getMessage());
            $this->assertStringContainsString('exceptions=0', $failure->getMessage());
        }
    }
}
