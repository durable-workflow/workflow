<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PDO;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/**
 * A fresh worker for one transit incident; see WorkerLossDriver for the crash and resume phases. A resume
 * phase stops once the cut operation is recovered, or when the incident ends or waits.
 */
final class TransitIncidentProcessLossProcessTest extends TestCase
{
    use WorkerLossDriver;

    public function testRunTheOriginalIncidentWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $this->configure($input);
        if ($input['phase'] === 'crash') {
            $this->armWorkerLoss($input['handler'], 'forward', $input['report']);
        } else {
            $this->startReplacementWorker();
        }
        for ($step = 0; $step < 800; ++$step) {
            if ($input['phase'] === 'resume' && ($this->recovered($input) || $this->workerIdle(Catalogue::NAMESPACE))) {
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'status' => $this->incidentRun($input)
                        ->status->value,
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            $this->retryWhenOnlyTheOrderIsReoffered();
            $this->runNextTask(Catalogue::NAMESPACE);
        }
        $this->fail('The bounded original incident worker did not reach its selected cut or outcome.');
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
            $key => FinancialCatalogue::AFTER_STOCK,
        ], unitAmounts: [
            $key => $input['unitAmounts'],
        ], transitPolicies: [
            $key => $input['policy'],
        ], transitWakes: $input['wakes']);
        return MachineStore::configure(
            $this->app,
            $authority,
            $provider,
            unitRefunds: new UnitRefundGateway($db, 'shop-a')
        );
    }

    /**
     * After its Shipment child closed, the Order's remedy wait is re-offered by every watchdog pass (#1219),
     * so due work never runs out. Once every lease is repaired, move on to the next activity retry.
     */
    private function retryWhenOnlyTheOrderIsReoffered(): void
    {
        $open = WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
            ->whereIn('status', [TaskStatus::Ready, TaskStatus::Leased])->get();
        $retry = $open->where('task_type', TaskType::Activity)->sortBy('available_at')->first();
        if ($retry !== null && $open->every(static fn (WorkflowTask $task): bool => $task->status === TaskStatus::Ready
            && $task->available_at?->isFuture())) {
            Carbon::setTestNow($retry->available_at->copy()->addSecond());
        }
    }

    private function recovered(array $input): bool
    {
        if ($this->incidentRun($input)->status->isTerminal()) {
            return true;
        }
        $ledger = (new ScopeRepository())->inspect(ScopeIdentity::fromArray($input['scope']));
        foreach ($ledger['obligations'] as $row) {
            if ($row['id'] === $input['operationId']) {
                return $row['forward_status'] === 'observed';
            }
        }
        return false;
    }

    private function incidentRun(array $input): WorkflowRun
    {
        $row = DB::table(IncidentStore::TABLE)->where('id', $input['key'])->sole();
        return WorkflowRun::query()->findOrFail($row->native_run);
    }

    private function input(): array
    {
        return $this->workerLossInput('DWF_INCIDENT_PROCESS_INPUT');
    }
}
