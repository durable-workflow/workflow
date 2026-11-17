<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriodStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/** Source-native fixture: explicit task driver and independent provider SQL; no installed-worker claim. */
trait BillingPeriodMachineFixture
{
    private BillingPeriodStore $periodStore;

    private BillingPeriod $period;

    private BillingPeriod $nextPeriod;

    private ?Journal $billingProvider = null;

    private ?PDO $billingProviderDb = null;

    private string $billingProviderPath;

    private int $billingStart;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        $this->billingStart = Carbon::now()->getTimestamp();
        config([
            'workflows.v2.namespaces' => [BillingPeriodStore::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['business-state-machines/schema.php', 'provider-state-machines/billing-schema.php'] as $schema) {
            (require __DIR__ . '/../../examples/' . $schema)(DB::connection()->getSchemaBuilder());
        }
        $this->billingProviderPath = tempnam(sys_get_temp_dir(), 'billing-provider-');
        $this->billingProviderDb = new PDO('sqlite:' . $this->billingProviderPath);
        $this->billingProvider = new Journal($this->billingProviderDb, 'shop-a', static fn (): int => Carbon::now()->getTimestamp(), captureEffectTimes: true);
        $this->billingProvider->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach ([
            'provider_billing_future_cancellations',
            'provider_billing_admissions',
            'provider_billing_assessments',
            'provider_billing_periods',
            'provider_billing_subscriptions',
            'example_machine_publications',
            'example_machine_decisions',
            'example_machine_subjects',
        ] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        $path = $this->billingProviderPath;
        unset($this->periodStore);
        $this->billingProvider = null;
        $this->billingProviderDb = null;
        parent::tearDown();
        if (is_file($path)) {
            unlink($path);
        }
    }

    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }

    private function configurePeriod(
        string $policy,
        Status $status = Status::Processing,
        bool $pump = true,
        bool $loseAck = false
    ): WorkflowRun {
        $this->period = $this->terms('2026-01', $this->billingStart, $policy);
        $this->nextPeriod = $this->terms('2026-02', $this->period->endsAt, $policy);
        $this->periodStore = new BillingPeriodStore(DB::connection(), 'period-worker', [
            $this->period,
            $this->nextPeriod,
        ], $this->billingProvider);
        BillingPeriodStore::configure($this->app, $this->periodStore);
        foreach ([$this->period, $this->nextPeriod] as $period) {
            $this->periodStore->seed($period);
            $input = $period->paymentDescriptor();
            $this->billingProvider->plan(Request::fromArray($input['forward']), $status, $loseAck);
            $this->billingProvider->plan(Request::fromArray($input['compensation']), Status::Confirmed);
        }
        $admitted = $this->periodStore->admit($this->period->subjectKey());
        $this->assertTrue($admitted['accepted']);
        if ($pump) {
            $this->pumpBilling();
        }
        return WorkflowRun::query()->findOrFail($admitted['runId']);
    }

    private function terms(string $id, int $start, string $policy): BillingPeriod
    {
        return new BillingPeriod(
            'shop-a',
            'service-a',
            'subscription-g1',
            $id,
            1499,
            'USD',
            $start,
            $start + 2592000,
            BillingPeriod::graceDeadline($start),
            $policy,
            $policy === BillingPeriod::GRACE
        );
    }

    private function confirm(WorkflowRun $run, int $at): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC($at));
        $this->billingProvider->resolve(
            Request::fromArray($this->period->paymentDescriptor()['forward']),
            Status::Confirmed
        );
        WorkflowStub::loadRun($run->id)->withRequestId('payment-confirmed-' . $this->period->subjectKey())->signal(
            'payment-observed'
        );
        $this->pumpBilling();
    }

    private function pumpBilling(?callable $stop = null): void
    {
        for ($i = 0; $i < 400; ++$i) {
            if ($stop !== null && $stop()) {
                return;
            }
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', BillingPeriodStore::NAMESPACE)
                ->where('status', TaskStatus::Ready)->where(static function ($query): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', Carbon::now()->format('Y-m-d H:i:s.u'));
                })->orderBy('created_at')
                ->orderBy('id')
                ->first();
            if ($task === null) {
                return;
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded billing task driver did not settle: ' . $this->billingProgress());
    }

    private function billingProgress(): string
    {
        return json_encode([
            'runs' => WorkflowRun::query()->get(['id', 'workflow_class', 'status'])->toArray(),
            'failures' => WorkflowFailure::query()->orderByDesc('created_at')->limit(5)->pluck('message')->all(),
            'tasks' => WorkflowTask::query()->get(['task_type', 'status', 'available_at'])->toArray(),
        ], JSON_THROW_ON_ERROR);
    }
}
