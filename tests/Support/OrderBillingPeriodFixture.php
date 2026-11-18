<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderBillingPeriodStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/** Genuine Order-per-period association with the provider's original effect-time capability. */
trait OrderBillingPeriodFixture
{
    use CommonFundingMachineFixture { tearDown as private tearDownFunding; }

    private BillingPeriod $period;

    private BillingPeriod $nextPeriod;

    private OrderBillingPeriodStore $periodStore;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => [Catalogue::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['business-state-machines/schema.php', 'provider-state-machines/schema.php',
            'provider-state-machines/billing-schema.php'] as $schema) {
            (require __DIR__ . '/../../examples/' . $schema)(DB::connection()->getSchemaBuilder());
        }
        $this->providerPath = tempnam(sys_get_temp_dir(), 'order-period-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal(
            $this->providerDb,
            'shop-a',
            static fn (): int => Carbon::now()->getTimestamp(),
            captureEffectTimes: true,
        );
        $this->provider->install();
        $this->provider->shipments()
            ->install();
    }

    protected function tearDown(): void
    {
        foreach (['provider_billing_future_cancellations', 'provider_billing_admissions',
            'provider_billing_assessments', 'provider_billing_periods', 'provider_billing_subscriptions'] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        $this->tearDownFunding();
    }

    private function startPeriodOrder(
        string $policy,
        Status $payment = Status::Confirmed,
        bool $pump = true
    ): WorkflowStub {
        $start = Carbon::now()->getTimestamp();
        $this->period = $this->orderPeriod('2026-01', $start, $policy);
        $this->nextPeriod = $this->orderPeriod('2026-02', $this->period->endsAt, $policy);
        $orders = [$this->period->order, $this->nextPeriod->order];
        $keys = array_map(static fn (OrderTerms $order): string => $order->subjectKey(), $orders);
        $authority = new Authority(
            DB::connection(),
            'provider-worker',
            $keys,
            array_fill_keys($keys, FundingCatalogue::IMMEDIATE),
            orderPeriods: [$this->period, $this->nextPeriod],
        );
        $this->store = MachineStore::configure($this->app, $authority, $this->provider);
        $this->periodStore = $this->app->make(OrderBillingPeriodStore::class);
        foreach ($orders as $terms) {
            $this->store->seed($terms);
            foreach ([
                $terms->descriptor('order'),
                $terms->descriptor('payment'),
                $terms->physicalDescriptor(),
            ] as $graph) {
                foreach ($graph as $value) {
                    $request = Request::fromArray($value);
                    $this->provider->plan($request, $request->action === 'capture' ? $payment : Status::Confirmed);
                }
            }
        }
        $this->terms = $this->period->order;
        $result = $this->periodStore->admit($this->period->subjectKey());
        $root = WorkflowStub::loadRun($result['runId'], Catalogue::NAMESPACE);
        if ($pump) {
            $this->pump();
        }
        return $root;
    }

    private function orderPeriod(string $id, int $starts, string $policy): BillingPeriod
    {
        $args = [
            'client' => 'shop-a',
            'subscription' => 'subscription-orders',
            'generation' => 'subscription-g1',
            'period' => $id,
            'amountMinor' => 1499,
            'currency' => 'USD',
            'startsAt' => $starts,
            'endsAt' => $starts + 2592000,
            'paymentDeadline' => BillingPeriod::graceDeadline($starts),
            'activationPolicy' => $policy,
            'accessDuringGrace' => $policy === BillingPeriod::GRACE,
        ];
        $period = new BillingPeriod(...$args);
        $order = new OrderTerms(
            $period->client,
            'order-' . $id,
            $period->ownerGeneration(),
            $period->amountMinor,
            $period->currency,
            'sku-1',
            2,
            fulfillmentDeadline: $period->endsAt + 604800,
        );
        return new BillingPeriod(...[
            ...$args,
            'order' => $order,
        ]);
    }
}
