<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriodStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PDO;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/**
 * A fresh worker for one original subscription period, with or without its genuine Order. The trusted
 * bootstrap is rebuilt from the same immutable period grants in every process; see WorkerLossDriver.
 */
final class BillingPeriodProcessLossProcessTest extends TestCase
{
    use WorkerLossDriver;

    public function testRunTheOriginalPeriodWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $reached = $this->configure($input);
        if ($input['phase'] === 'crash') {
            $this->armWorkerLoss($input['handler'], $input['mode'], $input['report'], $input['action'] ?? null);
        } else {
            $this->startReplacementWorker($input['notBefore'] ?? null);
        }
        $namespace = $this->namespace($input);
        for ($step = 0; $step < 800; ++$step) {
            if ($input['phase'] === 'resume' && ($reached() || $this->workerIdle($namespace))) {
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'reached' => $reached(),
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            $this->runNextTask($namespace);
        }
        $this->fail('The bounded original period worker did not reach its selected cut or outcome.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $this->workerLossEnvironment($app, $input, $this->namespace($input));
    }

    /**
     * @return Closure(): bool Whether the replacement worker reached the outcome the parent asserts.
     */
    private function configure(array $input): Closure
    {
        $provider = new Journal(
            new PDO('sqlite:' . $input['provider']),
            'shop-a',
            static fn (): int => Carbon::now()->getTimestamp(),
            captureEffectTimes: true,
        );
        $periods = array_map(BillingPeriod::fromArray(...), $input['periods']);
        if ($input['association'] === 'service') {
            $store = new BillingPeriodStore(DB::connection(), 'period-worker', $periods, $provider);
            BillingPeriodStore::configure($this->app, $store);
            return $input['until'] === 'verified'
                ? static fn (): bool => (new ScopeRepository())->inspect(
                    $store->scope($input['key'])
                )['status'] === 'verified'
                : static fn (): bool => WorkflowRun::query()->findOrFail(
                    $input['runId']
                )->status->value === 'completed';
        }
        $keys = array_map(static fn (BillingPeriod $period): string => $period->order->subjectKey(), $periods);
        $authority = new Authority(
            DB::connection(),
            'provider-worker',
            $keys,
            array_fill_keys($keys, FundingCatalogue::IMMEDIATE),
            orderPeriods: $periods,
        );
        MachineStore::configure($this->app, $authority, $provider);
        return static fn (): bool => $authority->subject($input['orderKey'], 'order')->state === $input['until'];
    }

    private function namespace(array $input): string
    {
        return $input['association'] === 'service' ? BillingPeriodStore::NAMESPACE : Catalogue::NAMESPACE;
    }

    private function input(): array
    {
        return $this->workerLossInput('DWF_BILLING_PROCESS_INPUT');
    }
}
