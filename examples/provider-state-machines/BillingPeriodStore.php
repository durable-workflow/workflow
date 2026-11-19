<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\RetainedOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\TimedObservation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\WorkflowStub;

/** Recorded host authority for service periods. A genuine per-period Order uses its separate binding adapter. */
final readonly class BillingPeriodStore
{
    public const APPLICATION = 'example.subscription-periods';

    public const NAMESPACE = 'provider-periods';

    public BillingPeriodAssignments $assignments;

    /**
     * @param list<BillingPeriod> $grants Immutable assignments authorized by trusted application bootstrap.
     */
    public function __construct(
        public Connection $db,
        public string $principal,
        array $grants,
        public Journal $provider,
    ) {
        $this->assignments = new BillingPeriodAssignments($db, $principal, $grants);
    }

    public static function configure(Application $app, self $store): void
    {
        $app->instance(self::class, $store);
        $app->instance(ScopeAuthorization::class, new class($store) implements ScopeAuthorization {
            public function __construct(
                private BillingPeriodStore $store
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                try {
                    return $this->store->authorizeScope($scope, $principal, $operation);
                } catch (\LogicException $refused) {
                    // Policy refusals are permanent; other failures stay retryable.
                    throw new \Workflow\V2\Compensation\Security\ScopeAuthorizationRefused(
                        $refused->getMessage(),
                        $refused
                    );
                }
            }
        });
        $payment = new RetainedOperation(
            $store->provider,
            static function (OperationContext $context, string $mode) use ($store): void {
                $store->authorizeEffect($context, $mode);
            }
        );
        $app->instance(HandlerRegistry::class, new HandlerRegistry([
            'billing.period.owner' => [
                '1' => new BillingPeriodOwner($store),
            ],
            'billing.period.payment' => [
                '1' => $payment,
            ],
        ]));
        $app->instance(BillingPeriodAssessment::class, new BillingPeriodAssessment($store));
    }

    public function seed(BillingPeriod $period): void
    {
        $expected = $this->assignments->grant($period->subjectKey());
        if ($expected->fingerprint() !== $period->fingerprint() || $period->order !== null) {
            throw new LogicException('The standalone service adapter cannot manufacture or adopt an Order.');
        }
        $key = $period->subjectKey();
        $this->db->transaction(function () use ($period, $key): void {
            $subscription = $this->assignments->subscriptionId($period);
            $this->db->table('provider_billing_subscriptions')
                ->insertOrIgnore([
                    'id' => $subscription,
                    'principal' => $this->principal,
                    'authority_version' => $this->assignments->subscriptionVersion($period),
                ]);
            $this->assignments->subscription($period);
            $this->db->table('provider_billing_periods')
                ->insert([
                    'id' => $key,
                    'subscription_id' => $subscription,
                    'principal' => $this->principal,
                    'authority_version' => $this->assignments->version($period),
                    'assignment' => PortableDescriptor::encode($period->toArray()),
                    'assignment_sha256' => $period->fingerprint(),
                ]);
            $this->db->table('example_machine_subjects')
                ->insert([
                    'id' => BillingPeriodAssignments::subjectId($key),
                    'scope' => self::APPLICATION,
                    'subject' => $key,
                    'principal' => $this->principal,
                    'authority_version' => $this->assignments->version($period),
                    'state' => 'received',
                    'revision' => 1,
                    'cancellation_requested' => false,
                ]);
        });
    }

    /**
     * No pre-charge: a period is admitted only when due. Future cancellation cannot rewrite a paid period.
     */
    public function admit(string $key): array
    {
        $period = $this->assignments->period($key);
        return $this->db->transaction(function () use ($key, $period): array {
            $subscription = $this->assignments->subscription($period);
            $subject = $this->assignments->subject($key, $period, true);
            $row = $this->db->table('provider_billing_periods')
                ->where('id', $key)
                ->lockForUpdate()
                ->sole();
            $this->assignments->assertAssignment($row, $period);
            if ($row->native_run !== null) {
                $actual = WorkflowRun::query()->findOrFail($row->native_run);
                $this->assignments->assertRun($actual, $period, $row);
                return [
                    'accepted' => true,
                    'runId' => $actual->id,
                    'existing' => true,
                ];
            }
            $now = Carbon::now()->getTimestamp();
            if ($now < $period->startsAt || $now >= $period->endsAt || (bool) $subject->cancellation_requested
                || ($subscription->future_cutoff !== null && $period->startsAt >= (int) $subscription->future_cutoff)) {
                return [
                    'accepted' => false,
                    'runId' => null,
                    'reason' => 'original_period_not_admissible',
                ];
            }
            $result = WorkflowStub::make(
                ServicePeriodPayment::class,
                BillingPeriodAssignments::instance($key),
                self::NAMESPACE
            )
                ->attemptStart($key, StartOptions::rejectDuplicate());
            if (! $result->accepted() || ! is_string($result->runId())) {
                throw new LogicException('Native period admission refused; an existing foreign run cannot be adopted.');
            }
            $receipt = PortableDescriptor::encode([
                'period' => $period->fingerprint(),
                'namespace' => self::NAMESPACE,
                'instance' => BillingPeriodAssignments::instance($key),
                'run' => $result->runId(),
                'class' => ServicePeriodPayment::class,
            ]);
            $this->db->table('provider_billing_periods')
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

    public function cancelFuture(string $key, string $requestKey, int $cutoff): void
    {
        $period = $this->assignments->period($key);
        PortableDescriptor::identifier($requestKey, 'future billing cancellation request');
        $this->db->transaction(function () use ($period, $requestKey, $cutoff): void {
            $subscription = $this->assignments->subscription($period);
            $id = hash('sha256', PortableDescriptor::encode([$subscription->id, $requestKey]));
            $hash = hash(
                'sha256',
                PortableDescriptor::encode([$subscription->id, $requestKey, $cutoff, $this->principal])
            );
            $prior = $this->db->table('provider_billing_future_cancellations')
                ->where('id', $id)
                ->first();
            if ($prior !== null && ($prior->subscription_id !== $subscription->id || $prior->request_sha256 !== $hash)) {
                throw new LogicException('A future billing decision cannot change its original cutoff.');
            }
            if ($prior !== null) {
                return;
            }
            if ($cutoff <= Carbon::now()->getTimestamp()) {
                throw new LogicException('Future cancellation cannot retroactively cancel an admitted current period.');
            }
            if ($prior === null) {
                $this->db->table('provider_billing_future_cancellations')
                    ->insert([
                        'id' => $id,
                        'subscription_id' => $subscription->id,
                        'request_sha256' => $hash,
                        'cutoff' => $cutoff,
                    ]);
            }
            $this->db->table('provider_billing_subscriptions')
                ->where('id', $subscription->id)
                ->update([
                    'future_cutoff' => $subscription->future_cutoff === null ? $cutoff : min(
                        $cutoff,
                        (int) $subscription->future_cutoff
                    ),
                ]);
        });
    }

    public function actor(WorkflowRun $actual): array
    {
        if ($actual->namespace !== self::NAMESPACE || $actual->workflow_class !== ServicePeriodPayment::class) {
            throw new LogicException('The native actor is outside the original service-period type and namespace.');
        }
        $args = $actual->workflowArguments();
        if (count($args) !== 1 || ! is_string($args[0])) {
            throw new LogicException('The period native arguments are malformed.');
        }
        $period = $this->assignments->period($args[0]);
        $row = $this->db->table('provider_billing_periods')
            ->where('id', $period->subjectKey())
            ->sole();
        $this->assignments->assertRun($actual, $period, $row);
        return [$period, $this->assignments->subject($period->subjectKey(), $period), $row];
    }

    public function initialize(WorkflowRun $actual): array
    {
        [$period] = $this->actor($actual);
        return [
            'period' => $period->toArray(),
            'scope' => $this->scope($period->subjectKey())
                ->toArray(),
            'principal' => $this->principal,
            'nativeRun' => $actual->id,
        ];
    }

    public function scope(string $key): ScopeIdentity
    {
        $this->assignments->period($key);
        $row = $this->db->table('provider_billing_periods')
            ->where('id', $key)
            ->sole();
        if (! is_string($row->native_run)) {
            throw new LogicException('The original period has no native admission receipt.');
        }
        $period = $this->assignments->period($key);
        $actual = WorkflowRun::query()->where('namespace', self::NAMESPACE)->findOrFail($row->native_run);
        $this->assignments->assertRun($actual, $period, $row);
        return new ScopeIdentity(self::APPLICATION, self::NAMESPACE, $key, $row->native_run);
    }

    public function profile(BillingPeriod $period): PolicyProfile
    {
        return BillingPeriodPolicy::paymentProfile($period);
    }

    public function execution(WorkflowRun $actual, string $transition): ExecutionReference
    {
        [$period, $subject] = $this->actor($actual);
        return new ExecutionReference(
            self::APPLICATION,
            $period->subjectKey(),
            $actual->id . '.' . $transition . '.' . $subject->revision,
            $this->profile($period)
                ->fingerprint(),
            $subject->state,
            (string) $subject->revision
        );
    }

    public function observation(WorkflowRun $actual): array
    {
        [$period] = $this->actor($actual);
        $charge = Request::fromArray($period->paymentDescriptor()['forward']);
        $receipt = $this->provider->timedLookup($charge);
        // Decoding is also used on history values; never accept another period's receipt.
        TimedObservation::fromArray($receipt->toArray(), $charge);
        $projection = BillingPeriodPolicy::access($period, Carbon::now()->getTimestamp(), $receipt->confirmedAt);
        $cancelled = $this->db->transaction(function () use ($period, $receipt): bool {
            $currentSubject = $this->assignments->subject($period->subjectKey(), $period, true);
            $current = $this->db->table('provider_billing_periods')
                ->where('id', $period->subjectKey())
                ->lockForUpdate()
                ->sole();
            $this->assignments->assertAssignment($current, $period);
            if ($current->confirmed_at !== null && ($receipt->confirmedAt !== (int) $current->confirmed_at
                || $receipt->observation->effectId !== $current->effect_id)) {
                throw new LogicException('The original confirmed period payment cannot change its receipt.');
            }
            if ($receipt->confirmedAt !== null && $current->confirmed_at === null) {
                $this->db->table('provider_billing_periods')
                    ->where('id', $period->subjectKey())
                    ->update([
                        'confirmed_at' => $receipt->confirmedAt,
                        'effect_id' => $receipt->observation->effectId,
                    ]);
            }
            return (bool) $currentSubject->cancellation_requested;
        });
        $projection['access'] = $projection['access'] && ! $cancelled;
        return [
            'receipt' => $receipt->toArray(),
            'projection' => $projection,
            'cancelled' => $cancelled,
            'waitSeconds' => max(0, min($period->paymentDeadline > Carbon::now()->getTimestamp()
                ? $period->paymentDeadline : $period->endsAt, $period->endsAt) - Carbon::now()->getTimestamp()),
        ];
    }

    /**
     * Application access always rechecks the fixed cutoff and live cancellation, even with a stopped worker.
     */
    public function access(string $key): array
    {
        $period = $this->assignments->period($key);
        $subject = $this->assignments->subject($key, $period);
        $row = $this->db->table('provider_billing_periods')
            ->where('id', $key)
            ->sole();
        if ($row->native_run === null) {
            $value = BillingPeriodPolicy::access($period, Carbon::now()->getTimestamp(), null);
            $value['access'] = false;
            $value['state'] = 'not-admitted';
            return $value;
        }
        // Grace access also needs the exact original admission; seeded future terms grant no access.
        $this->scope($key);
        $confirmedAt = $subject->state === 'paid' ? $this->paidTime($period, $row) : null;
        $value = BillingPeriodPolicy::access($period, Carbon::now()->getTimestamp(), $confirmedAt);
        $value['access'] = $value['access'] && ! (bool) $subject->cancellation_requested;
        return $value;
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        if ($principal !== $this->principal || $scope->application !== self::APPLICATION || $scope->namespace !== self::NAMESPACE
            || ! in_array(
                $operation,
                ['register', 'forward', 'compensate', 'participate', 'inspect', 'request'],
                true
            )) {
            throw new LogicException('The billing scope is outside current host authority.');
        }
        $period = $this->assignments->period($scope->key);
        if ($scope->toArray() !== $this->scope($scope->key)->toArray()) {
            throw new LogicException('The billing scope substituted its original native period.');
        }
        return $this->assignments->version($period);
    }

    public function authorizeEffect(OperationContext $context, string $mode, bool $owner = false): array
    {
        $this->authorizeScope($context->scope, $this->principal, $mode);
        $actual = WorkflowRun::query()->findOrFail($context->originalRunId);
        [$period, $subject] = $this->actor($actual);
        $expected = $owner ? $this->owner($period->subjectKey(), $actual->id) : $this->payment($period);
        if ($context->scope->generation !== $actual->id || $context->obligation->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The billing effect substituted its original scope or descriptor.');
        }
        $ledger = (new ScopeRepository())->inspect($context->scope);
        $matched = array_filter(
            $ledger['obligations'],
            static fn (array $row): bool => $row['id'] === $context->operationId
            && Obligation::fromArray($row['descriptor'])->fingerprint() === $expected->fingerprint()
        );
        if (count($matched) !== 1 || $ledger['original_run_id'] !== $actual->id) {
            throw new LogicException('The billing effect lacks its original recorded duty.');
        }
        if ($mode === 'forward' && ! $owner && (bool) $subject->cancellation_requested) {
            throw new LogicException('The original period forward path is closed.');
        }
        if ($mode === 'forward' && ! $owner && ! $context->reconcileBeforeForward
            && Carbon::now()->getTimestamp() >= $period->paymentDeadline) {
            throw new LogicException('The original period first-charge deadline has elapsed.');
        }
        return [$period, $subject];
    }

    public function owner(string $key, string $run): Obligation
    {
        return new Obligation('owner', 'billing.period.owner', '1', [
            'period' => $key,
            'run' => $run,
        ]);
    }

    public function payment(BillingPeriod $period): Obligation
    {
        return new Obligation('payment', 'billing.period.payment', '1', $period->paymentDescriptor());
    }

    public function cancel(string $key, string $requestKey): array
    {
        $period = $this->assignments->period($key);
        PortableDescriptor::identifier($requestKey, 'original period cancellation request');
        $row = $this->db->table('provider_billing_periods')
            ->where('id', $key)
            ->sole();
        $actual = is_string($row->native_run) ? WorkflowRun::query()->findOrFail($row->native_run) : null;
        $execution = $actual === null
            ? new ExecutionReference(self::APPLICATION, $key, 'period-cancellation', $this->profile(
                $period
            )->fingerprint(), 'received', '1')
            : $this->execution($actual, 'cancel');
        $store = new SqlBusinessStore($this->db, function (ExecutionReference $reference, string $principal) use (
            $key,
            $period
        ): string {
            $this->assignments->period($key);
            if ($principal !== $this->principal || $reference->subject !== $key || $reference->scope !== self::APPLICATION) {
                throw new LogicException('The cancellation belongs to another original period.');
            }
            return $this->assignments->version($period);
        });
        $store->requestCancellation($execution, $requestKey, $this->principal);
        if ($actual === null) {
            return [
                'status' => 'unresolved',
                'reason' => 'original_scope_not_initialized',
            ];
        }
        try {
            return app(ScopeRecovery::class)->request(
                $this->scope($key),
                $requestKey,
                $this->principal,
                'period-cancellation'
            );
        } catch (LogicException $error) {
            if ($error->getMessage() !== 'The exact compensation scope generation is unavailable.') {
                throw $error;
            }
            return [
                'status' => 'unresolved',
                'reason' => 'original_scope_not_initialized',
            ];
        }
    }

    private function paidTime(BillingPeriod $period, object $row): int
    {
        $scope = $this->scope($period->subjectKey());
        $profile = $this->profile($period);
        $execution = new ExecutionReference(
            self::APPLICATION,
            $period->subjectKey(),
            $scope->generation . '.confirm-receipt.3',
            $profile->fingerprint(),
            'collected',
            '3'
        );
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $publication = $this->db->table('example_machine_publications')
            ->where('id', $id)
            ->sole();
        $decisionRow = $this->db->table('example_machine_decisions')
            ->where('id', $id)
            ->sole();
        $expected = [
            'execution' => $execution->toArray(),
            'transitionKey' => 'confirm-receipt',
            'state' => 'paid',
            'revision' => '4',
        ];
        if ($publication->subject_id !== BillingPeriodAssignments::subjectId(
            $period->subjectKey()
        ) || $decisionRow->subject_id !== $publication->subject_id
            || $decisionRow->status !== 'committed' || strlen($publication->payload) > PortableDescriptor::MAX_BYTES
            || PortableDescriptor::encode(
                json_decode($publication->payload, true, flags: JSON_THROW_ON_ERROR)
            ) !== PortableDescriptor::encode($expected)) {
            throw new LogicException('Period access requires the original protected paid publication.');
        }
        $charge = Request::fromArray($period->paymentDescriptor()['forward']);
        $edge = array_values(
            array_filter($profile->transitions, static fn ($edge): bool => $edge->key === 'confirm-receipt')
        )[0];
        $records = $this->db->table('provider_billing_assessments')
            ->where('period_id', $period->subjectKey())
            ->limit(16)
            ->get();
        foreach ($records as $record) {
            if (strlen($record->payload) > PortableDescriptor::MAX_BYTES
                || ! hash_equals($record->payload_sha256, hash('sha256', $record->payload))) {
                throw new LogicException('The original paid period assessment was corrupted.');
            }
            $payload = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
            $snapshot = $payload['snapshot'];
            if (PortableDescriptor::encode($snapshot['execution']) !== PortableDescriptor::encode(
                $execution->toArray()
            )) {
                continue;
            }
            $decode = static fn (array $v): array => (new Decision(Verdict::from(
                $v['verdict']
            ), $v['reason'], $v['evidence']))->toArray();
            $request = [
                'execution' => $execution->toArray(),
                'transition' => get_object_vars($edge),
                'snapshot' => [
                    'id' => $snapshot['snapshotId'],
                    'phase' => $snapshot['phase'],
                    'target' => $decode($snapshot['target']),
                    'permission' => $decode($snapshot['permission']),
                ],
                'machineAssessment' => MachineAssessment::fromArray(
                    $snapshot['machineAssessment'],
                    $profile->definition
                )->toArray(),
            ];
            if (hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)) !== $decisionRow->request_sha256) {
                continue;
            }
            $receipt = TimedObservation::fromArray($payload['receipt'], $charge);
            if ($receipt->confirmedAt === null || $receipt->confirmedAt !== (int) $row->confirmed_at
                || $receipt->observation->effectId !== $row->effect_id) {
                throw new LogicException('The period access projection substituted its original payment receipt.');
            }
            return $receipt->confirmedAt;
        }
        throw new LogicException('The paid period has no matching immutable payment assessment.');
    }
}
