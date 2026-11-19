<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\WorkflowStub;

/** Each admitted period owns a genuine common-route Order, Payment and physical Shipment. */
final readonly class OrderBillingPeriodStore
{
    private BillingPeriodAssignments $assignments;

    private OrderPeriodPaymentReceipt $receipts;

    public function __construct(
        public Authority $authority,
        private Journal $provider,
    ) {
        $db = $authority->db;
        if (! $db instanceof Connection) {
            throw new LogicException('Order periods require the original configured native SQL connection.');
        }
        $this->assignments = new BillingPeriodAssignments($db, $authority->principal, $authority->periods->grants());
        $provider->requireEffectTimeSupport();
        $this->receipts = new OrderPeriodPaymentReceipt($authority, $provider);
    }

    /**
     * Called inside MachineStore's already-authorized Order seed transaction.
     */
    public function seedRow(OrderTerms $terms): void
    {
        $period = $this->authority->periods->forOrder($terms->subjectKey());
        if ($period === null) {
            return;
        }
        if ($this->authority->db->transactionLevel() !== 1 || WorkflowFiberContext::active()
            || $period->order->toArray() !== $terms->toArray()) {
            throw new LogicException('The period and genuine Order must be seeded in the same owned transaction.');
        }
        $db = $this->authority->db;
        $key = $period->subjectKey();
        $subscription = $this->assignments->subscriptionId($period);
        $db->table('provider_billing_subscriptions')
            ->insertOrIgnore([
                'id' => $subscription,
                'principal' => $this->authority->principal,
                'authority_version' => $this->assignments->subscriptionVersion($period),
            ]);
        $this->assignments->subscription($period);
        $db->table('provider_billing_periods')
            ->insert([
                'id' => $key,
                'subscription_id' => $subscription,
                'principal' => $this->authority->principal,
                'authority_version' => $this->assignments->version($period),
                'assignment' => PortableDescriptor::encode($period->toArray()),
                'assignment_sha256' => $period->fingerprint(),
            ]);
        $db->table('example_machine_subjects')
            ->insert([
                'id' => BillingPeriodAssignments::subjectId($key),
                'scope' => BillingPeriodStore::APPLICATION,
                'subject' => $key,
                'principal' => $this->authority->principal,
                'authority_version' => $this->assignments->version($period),
                'state' => 'received',
                'revision' => 1,
                'cancellation_requested' => false,
            ]);
    }

    public function admit(string $key): array
    {
        $period = $this->period($key);
        $orderKey = $period->order->subjectKey();
        [$assignment] = $this->authority->assignment($orderKey);
        $original = $this->authority->subject($orderKey, 'order');
        return $this->authority->db->transaction(function () use ($key, $period, $assignment, $original): array {
            $subscription = $this->assignments->subscription($period);
            $subject = $this->assignments->subject($key, $period, true);
            $row = $this->authority->db->table('provider_billing_periods')
                ->where('id', $key)
                ->lockForUpdate()
                ->sole();
            $this->assignments->assertAssignment($row, $period);
            $order = $this->authority->db->table('example_machine_subjects')
                ->where('id', $original->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($order, $period->order->subjectKey(), 'order');
            $current = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $assignment->id)
                ->lockForUpdate()
                ->sole();
            if ($current->terms !== $assignment->terms || $current->terms_sha256 !== $assignment->terms_sha256) {
                throw new LogicException('The original Order assignment changed during period admission.');
            }
            if ($row->native_run !== null) {
                return $this->existingAdmission($period, $row, $current);
            }
            if ($current->root_run !== null) {
                throw new LogicException('A foreign existing Order cannot become this period admission.');
            }
            $now = Carbon::now()->getTimestamp();
            if ($now < $period->startsAt || $now >= $period->endsAt || (bool) $subject->cancellation_requested
                || (bool) $order->cancellation_requested
                || ($subscription->future_cutoff !== null && $period->startsAt >= (int) $subscription->future_cutoff)) {
                return [
                    'accepted' => false,
                    'runId' => null,
                    'reason' => 'original_period_not_admissible',
                ];
            }
            $result = WorkflowStub::make(FundingCheckout::class, self::instance($period), Catalogue::NAMESPACE)
                ->attemptStart($period->order->subjectKey(), StartOptions::rejectDuplicate());
            if (! $result->accepted() || ! is_string($result->runId())) {
                throw new LogicException('An existing foreign native Order cannot be adopted by a billing period.');
            }
            $receipt = $this->receipt($period, $result->runId());
            $this->authority->db->table('provider_billing_periods')
                ->where('id', $key)
                ->update([
                    'native_run' => $result->runId(),
                    'admission_receipt' => $receipt,
                    'admission_sha256' => hash('sha256', $receipt),
                ]);
            return [
                'accepted' => true,
                'runId' => $result->runId(),
                'existing' => false,
            ];
        });
    }

    public function assertActor(WorkflowRun $actual, string $orderKey): void
    {
        $period = $this->authority->periods->forOrder($orderKey);
        if ($period === null) {
            return;
        }
        $this->period($period->subjectKey());
        $row = $this->row($period);
        $root = $this->assertRun($period, $row);
        $arguments = $actual->workflowArguments();
        // MachineStore's existing childPlan then verifies the authoritative reservation, native
        // admission and original scope. A WorkflowRun itself does not carry a parent-link column.
        if ($actual->namespace !== Catalogue::NAMESPACE
            || ($actual->id !== $root->id && (($arguments[1] ?? null) !== $root->id
                || ! in_array($actual->workflow_class, [FundingPayment::class, FullShipment::class], true)))) {
            throw new LogicException('The native actor is outside its original period Order graph.');
        }
    }

    /**
     * Existing native scope/descriptor authorization runs before this additional period fence.
     */
    public function authorizeEffect(OperationContext $context, string $mode): void
    {
        $orderKey = explode('.', $context->scope->key)[0];
        $period = $this->authority->periods->forOrder($orderKey);
        if ($period === null) {
            return;
        }
        $this->authority->authorizeScope($context->scope, $this->authority->principal, $mode);
        $this->assertActor(WorkflowRun::query()->findOrFail($context->originalRunId), $orderKey);
        if ($mode !== 'forward') {
            return;
        }
        $subject = $this->assignments->subject($period->subjectKey(), $period);
        $subscription = $this->assignments->subscription($period);
        if ((bool) $subject->cancellation_requested
            || ($subscription->future_cutoff !== null && $period->startsAt >= (int) $subscription->future_cutoff)) {
            throw new LogicException('The original billing period closed new forward effects.');
        }
        $role = $context->scope->key === $orderKey ? 'order' : substr($context->scope->key, strlen($orderKey) + 1);
        if ($role === 'payment') {
            if (! $context->reconcileBeforeForward && Carbon::now()->getTimestamp() >= $period->paymentDeadline) {
                throw new LogicException('The original period first-charge deadline has elapsed.');
            }
            return;
        }
        $root = $this->assertRun($period, $this->row($period));
        $time = $this->receipts->confirmedAt($period, $root, false);
        if ($time === null || $time >= $period->paymentDeadline) {
            throw new LogicException('Order fulfillment requires this period original on-time payment.');
        }
    }

    public function access(string $key): array
    {
        $period = $this->period($key);
        $row = $this->row($period);
        $root = $this->assertRun($period, $row);
        $subject = $this->assignments->subject($key, $period);
        $order = $this->authority->subject($period->order->subjectKey(), 'order');
        $subscription = $this->assignments->subscription($period);
        $result = BillingPeriodPolicy::access(
            $period,
            Carbon::now()->getTimestamp(),
            $this->receipts->confirmedAt($period, $root),
        );
        if ((bool) $subject->cancellation_requested || (bool) $order->cancellation_requested
            || ($subscription->future_cutoff !== null && $period->startsAt >= (int) $subscription->future_cutoff)) {
            $result['access'] = false;
            $result['state'] = 'cancelled';
        }
        return [
            ...$result,
            'orderKey' => $period->order->subjectKey(),
            'originalRunId' => $root->id,
        ];
    }

    public function cancelFuture(string $key, string $requestKey, int $cutoff): void
    {
        $this->period($key);
        (new BillingPeriodStore(
            $this->assignments->db,
            $this->authority->principal,
            $this->authority->periods->grants(),
            $this->provider,
        ))->cancelFuture($key, $requestKey, $cutoff);
    }

    public function cancel(string $key, string $requestKey): array
    {
        $period = $this->period($key);
        $root = $this->assertRun($period, $this->row($period));
        return (new CancellationStore($this->authority))->request($period->order->subjectKey(), $root->id, $requestKey);
    }

    private function period(string $key): BillingPeriod
    {
        $period = $this->assignments->grant($key);
        $terms = $period->order ?? throw new LogicException('A genuine original period Order is required.');
        $this->authority->authorize($terms->subjectKey(), $this->authority->principal);
        $this->assignments->period($key);
        return $period;
    }

    private function existingAdmission(BillingPeriod $period, object $row, object $assignment): array
    {
        $run = $this->assertRun($period, $row);
        if ($assignment->root_run !== null && $assignment->root_run !== $run->id) {
            throw new LogicException('The original Order acquired a different native owner.');
        }
        return [
            'accepted' => true,
            'runId' => $run->id,
            'existing' => true,
        ];
    }

    private function row(BillingPeriod $period): object
    {
        $row = $this->authority->db->table('provider_billing_periods')
            ->where('id', $period->subjectKey())
            ->sole();
        $this->assignments->assertAssignment($row, $period);
        return $row;
    }

    private function assertRun(BillingPeriod $period, object $row): WorkflowRun
    {
        if (! is_string($row->native_run)) {
            throw new LogicException('The period has no original genuine Order admission.');
        }
        $actual = WorkflowRun::query()->findOrFail($row->native_run);
        $receipt = $this->receipt($period, $actual->id);
        if ($actual->namespace !== Catalogue::NAMESPACE || $actual->workflow_class !== FundingCheckout::class
            || $actual->workflow_instance_id !== self::instance($period)
            || $actual->workflowArguments() !== [$period->order->subjectKey()]
            || $row->admission_receipt !== $receipt || $row->admission_sha256 !== hash('sha256', $receipt)) {
            throw new LogicException('The original period Order admission receipt changed.');
        }
        return $actual;
    }

    private function receipt(BillingPeriod $period, string $run): string
    {
        return PortableDescriptor::encode([
            'period' => $period->fingerprint(),
            'order' => $period->order->subjectKey(),
            'namespace' => Catalogue::NAMESPACE,
            'instance' => self::instance($period),
            'run' => $run,
            'class' => FundingCheckout::class,
        ]);
    }

    private static function instance(BillingPeriod $period): string
    {
        return 'provider-order-' . $period->order->subjectKey();
    }
}
