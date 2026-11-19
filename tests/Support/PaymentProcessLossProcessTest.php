<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationFinalizer;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Orchestra\Testbench\TestCase;
use PDO;
use Workflow\Providers\WorkflowServiceProvider;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/**
 * A fresh worker for one original authorized, cash-on-delivery or advance-and-balance Order; see
 * WorkerLossDriver for the crash and resume phases. The replacement runs only the work due now: later
 * business deadlines stay pending for the parent to assert.
 */
final class PaymentProcessLossProcessTest extends TestCase
{
    use WorkerLossDriver;

    public function testRunTheOriginalPaymentWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $this->configure($input);
        if ($input['phase'] === 'crash') {
            $this->armWorkerLoss($input['handler'], $input['mode'], $input['report']);
        } else {
            $this->startReplacementWorker();
        }
        for ($step = 0; $step < 800; ++$step) {
            if ($input['phase'] === 'resume' && $this->workerCurrent(Catalogue::NAMESPACE)) {
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            $this->runNextTask(Catalogue::NAMESPACE);
        }
        $this->fail('The bounded original payment worker did not reach its selected cut or current work.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->workerLossEnvironment($app, $this->input(), Catalogue::NAMESPACE);
    }

    private function configure(array $input): void
    {
        $db = new PDO('sqlite:' . $input['provider']);
        $clock = static fn (): int => Carbon::now()->getTimestamp();
        $authority = new Authority(DB::connection(), 'provider-worker', [$input['key']], [
            $input['key'] => $input['recipe'],
        ]);
        if ($input['profile'] === 'authorized') {
            MachineStore::configure($this->app, $authority, new Journal($db, 'shop-a', $clock));
        } else {
            $mixed = match ($input['profile']) {
                'cod' => null,
                'advance-balance' => new AdvanceBalanceProvider($db, 'shop-a', $clock),
                default => throw new LogicException('The original payment worker profile is unavailable.'),
            };
            $cod = $mixed?->cod ?? new CodPaymentProvider($db, 'shop-a', $clock, true);
            MachineStore::configure($this->app, $authority, $cod->commands, $mixed?->incoming, $cod);
        }
        // The worker application finalizes verified cancellations itself (recommended pattern).
        CancellationFinalizer::register(app('events'), $authority, app(CancellationStore::class));
    }

    private function input(): array
    {
        return $this->workerLossInput('DWF_PAYMENT_PROCESS_INPUT');
    }
}
