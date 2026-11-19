<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

/**
 * Host boundary for a same-price exchange of one delivered unit for another SKU. The exchange is a separate
 * native process linked to the original fulfilled Order generation: it returns the original unit under its
 * own return identity, reserves and books a replacement under its own identities, and never rewrites the
 * Order, its delivery evidence or its child histories.
 */
final readonly class ExchangeStore
{
    public const REQUEST_HANDLER = 'provider.exchange.request';

    public const RESTOCK_HANDLER = 'provider.exchange.restock';

    public const RESERVE_HANDLER = 'provider.exchange.reserve';

    public const BOOK_HANDLER = 'provider.exchange.book';

    public const REFUND_HANDLER = 'provider.exchange.refund';

    public const SCOPE_INFIX = '.exchange.';

    /**
     * Return keys under this prefix belong to exchanges; customer unit returns cannot claim them.
     */
    public const RETURN_PREFIX = 'exchange.';

    public const TABLE = 'provider_machine_exchanges';

    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private UnitRefundGateway $refunds,
    ) {
        $refunds->assertProvider($provider);
    }

    public static function id(string $key, string $exchangeKey): string
    {
        return hash('sha256', PortableDescriptor::encode(['unit-exchange-v1', $key, $exchangeKey]));
    }

    public static function instance(string $key, string $exchangeKey): string
    {
        return 'provider-exchange-' . self::id($key, $exchangeKey);
    }

    /**
     * Original units already claimed by an exchange of this Order; read under the Order root lock.
     *
     * @return list<int>
     */
    public static function claimed(ConnectionInterface $db, string $key): array
    {
        if (! $db instanceof Connection) {
            throw new LogicException('Exchange claims need the original application database connection.');
        }
        if (! $db->getSchemaBuilder()->hasTable(self::TABLE)) {
            return [];
        }
        $units = [];
        foreach ($db->table(self::TABLE)->where('root_key', $key)->get() as $row) {
            $units[] = json_decode($row->assignment, true, flags: JSON_THROW_ON_ERROR)['unit'];
        }
        return $units;
    }

    /**
     * Trusted application command. The same key and terms are idempotent; changed terms are refused.
     * Only a same-price exchange for another SKU is selected; a price difference needs its own money policy.
     */
    public function request(string $key, string $exchangeKey, int $unit, string $sku, int $priceMinor): array
    {
        $this->authority->authorize($key, $this->authority->principal);
        PortableDescriptor::identifier($exchangeKey, 'original exchange key');
        if (strlen($exchangeKey) > 32) {
            throw new LogicException('The exchange key is too long for its derived return and replacement keys.');
        }
        [$original, $booking] = app(ReturnStore::class)->original($key);
        $original->ordinals([$unit]);
        // Validates the replacement SKU and every derived replacement identity before admission.
        self::replacementCommands($booking, $exchangeKey, $sku);
        if ($sku === ShipmentTerms::fromArray($booking->terms)->sku) {
            throw new LogicException('An exchange for another size must name another SKU.');
        }
        if ($priceMinor !== $original->amountFor([$unit])) {
            throw new LogicException(
                'Only a same-price exchange is selected; a price difference needs its own policy.'
            );
        }
        $return = UnitReturnTerms::request($booking, self::RETURN_PREFIX . $exchangeKey, [$unit]);
        $assignment = PortableDescriptor::encode([
            'version' => 1,
            'key' => $key,
            'exchangeKey' => $exchangeKey,
            'unit' => $unit,
            'replacementSku' => $sku,
            'priceMinor' => $priceMinor,
            'allocationFingerprint' => $original->fingerprint(),
            'request' => $return->toArray(),
        ]);
        $id = self::id($key, $exchangeKey);
        // Read outside the protected transaction; the original delivery publication never reverts.
        $delivered = $this->authority->subject($key, 'shipment')
->state === 'delivered';
        return $this->authority->db->transaction(function () use (
            $key,
            $exchangeKey,
            $id,
            $assignment,
            $delivered,
            $unit
        ): array {
            $root = $this->authority->db->table('example_machine_subjects')
                ->where('id', Authority::subjectId($key, 'order'))
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($root, $key, 'order');
            $existing = $this->authority->db->table(self::TABLE)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && ($existing->assignment !== $assignment
                || $existing->assignment_sha256 !== hash('sha256', $assignment))) {
                throw new LogicException('The original exchange request cannot change its unit, SKU, price or owner.');
            }
            if ($existing === null) {
                $this->admissible($key, $root, $delivered, $unit);
                $this->authority->db->table(self::TABLE)
                    ->insert([
                        'id' => $id,
                        'root_key' => $key,
                        'exchange_key' => $exchangeKey,
                        'assignment' => $assignment,
                        'assignment_sha256' => hash('sha256', $assignment),
                    ]);
            }
            $row = $this->authority->db->table(self::TABLE)
                ->where('id', $id)
                ->sole();
            if ($row->native_run !== null) {
                return [
                    'accepted' => true,
                    'runId' => $row->native_run,
                    'existing' => true,
                ];
            }
            $started = WorkflowStub::make(UnitExchange::class, self::instance($key, $exchangeKey), Catalogue::NAMESPACE)
                ->attemptStart($key, $exchangeKey, StartOptions::rejectDuplicate());
            if (! $started->accepted() || ! is_string($started->runId())) {
                throw new LogicException('Native exchange admission refused; a foreign run cannot be adopted.');
            }
            $this->authority->db->table(self::TABLE)
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
            throw new LogicException('The recorded exchange arguments differ from original native admission.');
        }
        [$key, $exchangeKey, , $assignment] = $this->actor($actual);
        return [
            'key' => $key,
            'exchangeKey' => $exchangeKey,
            'unit' => $assignment['unit'],
            'nativeRun' => $actual->id,
            'principal' => $this->authority->principal,
            'scope' => $this->scope($key, $exchangeKey, $actual->id)
                ->toArray(),
            'request' => $this->obligation($assignment, 'request')
                ->toArray(),
        ];
    }

    /**
     * Receipt alone never settles. The first inspection-based plan is stored and never changes.
     * An accepted original pins the replacement graph at the provider before any replacement effect.
     */
    public function inspect(WorkflowRun $actual): array
    {
        [$key, $exchangeKey, $row, $assignment] = $this->actor($actual);
        $scope = $this->scope($key, $exchangeKey, $actual->id);
        PostDeliveryRecords::registered(
            'exchange',
            $scope,
            $this->obligation($assignment, 'request'),
            $actual->id,
            observed: true
        );
        if ($row->plan !== null) {
            return PostDeliveryRecords::decode('exchange', $row->plan, $row->plan_sha256);
        }
        $snapshot = $this->provider->shipments()
            ->inspectUnitReturn($assignment['request']);
        $facts = $snapshot['facts'];
        if (! $facts['return_requested']) {
            throw new LogicException('The original exchange return request is not confirmed.');
        }
        if (! $facts['warehouse_inspected']) {
            return [
                'action' => 'wait',
                'unit' => $assignment['unit'],
                'receivedUnits' => $facts['received_units'],
            ];
        }
        $accepted = $facts['accepted_units'] !== [];
        $saleable = $facts['saleable_units'] !== [];
        if ($accepted) {
            $this->provider->shipments()
                ->register($this->replacement($assignment)['graph']);
        }
        $plan = PortableDescriptor::encode([
            'action' => 'settle',
            'unit' => $assignment['unit'],
            'receivedUnits' => $facts['received_units'],
            'acceptedUnits' => $facts['accepted_units'],
            'saleableUnits' => $facts['saleable_units'],
            // Selected policy: a rejected original gets no replacement, restock or refund.
            'discrepancyUnits' => $accepted ? [] : [$assignment['unit']],
            'receiptId' => $snapshot['events']['received']['id'],
            'inspectionId' => $snapshot['events']['inspected']['id'],
            // Only an accepted saleable original returns to saleable stock.
            'restock' => $saleable ? $this->obligation($assignment, 'restock')
                ->toArray() : null,
            'reserve' => $accepted ? $this->obligation($assignment, 'reserve')
                ->toArray() : null,
            'book' => $accepted ? $this->obligation($assignment, 'book')
                ->toArray() : null,
            // Used only after a definitive replacement reservation refusal.
            'refund' => $accepted ? $this->obligation($assignment, 'refund')
                ->toArray() : null,
        ]);
        PostDeliveryRecords::storeOnce($this->authority->db, self::TABLE, $row->id, 'plan', $plan);
        $stored = $this->row($key, $exchangeKey);
        return PostDeliveryRecords::decode('exchange', $stored->plan, $stored->plan_sha256);
    }

    /**
     * Recorded carrier facts of the confirmed replacement booking; never a decision about the original parcel.
     */
    public function track(WorkflowRun $actual): array
    {
        [$key, $exchangeKey, , $assignment] = $this->actor($actual);
        $scope = $this->scope($key, $exchangeKey, $actual->id);
        PostDeliveryRecords::registered(
            'exchange',
            $scope,
            $this->obligation($assignment, 'book'),
            $actual->id,
            observed: true
        );
        if (($this->forward($scope, 'exchange.book')['status'] ?? null) !== 'confirmed') {
            throw new LogicException('Replacement tracking requires its confirmed replacement booking.');
        }
        $events = $this->provider->shipments()
            ->inspect($this->replacement($assignment)['graph'])
            ->toArray()['events'];
        return PostDeliveryRecords::carrierProgress($events);
    }

    /**
     * The protected exchange outcome. Settled, refused and compensated outcomes are immutable; unsettled ones are not stored.
     */
    public function publish(WorkflowRun $actual): array
    {
        [$key, $exchangeKey, $row, $assignment] = $this->actor($actual);
        if ($row->outcome !== null) {
            return PostDeliveryRecords::decode('exchange', $row->outcome, $row->outcome_sha256);
        }
        $scope = $this->scope($key, $exchangeKey, $actual->id);
        PostDeliveryRecords::registered(
            'exchange',
            $scope,
            $this->obligation($assignment, 'request'),
            $actual->id,
            observed: true
        );
        $plan = $row->plan === null ? null : PostDeliveryRecords::decode('exchange', $row->plan, $row->plan_sha256);
        $saleable = $plan['saleableUnits'] ?? [];
        $accepted = ($plan['acceptedUnits'] ?? []) !== [];
        $snapshot = $this->provider->shipments()
            ->inspectUnitReturn(
                $assignment['request'],
                $saleable === [] ? null : UnitReturnTerms::restock($assignment['request'], $saleable)
            );
        $replacement = $this->replacement($assignment);
        $reserved = $this->forward($scope, 'exchange.reserve')['status'] ?? null;
        $booked = $this->forward($scope, 'exchange.book')['status'] ?? null;
        $events = $booked === 'confirmed' ? $this->provider->shipments()
            ->inspect($replacement['graph'])
            ->toArray()['events'] : [];
        $refunded = [
            'confirmedUnits' => [],
            'ownPayoutMinor' => 0,
        ];
        if ($reserved === 'refused') {
            PostDeliveryRecords::registered(
                'exchange',
                $scope,
                $this->obligation($assignment, 'refund'),
                $actual->id,
                observed: true
            );
            $refunded = $this->refunds->lookup($this->command($assignment));
        }
        $restocked = $snapshot['facts']['restocked_units'] === $saleable;
        $compensated = (new ScopeRepository())->metadata($scope)['status'] === 'verified';
        [$status, $resolution] = match (true) {
            $snapshot['observations']['return']['status'] === 'refused' => ['refused', 'return-refused'],
            $plan === null => ['unsettled', 'awaiting-inspection'],
            ! $accepted => ['settled', 'original-rejected'],
            ! $restocked => ['unsettled', 'awaiting-restock'],
            $reserved === 'refused' && $refunded['confirmedUnits'] === [$assignment['unit']] => [
                'settled',
                'refunded-replacement-unavailable',
            ],
            $reserved === 'confirmed' && $booked === 'confirmed' && isset($events['delivered']) => [
                'settled',
                'replaced',
            ],
            $reserved === 'confirmed' && $booked !== null && $booked !== 'confirmed' && $compensated => [
                'compensated',
                'replacement-dispatch-refused',
            ],
            default => ['unsettled', 'replacement-in-progress'],
        };
        $refundedAmount = $refunded['confirmedUnits'] === [] ? 0
            : $assignment['original']->amountFor($refunded['confirmedUnits']);
        $outcome = [
            'version' => 1,
            'exchangeKey' => $exchangeKey,
            'status' => $status,
            'resolution' => $resolution,
            'originalOrderState' => $this->authority->subject($key, 'order')
->state,
            'originalBooking' => $assignment['booking']->identity->key(),
            'returnOperation' => $assignment['request']->identity->key(),
            'unit' => $assignment['unit'],
            'receivedUnits' => $plan['receivedUnits'] ?? $snapshot['facts']['received_units'],
            'acceptedUnits' => $plan['acceptedUnits'] ?? [],
            'saleableUnits' => $saleable,
            'discrepancyUnits' => $plan['discrepancyUnits'] ?? [],
            'restockedUnits' => $snapshot['facts']['restocked_units'],
            'replacementSku' => $assignment['replacementSku'],
            'replacementReservation' => $reserved === null ? null : $replacement['reserve']->identity->key(),
            'replacementReservationStatus' => $reserved,
            'replacementBooking' => $booked === null ? null : $replacement['graph']->booking->identity->key(),
            'replacementBookingStatus' => $booked,
            'replacementHandedOver' => isset($events['handed-over']),
            'replacementDelivered' => isset($events['delivered']),
            'refundedUnits' => $refunded['confirmedUnits'],
            'refundedAmountMinor' => $refundedAmount,
            'refundPayoutMinor' => $refunded['ownPayoutMinor'],
            // Same price: a delivered replacement moves no money; a compensated dispatch leaves the unit amount owed.
            'moneyMovementMinor' => $refundedAmount,
            'refundOwedMinor' => $status === 'compensated' ? $assignment['priceMinor'] : 0,
        ];
        if ($status === 'unsettled') {
            return $outcome;
        }
        $payload = PortableDescriptor::encode($outcome);
        PostDeliveryRecords::storeOnce($this->authority->db, self::TABLE, $row->id, 'outcome', $payload);
        $stored = $this->row($key, $exchangeKey);
        return PostDeliveryRecords::decode('exchange', $stored->outcome, $stored->outcome_sha256);
    }

    /**
     * Authorization callback for every exchange handler, before provider I/O.
     */
    public function operation(OperationContext $context, string $mode): void
    {
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The exchange operation mode is unavailable.');
        }
        $this->authorizeScope($context->scope, $this->authority->principal, $mode);
        [$key, $exchangeKey] = PostDeliveryRecords::parse('exchange', $context->scope, self::SCOPE_INFIX);
        $row = $this->row($key, $exchangeKey);
        $assignment = $this->assignment($row);
        $plan = $row->plan === null ? null : PostDeliveryRecords::decode('exchange', $row->plan, $row->plan_sha256);
        $stage = match ($context->obligation->key) {
            'exchange.request' => 'request',
            'exchange.restock' => ($plan['restock'] ?? null) === null ? null : 'restock',
            'exchange.reserve' => ($plan['reserve'] ?? null) === null ? null : 'reserve',
            'exchange.book' => ($plan['book'] ?? null) === null ? null : 'book',
            'exchange.refund' => ($plan['refund'] ?? null) === null ? null : 'refund',
            default => null,
        };
        $expected = $stage === null ? null : $this->obligation($assignment, $stage);
        if ($expected === null || $context->originalRunId !== $row->native_run
            || $context->obligation->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The exchange operation belongs to another exchange, Order, unit or SKU.');
        }
        PostDeliveryRecords::registered(
            'exchange',
            $context->scope,
            $expected,
            $row->native_run,
            $context->operationId
        );
        if ($mode !== 'forward') {
            return;
        }
        $reserved = $this->forward($context->scope, 'exchange.reserve')['status'] ?? null;
        $refused = match ($stage) {
            'request' => (bool) $this->authority->subject($key, 'order')
                ->cancellation_requested,
            // Dispatch needs the confirmed replacement reservation; a refund needs its definitive refusal.
            'book' => $reserved !== 'confirmed',
            'refund' => $reserved !== 'refused',
            default => false,
        };
        if ($refused) {
            throw new LogicException('The exchange stage is not permitted by its recorded predecessor outcome.');
        }
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        [$key, $exchangeKey] = PostDeliveryRecords::parse('exchange', $scope, self::SCOPE_INFIX);
        $this->authority->authorize($key, $principal);
        if ($scope->application !== Catalogue::APPLICATION || $scope->namespace !== Catalogue::NAMESPACE
            || ! in_array($operation, ['register', 'forward', 'compensate', 'request', 'inspect'], true)) {
            throw new LogicException('The exchange scope is outside the selected host boundary.');
        }
        $row = $this->row($key, $exchangeKey);
        if (! is_string($row->native_run)
            || $scope->toArray() !== $this->scope($key, $exchangeKey, $row->native_run)
                ->toArray()) {
            throw new LogicException('The exchange scope substituted its original native exchange.');
        }
        return $row->assignment_sha256;
    }

    public function scope(string $key, string $exchangeKey, string $run): ScopeIdentity
    {
        return new ScopeIdentity(
            Catalogue::APPLICATION,
            Catalogue::NAMESPACE,
            $key . self::SCOPE_INFIX . $exchangeKey,
            $run
        );
    }

    /**
     * Tests and operators can name the exact provider commands of one exchange.
     *
     * @return array{request: Request, restock: Request, reserve: Request, release: Request, graph: ShipmentDescriptor, refund: UnitRefundCommand}
     */
    public function commands(string $key, string $exchangeKey): array
    {
        $assignment = $this->assignment($this->row($key, $exchangeKey));
        return [
            'request' => $assignment['request'],
            'restock' => UnitReturnTerms::restock($assignment['request'], [$assignment['unit']]),
            ...$this->replacement($assignment),
            'refund' => $this->command($assignment),
        ];
    }

    /**
     * Replacement identities: same original owner, distinct exchange-scoped operations, never an original graph.
     *
     * @return array{reserve: Request, release: Request, graph: ShipmentDescriptor}
     */
    public static function replacementCommands(Request $booking, string $exchangeKey, string $sku): array
    {
        return PostDeliveryRecords::replacement($booking, 'exchange.' . $exchangeKey, $sku, 1);
    }

    private function actor(WorkflowRun $actual): array
    {
        $args = $actual->workflowArguments();
        if ($actual->namespace !== Catalogue::NAMESPACE || $actual->workflow_class !== UnitExchange::class
            || count($args) !== 2 || ! is_string($args[0] ?? null) || ! is_string($args[1] ?? null)) {
            throw new LogicException('The native actor is outside the original unit exchange type.');
        }
        [$key, $exchangeKey] = $args;
        $row = $this->row($key, $exchangeKey);
        if ($row->native_run !== $actual->id || $actual->workflow_instance_id !== self::instance($key, $exchangeKey)) {
            throw new LogicException('The native exchange actor differs from its pinned original admission.');
        }
        return [$key, $exchangeKey, $row, $this->assignment($row)];
    }

    private function row(string $key, string $exchangeKey): object
    {
        $this->authority->authorize($key, $this->authority->principal);
        $row = $this->authority->db->table(self::TABLE)
            ->where('id', self::id($key, $exchangeKey))
            ->first();
        if ($row === null || $row->root_key !== $key || $row->exchange_key !== $exchangeKey
            || ! hash_equals($row->assignment_sha256, hash('sha256', $row->assignment))) {
            throw new LogicException('The original exchange assignment is unavailable or changed.');
        }
        return $row;
    }

    /**
     * @return array{unit: int, replacementSku: string, priceMinor: int, request: Request, booking: Request, original: OriginalUnitAmounts, exchangeKey: string}
     */
    private function assignment(object $row): array
    {
        $value = json_decode($row->assignment, true, flags: JSON_THROW_ON_ERROR);
        [$original, $booking] = app(ReturnStore::class)->original($row->root_key);
        $request = UnitReturnTerms::request($booking, self::RETURN_PREFIX . $row->exchange_key, [$value['unit']]);
        if ($value['key'] !== $row->root_key || $value['exchangeKey'] !== $row->exchange_key
            || $value['allocationFingerprint'] !== $original->fingerprint()
            || $value['priceMinor'] !== $original->amountFor([$value['unit']])
            || Request::fromArray($value['request'])->fingerprint() !== $request->fingerprint()) {
            throw new LogicException('The exchange assignment substituted its original booking, unit or price.');
        }
        return [
            'unit' => $value['unit'],
            'replacementSku' => $value['replacementSku'],
            'priceMinor' => $value['priceMinor'],
            'exchangeKey' => $row->exchange_key,
            'request' => $request,
            'booking' => $booking,
            'original' => $original,
        ];
    }

    private function replacement(array $assignment): array
    {
        return self::replacementCommands(
            $assignment['booking'],
            $assignment['exchangeKey'],
            $assignment['replacementSku']
        );
    }

    private function admissible(string $key, object $root, bool $delivered, int $unit): void
    {
        if ($root->state !== 'fulfilled' || (bool) $root->cancellation_requested || ! $delivered) {
            throw new LogicException('An exchange requires its original fulfilled and delivered Order generation.');
        }
        $claimed = self::claimed($this->authority->db, $key);
        foreach ($this->authority->db->table('provider_machine_returns')->where('root_key', $key)->get() as $other) {
            $claimed = [...$claimed, ...json_decode($other->assignment, true, flags: JSON_THROW_ON_ERROR)['units']];
        }
        if (in_array($unit, $claimed, true)) {
            throw new LogicException('An original unit already belongs to another return or exchange.');
        }
    }

    private function obligation(array $assignment, string $stage): Obligation
    {
        $replacement = $this->replacement($assignment);
        [$handler, $input] = match ($stage) {
            'request' => [self::REQUEST_HANDLER, [
                'version' => 1,
                'request' => $assignment['request']->toArray(),
            ]],
            'restock' => [self::RESTOCK_HANDLER, [
                'version' => 1,
                'request' => UnitReturnTerms::restock($assignment['request'], [$assignment['unit']])->toArray(),
            ]],
            'reserve' => [self::RESERVE_HANDLER, [
                'forward' => $replacement['reserve']->toArray(),
                'compensation' => $replacement['release']->toArray(),
            ]],
            'book' => [self::BOOK_HANDLER, $replacement['graph']->toArray()],
            'refund' => [self::REFUND_HANDLER, [
                'version' => 1,
                'refund' => $this->command($assignment)
                    ->toArray(),
            ]],
            default => throw new LogicException('The exchange stage is unavailable.'),
        };
        return new Obligation('exchange.' . $stage, $handler, '1', $input);
    }

    private function command(array $assignment): UnitRefundCommand
    {
        return new UnitRefundCommand(
            $assignment['original'],
            'unit-exchange-' . $assignment['exchangeKey'],
            [$assignment['unit']]
        );
    }

    /**
     * The recorded forward value of one exchange obligation, or null before it was observed.
     */
    private function forward(ScopeIdentity $scope, string $key): ?array
    {
        return PostDeliveryRecords::forward($scope, $key);
    }
}
