<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\FundingPayment;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderBillingPeriodStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use DurableWorkflow\Examples\ProviderStateMachines\ServicePeriodPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommonFundingMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Genuine native Order association; this does not qualify an installed worker or the full customer matrix. */
final class V2OrderBillingPeriodTest extends TestCase
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
            (require __DIR__ . '/../../../examples/' . $schema)(DB::connection()->getSchemaBuilder());
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

    public static function policies(): array
    {
        return [[BillingPeriod::PAYMENT_FIRST], [BillingPeriod::GRACE]];
    }

    #[DataProvider('policies')]
    public function testPaidPeriodUsesItsGenuineCommonOrderPaymentAndShipment(string $policy): void
    {
        $root = $this->startPeriodOrder($policy);
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $access = $this->periodStore->access($this->period->subjectKey());
        $this->assertTrue($access['access']);
        $this->assertTrue($access['paidOnTime']);
        $this->assertSame($this->terms->subjectKey(), $access['orderKey']);
        $this->assertSame($root->runId(), $access['originalRunId']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingCheckout::class)->count());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingPayment::class)->count());
        $this->assertSame(0, WorkflowRun::query()->where('workflow_class', ServicePeriodPayment::class)->count());
        $this->assertSame($this->terms->descriptor('payment'), $this->period->paymentDescriptor());
        $before = $this->periodStore->admit($this->period->subjectKey());
        $this->assertTrue($before['existing']);
        $this->assertSame($root->runId(), $before['runId']);
        // Original paid money outlives its payment window. Physical fulfillment
        // keeps its separate immutable budget rather than rewinding that window.
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline + 60));
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $root->withRequestId('fulfill-original-period')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        $this->recordPhysical('delivered');
        $this->assertSame('fulfilled', $this->state('order'), $this->progress());
        $charge = Request::fromArray($this->terms->descriptor('payment')['forward']);
        $this->assertSame(1, $this->providerDb->query(
            "SELECT COUNT(*) FROM provider_effects WHERE operation_id = '" . $charge->identity->key() . "'"
        )->fetchColumn());
    }

    #[DataProvider('policies')]
    public function testGraceAccessExpiresWithoutTurningAnUnpaidOrderIntoFulfillment(string $policy): void
    {
        $this->startPeriodOrder($policy, Status::Processing);
        $access = $this->periodStore->access($this->period->subjectKey());
        $this->assertSame($policy === BillingPeriod::GRACE, $access['access']);
        $this->assertFalse($access['paidOnTime']);
        $this->assertSame(0, DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), 'shipment')
        )->count());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline));
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame([], $this->effects());
    }

    #[DataProvider('policies')]
    public function testFutureCancellationKeepsThePaidOriginalOrderAndRejectsTheNextPeriod(string $policy): void
    {
        $this->startPeriodOrder($policy);
        $this->periodStore->cancelFuture(
            $this->period->subjectKey(),
            'stop-next-order',
            $this->nextPeriod->startsAt,
        );
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertNotContains('refund', $this->effects());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
        $this->assertFalse($this->periodStore->admit($this->nextPeriod->subjectKey())['accepted']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', FundingCheckout::class)->count());
    }

    public function testReplacementWorkerCannotForgetTheOriginalPeriodPolicy(): void
    {
        $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST);
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()], [
            $this->terms->subjectKey() => FundingCatalogue::IMMEDIATE,
        ]);
        DB::table('provider_machine_assignments')->where('id', $this->terms->subjectKey())
            ->update([
                'terms' => '{invalid protected JSON',
            ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original subject authority or selected policy is unavailable.');
        $authority->assignment($this->terms->subjectKey());
    }

    public function testPaidAccessRejectsASubstitutedOriginalPaymentPublication(): void
    {
        $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST);
        $rows = DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), 'payment')
        )->get();
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($payload['state'] === 'paid') {
                $payload['execution']['executionId'] = 'another-period.confirm-receipt.3';
                DB::table('example_machine_publications')->where('id', $row->id)
                    ->update([
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ]);
            }
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original Order Payment publication is missing or substituted.');
        $this->periodStore->access($this->period->subjectKey());
    }

    #[DataProvider('policies')]
    public function testAnOnTimeCommitAcknowledgedAfterGraceKeepsTheOriginalOrder(string $policy): void
    {
        $root = $this->startPeriodOrder($policy, Status::Processing);
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline - 1));
        $this->provider->resolve($charge, Status::Confirmed);
        // Rebuild the host's policy/handlers while no native task is executing.
        $this->store = MachineStore::configure($this->app, $this->store->authority, $this->provider);
        $this->periodStore = $this->app->make(OrderBillingPeriodStore::class);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline + 60));
        $this->signalPayment('delayed-original-ack');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertTrue($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame($this->period->paymentDeadline - 1, $this->provider->timedLookup($charge)->confirmedAt);
        $before = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($before, $this->effects());
        $this->assertSame($root->runId(), $this->periodStore->admit($this->period->subjectKey())['runId']);
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $effect): bool => $effect === 'capture'))
        );
    }

    #[DataProvider('policies')]
    public function testLateOriginalMoneyDoesNotActivateAccessOrReserveStock(string $policy): void
    {
        $this->startPeriodOrder($policy, Status::Processing);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline));
        $charge = Request::fromArray($this->period->paymentDescriptor()['forward']);
        $this->provider->resolve($charge, Status::Confirmed);
        $this->signalPayment('late-original-money');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $value = $this->periodStore->access($this->period->subjectKey());
        $this->assertTrue($value['latePayment']);
        $this->assertFalse($value['access']);
        $this->assertNotContains('reserve', $this->effects());
        $this->assertNotContains('ship-book', $this->effects());
        $this->assertSame(['capture'], $this->effects());
    }

    public function testAQueuedOriginalOrderCannotFirstChargeAfterItsPaymentDeadline(): void
    {
        $this->startPeriodOrder(BillingPeriod::GRACE, pump: false);
        $this->assertSame([], $this->effects());
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->period->paymentDeadline));
        $this->pump();
        $this->assertSame([], $this->effects());
        $this->assertSame(0, (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertNotSame('paid', $this->state('payment'), $this->progress());
    }

    public function testCancellationBeforeOriginalInitializationDoesNotInventAnEmptyScope(): void
    {
        $root = $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST, pump: false);
        $result = $this->periodStore->cancel($this->period->subjectKey(), 'cancel-before-period-owner');
        $this->assertSame('unresolved', $result['status']);
        $this->assertSame('original_scope_not_initialized', $result['reason']);
        $this->assertSame([], $this->effects());
        $this->pump();
        $proof = (new ScopeRepository())->inspect(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertSame('verified', $proof['status'], $this->progress());
        $this->assertSame([], $this->effects());
        $this->assertFalse($this->periodStore->access($this->period->subjectKey())['access']);
        $this->assertSame($root->runId(), $this->periodStore->admit($this->period->subjectKey())['runId']);
    }

    public function testRecoveryOfThePreviousPeriodCannotRefundTheNextOrdersMoney(): void
    {
        $first = $this->startPeriodOrder(BillingPeriod::PAYMENT_FIRST);
        $originalTerms = $this->terms;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->nextPeriod->startsAt));
        $next = $this->periodStore->admit($this->nextPeriod->subjectKey());
        $this->pump();
        $this->assertNotSame($first->runId(), $next['runId']);
        $this->assertTrue($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->periodStore->cancel($this->period->subjectKey(), 'original-order-period-only');
        $this->pump();
        $original = $this->store->authority->plan($originalTerms->subjectKey());
        $this->assertSame(
            'verified',
            (new ScopeRepository())->inspect($original->rootScope())['status'],
            $this->progress()
        );
        $this->assertTrue($this->periodStore->access($this->nextPeriod->subjectKey())['access']);
        $this->assertSame(
            'paid',
            $this->store->authority->subject($this->nextPeriod->order->subjectKey(), 'payment')
->state
        );
        $nextCharge = Request::fromArray($this->nextPeriod->paymentDescriptor()['forward']);
        $this->assertFalse($this->provider->timedLookup($nextCharge)->observation->admissionClosed);
        $this->assertSame(
            2,
            count(array_filter($this->effects(), static fn (string $effect): bool => $effect === 'capture'))
        );
        $this->assertSame(
            1,
            count(array_filter($this->effects(), static fn (string $effect): bool => $effect === 'refund'))
        );
    }

    private function signalPayment(string $request): void
    {
        WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)
            ->withRequestId($request)
            ->signal('reconcile-funding');
        $this->pump();
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
