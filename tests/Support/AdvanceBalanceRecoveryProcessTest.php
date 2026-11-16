<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceProvider;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
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
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/** Fresh application and provider connections; no migration, seed or inherited parent container. */
final class AdvanceBalanceRecoveryProcessTest extends TestCase
{
    public function testResumeOriginalAdvanceCompensation(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $provider = new AdvanceBalanceProvider(
            new PDO('sqlite:' . $input['provider']),
            'shop-a',
            static fn (): int => now()
                ->getTimestamp()
        );
        $authority = new Authority(DB::connection(), 'provider-worker', [$input['key']], [
            $input['key'] => $input['recipe'],
        ]);
        $store = MachineStore::configure(
            $this->app,
            $authority,
            $provider->commands,
            $provider->incoming,
            $provider->cod
        );
        $root = ScopeIdentity::fromArray($input['rootScope']);
        $payment = ScopeIdentity::fromArray($input['paymentScope']);
        $scopes = new ScopeRepository();
        $finalizing = false;
        for ($step = 0; $step < 800; ++$step) {
            $ledger = $scopes->inspect($payment);
            $bankVerified = false;
            foreach ($ledger['obligations'] as $obligation) {
                if ($obligation['descriptor']['key'] === 'payment.incoming') {
                    $bankVerified = $obligation['compensation_status'] === 'verified';
                }
            }
            if ($input['phase'] === 'crash' && $bankVerified) {
                $this->report($input, $ledger['status']);
                posix_kill(getmypid(), SIGKILL);
                $this->fail('The selected recovery worker did not stop after the durable bank confirmation.');
            }
            if ($input['phase'] === 'resume' && $scopes->metadata($root)['status'] === 'verified' && ! $finalizing) {
                app(CancellationStore::class)->finish($input['key'], $input['rootRun'], $input['request']);
                $finalizing = true;
            }
            if ($finalizing && $store->authority->subject($input['key'], 'order')->state === 'cancelled') {
                $this->report($input, $scopes->metadata($root)['status']);
                return;
            }
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
                ->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy('created_at')->orderBy('id')
                ->first();
            $this->assertNotNull($task, 'The original cancellation has no runnable task before its required outcome.');
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded original mixed-payment recovery did not reach its selected cut or settlement.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $app['config']->set([
            'workflows.v2.namespaces' => [Catalogue::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'database.default' => 'provider_process',
            'database.connections.provider_process' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'provider_process',
            'queue.default' => 'redis',
            'cache' => $input['cache'],
            'logging.default' => 'null',
        ]);
    }

    private function report(array $input, string $status): void
    {
        file_put_contents($input['report'], json_encode([
            'pid' => getmypid(),
            'status' => $status,
            'clock' => now()
                ->toISOString(),
        ], JSON_THROW_ON_ERROR));
    }

    private function input(): array
    {
        $path = getenv('DWF_ADVANCE_RECOVERY_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the original mixed-payment recovery control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
