<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\MachineStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../examples/configured-state-machines/bootstrap.php';

/**
 * A fresh worker for the recorded Order/Shipment example; see WorkerLossDriver for the crash and resume phases.
 * The crash phase dies after the Shipment's deliver effect commits. The resume phase runs until the original
 * root scope verifies or native recovery goes idle.
 */
final class ConfiguredChildRaceProcessLossProcessTest extends TestCase
{
    use WorkerLossDriver;

    public function testRunTheOriginalConfiguredWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        MachineStore::configure($this->app, DB::connection(), $input['principal'], [$input['subject']]);
        if ($input['phase'] === 'crash') {
            $this->armWorkerLoss('configured.sql.deliver', 'forward', $input['report']);
        } else {
            $this->startReplacementWorker();
        }
        $scope = Ownership::scope($input['subject'], $input['rootRun']);
        for ($step = 0; $step < 800; ++$step) {
            if ($input['phase'] === 'resume') {
                $status = (new ScopeRepository())->metadata($scope)['status'];
                $idle = $status !== 'verified' && $this->workerIdle(Ownership::NAMESPACE);
                if ($status === 'verified' || $idle) {
                    file_put_contents($input['report'], json_encode([
                        'pid' => getmypid(),
                        'scope' => $status,
                        'idle' => $idle,
                        'clock' => now()
                            ->toISOString(),
                    ], JSON_THROW_ON_ERROR));
                    return;
                }
            }
            $this->runNextTask(Ownership::NAMESPACE);
        }
        $this->fail('The bounded configured worker did not reach its selected cut or outcome.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->workerLossEnvironment($app, $this->input(), Ownership::NAMESPACE);
    }

    private function input(): array
    {
        return $this->workerLossInput('DWF_CONFIGURED_CHILD_RACE_INPUT');
    }
}
