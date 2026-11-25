<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationFinalizer;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PDO;
use Workflow\Providers\WorkflowServiceProvider;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/**
 * A fresh worker for the common checkout (payment-first unless the input selects a recipe); see WorkerLossDriver for the crash and resume phases.
 */
final class CheckoutProcessLossProcessTest extends TestCase
{
    use WorkerLossDriver;

    public function testRunTheOriginalCheckoutWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $store = $this->configure($input);
        if ($input['phase'] === 'crash') {
            $this->armWorkerLoss($input['handler'], $input['mode'], $input['report']);
        } else {
            $this->startReplacementWorker();
        }
        for ($step = 0; $step < 800; ++$step) {
            $state = $store->authority->subject($input['key'], 'order')->state;
            $idle = $input['phase'] === 'resume' && $this->workerIdle(Catalogue::NAMESPACE);
            if ($input['phase'] === 'resume' && ($state === $input['until'] || $idle)) {
                // Idle means native recovery ended without settlement; the parent asserts which applies.
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'state' => $state,
                    'idle' => $state !== $input['until'],
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            $this->runNextTask(Catalogue::NAMESPACE);
        }
        $this->fail('The bounded original checkout worker did not reach its selected cut or outcome.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->workerLossEnvironment($app, $this->input(), Catalogue::NAMESPACE);
    }

    private function configure(array $input): MachineStore
    {
        $db = new PDO('sqlite:' . $input['provider']);
        $provider = new Journal($db, 'shop-a');
        $key = $input['key'];
        $authority = new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => $input['recipe'] ?? Catalogue::PAYMENT_FIRST,
        ], unitAmounts: [
            $key => $input['unitAmounts'],
        ], stockPolicies: [
            $key => $input['policy'],
        ]);
        $store = MachineStore::configure(
            $this->app,
            $authority,
            $provider,
            unitRefunds: new UnitRefundGateway($db, 'shop-a'),
            warehouse: new StockWarehouse($db)
        );
        // The worker application finalizes verified cancellations itself (recommended pattern).
        CancellationFinalizer::register(app('events'), $authority, app(CancellationStore::class));
        return $store;
    }

    private function input(): array
    {
        return $this->workerLossInput('DWF_CHECKOUT_PROCESS_INPUT');
    }
}
