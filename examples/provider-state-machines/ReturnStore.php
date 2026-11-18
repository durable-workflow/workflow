<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

/**
 * Host boundary for a customer unit return after original delivery. The trusted application pins each
 * return assignment before native execution. Every provider read is a recorded activity; the Order,
 * its delivery evidence and its child histories are never rewritten.
 */
final readonly class ReturnStore
{
    public const REQUEST_HANDLER = 'provider.return.request';

    public const RESTOCK_HANDLER = 'provider.return.restock';

    public const REFUND_HANDLER = 'provider.return.refund';

    public const SCOPE_INFIX = '.return.';

    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private UnitRefundGateway $refunds,
    ) {
        $refunds->assertProvider($provider);
    }

    public static function id(string $key, string $returnKey): string
    {
        return hash('sha256', PortableDescriptor::encode(['unit-return-v1', $key, $returnKey]));
    }

    public static function instance(string $key, string $returnKey): string
    {
        return 'provider-return-' . self::id($key, $returnKey);
    }

    /**
     * Trusted application command. The same key and units are idempotent; changed units are refused.
     */
    public function request(string $key, string $returnKey, array $units): array
    {
        $this->authority->authorize($key, $this->authority->principal);
        if (str_starts_with($returnKey, ExchangeStore::RETURN_PREFIX)) {
            throw new LogicException('Return keys under the exchange prefix belong to exchanges.');
        }
        [$original, $booking] = $this->original($key);
        $return = UnitReturnTerms::request($booking, $returnKey, $original->ordinals($units));
        $assignment = PortableDescriptor::encode([
            'version' => 1,
            'key' => $key,
            'returnKey' => $returnKey,
            'units' => UnitReturnTerms::fromArray($return->terms)->units,
            'allocationFingerprint' => $original->fingerprint(),
            'request' => $return->toArray(),
        ]);
        $id = self::id($key, $returnKey);
        // Read outside the protected transaction; the original delivery publication never reverts.
        $delivered = $this->authority->subject($key, 'shipment')
->state === 'delivered';
        return $this->authority->db->transaction(function () use (
            $key,
            $returnKey,
            $id,
            $assignment,
            $delivered
        ): array {
            $root = $this->authority->db->table('example_machine_subjects')
                ->where('id', Authority::subjectId($key, 'order'))
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($root, $key, 'order');
            $existing = $this->authority->db->table('provider_machine_returns')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && ($existing->assignment !== $assignment
                || $existing->assignment_sha256 !== hash('sha256', $assignment))) {
                throw new LogicException('The original return request cannot change its units or owner.');
            }
            if ($existing === null) {
                $this->admissible(
                    $key,
                    $root,
                    $delivered,
                    json_decode($assignment, true, flags: JSON_THROW_ON_ERROR)['units']
                );
                $this->authority->db->table('provider_machine_returns')
                    ->insert([
                        'id' => $id,
                        'root_key' => $key,
                        'return_key' => $returnKey,
                        'assignment' => $assignment,
                        'assignment_sha256' => hash('sha256', $assignment),
                    ]);
            }
            $row = $this->authority->db->table('provider_machine_returns')
                ->where('id', $id)
                ->sole();
            if ($row->native_run !== null) {
                return [
                    'accepted' => true,
                    'runId' => $row->native_run,
                    'existing' => true,
                ];
            }
            $started = WorkflowStub::make(UnitReturn::class, self::instance($key, $returnKey), Catalogue::NAMESPACE)
                ->attemptStart($key, $returnKey, StartOptions::rejectDuplicate());
            if (! $started->accepted() || ! is_string($started->runId())) {
                throw new LogicException('Native return admission refused; a foreign run cannot be adopted.');
            }
            $this->authority->db->table('provider_machine_returns')
                ->where('id', $id)
                ->update([
                    'native_run' => $started->runId(),
                ]);
            return [
                'accepted' => true,
                'runId' => $started->runId(),
                'existing' => false,
            ];
        });
    }

    public function initialize(WorkflowRun $actual, array $arguments): array
    {
        if (PortableDescriptor::encode($arguments) !== PortableDescriptor::encode($actual->workflowArguments())) {
            throw new LogicException('The recorded return arguments differ from original native admission.');
        }
        [$key, $returnKey, , $assignment] = $this->actor($actual);
        return [
            'key' => $key,
            'returnKey' => $returnKey,
            'units' => $assignment['units'],
            'nativeRun' => $actual->id,
            'principal' => $this->authority->principal,
            'scope' => $this->scope($key, $returnKey, $actual->id)
                ->toArray(),
            'request' => $this->requestObligation($assignment)
                ->toArray(),
        ];
    }

    /**
     * Receipt alone never settles. The first inspection-based plan is stored and never changes.
     */
    public function observe(WorkflowRun $actual): array
    {
        [$key, $returnKey, $row, $assignment] = $this->actor($actual);
        $scope = $this->scope($key, $returnKey, $actual->id);
        PostDeliveryRecords::registered(
            'return',
            $scope,
            $this->requestObligation($assignment),
            $actual->id,
            observed: true
        );
        if ($row->plan !== null) {
            return PostDeliveryRecords::decode('return', $row->plan, $row->plan_sha256);
        }
        $snapshot = $this->provider->shipments()
            ->inspectUnitReturn($assignment['request']);
        $facts = $snapshot['facts'];
        if (! $facts['return_requested']) {
            throw new LogicException('The original unit return request is not confirmed.');
        }
        if (! $facts['warehouse_inspected']) {
            return [
                'action' => 'wait',
                'requestedUnits' => $assignment['units'],
                'receivedUnits' => $facts['received_units'],
            ];
        }
        $saleable = $facts['saleable_units'];
        $plan = PortableDescriptor::encode([
            'action' => 'settle',
            'requestedUnits' => $assignment['units'],
            'receivedUnits' => $facts['received_units'],
            'acceptedUnits' => $facts['accepted_units'],
            'saleableUnits' => $saleable,
            // Selected policy: only accepted saleable units are restocked and refunded.
            'discrepancyUnits' => array_values(array_diff($assignment['units'], $saleable)),
            'receiptId' => $snapshot['events']['received']['id'],
            'inspectionId' => $snapshot['events']['inspected']['id'],
            'restock' => $saleable === [] ? null : $this->restockObligation($assignment, $saleable)->toArray(),
            'refund' => $saleable === [] ? null : $this->refundObligation($assignment, $saleable)->toArray(),
        ]);
        $this->authority->db->transaction(function () use ($row, $plan): void {
            $current = $this->authority->db->table('provider_machine_returns')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            if ($current->plan === null) {
                $this->authority->db->table('provider_machine_returns')
                    ->where('id', $row->id)
                    ->update([
                        'plan' => $plan,
                        'plan_sha256' => hash('sha256', $plan),
                    ]);
            }
        });
        $stored = $this->row($key, $returnKey);
        return PostDeliveryRecords::decode('return', $stored->plan, $stored->plan_sha256);
    }

    /**
     * The protected return outcome. Settled and refused outcomes are immutable; unsettled ones are not stored.
     */
    public function publish(WorkflowRun $actual): array
    {
        [$key, $returnKey, $row, $assignment] = $this->actor($actual);
        if ($row->outcome !== null) {
            return PostDeliveryRecords::decode('return', $row->outcome, $row->outcome_sha256);
        }
        $scope = $this->scope($key, $returnKey, $actual->id);
        PostDeliveryRecords::registered(
            'return',
            $scope,
            $this->requestObligation($assignment),
            $actual->id,
            observed: true
        );
        $plan = $row->plan === null ? null : PostDeliveryRecords::decode('return', $row->plan, $row->plan_sha256);
        $saleable = $plan['saleableUnits'] ?? [];
        $restock = $saleable === [] ? null : UnitReturnTerms::restock($assignment['request'], $saleable);
        $snapshot = $this->provider->shipments()
            ->inspectUnitReturn($assignment['request'], $restock);
        $refunded = [
            'confirmedUnits' => [],
            'ownPayoutMinor' => 0,
        ];
        if ($saleable !== []) {
            foreach (['restockObligation', 'refundObligation'] as $method) {
                PostDeliveryRecords::registered(
                    'return',
                    $scope,
                    $this->{$method}($assignment, $saleable),
                    $actual->id,
                    observed: true
                );
            }
            $refunded = $this->refunds->lookup($this->command($assignment, $saleable));
        }
        $status = match (true) {
            $snapshot['observations']['return']['status'] === 'refused' => 'refused',
            $plan !== null && $snapshot['facts']['restocked_units'] === $saleable
                && $refunded['confirmedUnits'] === $saleable => 'settled',
            default => 'unsettled',
        };
        $outcome = [
            'version' => 1,
            'returnKey' => $returnKey,
            'status' => $status,
            'originalOrderState' => $this->authority->subject($key, 'order')
->state,
            'originalBooking' => $assignment['booking']->identity->key(),
            'returnOperation' => $assignment['request']->identity->key(),
            'requestedUnits' => $assignment['units'],
            'receivedUnits' => $plan['receivedUnits'] ?? $snapshot['facts']['received_units'],
            'acceptedUnits' => $plan['acceptedUnits'] ?? [],
            'saleableUnits' => $saleable,
            'discrepancyUnits' => $plan['discrepancyUnits'] ?? [],
            'restockedUnits' => $snapshot['facts']['restocked_units'],
            'refundedUnits' => $refunded['confirmedUnits'],
            'refundedAmountMinor' => $refunded['confirmedUnits'] === [] ? 0
                : $assignment['original']->amountFor($refunded['confirmedUnits']),
            'refundPayoutMinor' => $refunded['ownPayoutMinor'],
        ];
        if ($status === 'unsettled') {
            return $outcome;
        }
        $payload = PortableDescriptor::encode($outcome);
        $this->authority->db->transaction(function () use ($row, $payload): void {
            $current = $this->authority->db->table('provider_machine_returns')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            if ($current->outcome === null) {
                $this->authority->db->table('provider_machine_returns')
                    ->where('id', $row->id)
                    ->update([
                        'outcome' => $payload,
                        'outcome_sha256' => hash('sha256', $payload),
                    ]);
            }
        });
        $stored = $this->row($key, $returnKey);
        return PostDeliveryRecords::decode('return', $stored->outcome, $stored->outcome_sha256);
    }

    /**
     * Authorization callback for every return handler, before provider I/O.
     */
    public function operation(OperationContext $context, string $mode): void
    {
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The return operation mode is unavailable.');
        }
        $this->authorizeScope($context->scope, $this->authority->principal, $mode);
        [$key, $returnKey] = PostDeliveryRecords::parse('return', $context->scope, self::SCOPE_INFIX);
        $row = $this->row($key, $returnKey);
        $assignment = $this->assignment($row);
        $plan = $row->plan === null ? null : PostDeliveryRecords::decode('return', $row->plan, $row->plan_sha256);
        $saleable = $plan['saleableUnits'] ?? [];
        $expected = match ($context->obligation->key) {
            'return.request' => $this->requestObligation($assignment),
            'return.restock' => $saleable === [] ? null : $this->restockObligation($assignment, $saleable),
            'return.refund' => $saleable === [] ? null : $this->refundObligation($assignment, $saleable),
            default => null,
        };
        if ($expected === null || $context->originalRunId !== $row->native_run
            || $context->obligation->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The return operation belongs to another original return, Order or unit quote.');
        }
        PostDeliveryRecords::registered('return', $context->scope, $expected, $row->native_run, $context->operationId);
        if ($mode === 'forward' && $context->obligation->key === 'return.request'
            && (bool) $this->authority->subject($key, 'order')
                ->cancellation_requested) {
            throw new LogicException('Original business cancellation has closed unit return admission.');
        }
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        [$key, $returnKey] = PostDeliveryRecords::parse('return', $scope, self::SCOPE_INFIX);
        $this->authority->authorize($key, $principal);
        if ($scope->application !== Catalogue::APPLICATION || $scope->namespace !== Catalogue::NAMESPACE
            || ! in_array($operation, ['register', 'forward', 'compensate', 'request', 'inspect'], true)) {
            throw new LogicException('The return scope is outside the selected host boundary.');
        }
        $row = $this->row($key, $returnKey);
        if (! is_string($row->native_run)
            || $scope->toArray() !== $this->scope($key, $returnKey, $row->native_run)
                ->toArray()) {
            throw new LogicException('The return scope substituted its original native return.');
        }
        return $row->assignment_sha256;
    }

    public function scope(string $key, string $returnKey, string $run): ScopeIdentity
    {
        return new ScopeIdentity(
            Catalogue::APPLICATION,
            Catalogue::NAMESPACE,
            $key . self::SCOPE_INFIX . $returnKey,
            $run
        );
    }

    /**
     * Tests and operators can name the exact original return request and its refund command.
     */
    public function returnRequest(string $key, string $returnKey): Request
    {
        return $this->assignment($this->row($key, $returnKey))['request'];
    }

    public function refundCommand(string $key, string $returnKey, array $units): UnitRefundCommand
    {
        return $this->command($this->assignment($this->row($key, $returnKey)), $units);
    }

    /**
     * @return array{OriginalUnitAmounts, Request}
     */
    public function original(string $key): array
    {
        [, $terms] = $this->authority->assignment($key);
        if ($this->authority->recipe($key) !== FinancialCatalogue::AFTER_STOCK
            || ! $this->authority->unitMoney->authorized($key)) {
            throw new LogicException(
                'Unit returns need the original stock-triggered capture with explicit unit money.'
            );
        }
        return [
            $this->authority->unitMoney->original($terms),
            Request::fromArray($terms->physicalDescriptor()['booking']),
        ];
    }

    private function actor(WorkflowRun $actual): array
    {
        $args = $actual->workflowArguments();
        if ($actual->namespace !== Catalogue::NAMESPACE || $actual->workflow_class !== UnitReturn::class
            || count($args) !== 2 || ! is_string($args[0] ?? null) || ! is_string($args[1] ?? null)) {
            throw new LogicException('The native actor is outside the original unit return type.');
        }
        [$key, $returnKey] = $args;
        $row = $this->row($key, $returnKey);
        if ($row->native_run !== $actual->id || $actual->workflow_instance_id !== self::instance($key, $returnKey)) {
            throw new LogicException('The native return actor differs from its pinned original admission.');
        }
        return [$key, $returnKey, $row, $this->assignment($row)];
    }

    private function row(string $key, string $returnKey): object
    {
        $this->authority->authorize($key, $this->authority->principal);
        $row = $this->authority->db->table('provider_machine_returns')
            ->where('id', self::id($key, $returnKey))
            ->first();
        if ($row === null || $row->root_key !== $key || $row->return_key !== $returnKey
            || ! hash_equals($row->assignment_sha256, hash('sha256', $row->assignment))) {
            throw new LogicException('The original return assignment is unavailable or changed.');
        }
        return $row;
    }

    /**
     * @return array{units: list<int>, request: Request, booking: Request, original: OriginalUnitAmounts}
     */
    private function assignment(object $row): array
    {
        $value = json_decode($row->assignment, true, flags: JSON_THROW_ON_ERROR);
        [$original, $booking] = $this->original($row->root_key);
        $request = UnitReturnTerms::request($booking, $row->return_key, $value['units']);
        if ($value['key'] !== $row->root_key || $value['returnKey'] !== $row->return_key
            || $value['allocationFingerprint'] !== $original->fingerprint()
            || Request::fromArray($value['request'])->fingerprint() !== $request->fingerprint()) {
            throw new LogicException('The return assignment substituted its original booking or unit quote.');
        }
        return [
            'units' => $value['units'],
            'request' => $request,
            'booking' => $booking,
            'original' => $original,
        ];
    }

    private function admissible(string $key, object $root, bool $delivered, array $units): void
    {
        if ($root->state !== 'fulfilled' || (bool) $root->cancellation_requested || ! $delivered) {
            throw new LogicException('A unit return requires its original fulfilled and delivered Order generation.');
        }
        if (array_intersect(ExchangeStore::claimed($this->authority->db, $key), $units) !== []) {
            throw new LogicException('An original unit already belongs to an exchange, not another return.');
        }
        foreach ($this->authority->db->table('provider_machine_returns')->where('root_key', $key)->get() as $other) {
            $claimed = json_decode($other->assignment, true, flags: JSON_THROW_ON_ERROR)['units'];
            if (array_intersect($claimed, $units) !== []) {
                throw new LogicException('An original unit already belongs to another return.');
            }
        }
    }

    private function requestObligation(array $assignment): Obligation
    {
        return new Obligation('return.request', self::REQUEST_HANDLER, '1', [
            'version' => 1,
            'request' => $assignment['request']->toArray(),
        ]);
    }

    private function restockObligation(array $assignment, array $saleable): Obligation
    {
        return new Obligation('return.restock', self::RESTOCK_HANDLER, '1', [
            'version' => 1,
            'request' => UnitReturnTerms::restock($assignment['request'], $saleable)->toArray(),
        ]);
    }

    private function refundObligation(array $assignment, array $saleable): Obligation
    {
        return new Obligation('return.refund', self::REFUND_HANDLER, '1', [
            'version' => 1,
            'refund' => $this->command($assignment, $saleable)
                ->toArray(),
        ]);
    }

    private function command(array $assignment, array $units): UnitRefundCommand
    {
        $returnKey = UnitReturnTerms::fromArray($assignment['request']->terms)->returnKey;
        return new UnitRefundCommand($assignment['original'], 'unit-return-' . $returnKey, $units);
    }
}
