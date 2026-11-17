<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Illuminate\Database\Connection;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\WorkflowFiberContext;

/** Trusted original assignments and their native admission identity; no provider I/O. */
final readonly class BillingPeriodAssignments
{
    /**
     * @param list<BillingPeriod> $grants
     */
    public function __construct(
        public Connection $db,
        public string $principal,
        private array $grants
    ) {
        PortableDescriptor::identifier($principal, 'authenticated billing principal');
        if ($db !== ConfiguredV2Models::connection() || ! array_is_list($grants)) {
            throw new LogicException('Period admission needs an explicit grant list and the native SQL connection.');
        }
        foreach ($grants as $grant) {
            if (! $grant instanceof BillingPeriod) {
                throw new LogicException('Billing grants must pin original typed period assignments.');
            }
        }
    }

    public function grant(string $key): BillingPeriod
    {
        if ($this->db->transactionLevel() !== 0 || WorkflowFiberContext::active()) {
            throw new LogicException('Billing host authorization must precede its owned SQL transaction.');
        }
        foreach ($this->grants as $period) {
            if ($period->subjectKey() === $key) {
                return $period;
            }
        }
        throw new LogicException('The original period is outside this authenticated host grant.');
    }

    public function version(BillingPeriod $period): string
    {
        return hash('sha256', 'billing.period.host.v1:' . $this->principal . ':' . $period->fingerprint()
            . ':' . BillingPeriodPolicy::paymentProfile($period)->fingerprint());
    }

    public function period(string $key): BillingPeriod
    {
        $expected = $this->grant($key);
        $row = $this->db->table('provider_billing_periods')
            ->where('id', $key)
            ->sole();
        $this->assertAssignment($row, $expected);
        return $expected;
    }

    public function subject(string $key, BillingPeriod $period, bool $lock = false): object
    {
        $query = $this->db->table('example_machine_subjects')
            ->where('id', self::subjectId($key));
        $row = ($lock ? $query->lockForUpdate() : $query)
            ->sole();
        if ($row->scope !== BillingPeriodStore::APPLICATION || $row->subject !== $key || $row->principal !== $this->principal
            || $row->authority_version !== $this->version($period)) {
            throw new LogicException('The original billing subject authority changed.');
        }
        return $row;
    }

    public static function subjectId(string $key): string
    {
        return hash('sha256', json_encode([BillingPeriodStore::APPLICATION, $key], JSON_THROW_ON_ERROR));
    }

    public static function instance(string $key): string
    {
        return 'billing-period-' . $key;
    }

    public function assertAssignment(object $row, BillingPeriod $period): void
    {
        if ($row->principal !== $this->principal || $row->authority_version !== $this->version($period)
            || $row->id !== $period->subjectKey() || $row->subscription_id !== $this->subscriptionId($period)
            || $row->assignment_sha256 !== $period->fingerprint()
            || $row->assignment !== PortableDescriptor::encode($period->toArray())) {
            throw new LogicException('The original period assignment, money, policy or owner changed.');
        }
    }

    public function assertRun(WorkflowRun $actual, BillingPeriod $period, object $row): void
    {
        $expected = PortableDescriptor::encode([
            'period' => $period->fingerprint(),
            'namespace' => BillingPeriodStore::NAMESPACE,
            'instance' => self::instance($period->subjectKey()),
            'run' => $actual->id,
            'class' => ServicePeriodPayment::class,
        ]);
        if ($actual->namespace !== BillingPeriodStore::NAMESPACE || $actual->workflow_class !== ServicePeriodPayment::class
            || $actual->workflow_instance_id !== self::instance(
                $period->subjectKey()
            ) || $row->native_run !== $actual->id
            || $actual->workflowArguments() !== [$period->subjectKey()] || $row->admission_receipt !== $expected
            || $row->admission_sha256 !== hash('sha256', $expected)) {
            throw new LogicException('The original native period admission receipt changed.');
        }
    }

    public function subscription(BillingPeriod $period): object
    {
        $row = $this->db->table('provider_billing_subscriptions')
            ->where('id', $this->subscriptionId($period))
            ->lockForUpdate()
            ->sole();
        if ($row->principal !== $this->principal || $row->authority_version !== $this->subscriptionVersion($period)) {
            throw new LogicException('The original subscription authority changed.');
        }
        return $row;
    }

    public function subscriptionId(BillingPeriod $period): string
    {
        return hash(
            'sha256',
            PortableDescriptor::encode([$period->client, $period->subscription, $period->generation])
        );
    }

    public function subscriptionVersion(BillingPeriod $period): string
    {
        return hash('sha256', 'billing.subscription.v1:' . $this->principal . ':' . $this->subscriptionId($period));
    }
}
