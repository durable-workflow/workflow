<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentLedger;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentTerms;
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
 * Host boundary for a carrier-confirmed loss or transport damage of the original parcel before recipient
 * handover. The original full Shipment admits one separate native remedy process per Order generation. It
 * settles the trusted customer remedy, files the carrier claim and recovers damaged goods under its own
 * identities; it never rewrites the original handover, deduction or child histories.
 */
final readonly class IncidentStore
{
    public const REFUND_HANDLER = 'provider.incident.refund';

    public const RESERVE_HANDLER = 'provider.incident.reserve';

    public const BOOK_HANDLER = 'provider.incident.book';

    public const CLAIM_HANDLER = 'provider.incident.claim';

    public const RECOVER_HANDLER = 'provider.incident.recover';

    public const RESTOCK_HANDLER = 'provider.incident.restock';

    public const SCOPE_SUFFIX = '.incident';

    public const RECOVERY_KEY = 'transit-damage';

    public const TABLE = 'provider_machine_incidents';

    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private UnitRefundGateway $refunds,
    ) {
        $refunds->assertProvider($provider);
    }

    public static function instance(string $key): string
    {
        return 'provider-incident-' . hash('sha256', PortableDescriptor::encode(['transit-incident-v1', $key]));
    }

    /**
     * Called by the original full Shipment while it waits for recipient delivery. Only the carrier's own
     * confirmed loss or damage event admits a remedy; a tracking delay returns no incident.
     */
    public function admit(WorkflowRun $shipment): array
    {
        [$actor, $plan, , $graph] = (new ShipmentAuthority($this->authority))->rows($shipment);
        $key = $actor->root_key;
        $this->authority->transit->policy($key);
        $existing = $this->authority->db->table(self::TABLE)
            ->where('id', $key)
            ->first();
        $kind = $existing === null ? $this->confirmedIncident($graph) : $this->assignment($existing)['kind'];
        if ($kind === null || $this->authority->subject($key, 'shipment')->state !== 'in-transit') {
            return [
                'incident' => null,
            ];
        }
        $assignment = PortableDescriptor::encode([
            'version' => 1,
            'key' => $key,
            'kind' => $kind,
            'policy' => $this->authority->transit->policy($key),
            'shipmentRun' => $shipment->id,
            'booking' => $graph->booking->identity->key(),
            'event' => $this->provider->shipments()
                ->inspect($graph)
                ->toArray()['events'][$kind]['id'],
        ]);
        $run = $this->authority->db->transaction(function () use ($key, $assignment): ?string {
            $root = $this->authority->db->table('example_machine_subjects')
                ->where('id', Authority::subjectId($key, 'order'))
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($root, $key, 'order');
            $row = $this->authority->db->table(self::TABLE)
                ->where('id', $key)
                ->lockForUpdate()
                ->first();
            if ($row !== null && ($row->assignment !== $assignment
                || ! hash_equals($row->assignment_sha256, hash('sha256', $assignment)))) {
                throw new LogicException('The original transit incident cannot change its kind, policy or owner.');
            }
            if ($row === null) {
                if ((bool) $root->cancellation_requested || $root->state !== 'ready') {
                    // A business cancellation that started first keeps its own settlement route.
                    return null;
                }
                $this->authority->db->table(self::TABLE)
                    ->insert([
                        'id' => $key,
                        'root_key' => $key,
                        'incident' => json_decode($assignment, true, flags: JSON_THROW_ON_ERROR)['kind'],
                        'assignment' => $assignment,
                        'assignment_sha256' => hash('sha256', $assignment),
                    ]);
                $row = $this->authority->db->table(self::TABLE)
                    ->where('id', $key)
                    ->sole();
            }
            if ($row->native_run !== null) {
                return $row->native_run;
            }
            $started = WorkflowStub::make(TransitIncident::class, self::instance($key), Catalogue::NAMESPACE)
                ->attemptStart($key, StartOptions::rejectDuplicate());
            if (! $started->accepted() || ! is_string($started->runId())) {
                throw new LogicException('Native incident admission refused; a foreign run cannot be adopted.');
            }
            $this->authority->db->table(self::TABLE)
                ->where('id', $key)
                ->update([
                    'native_run' => $started->runId(),
                ]);
            return $started->runId();
        });
        if ($run === null) {
            return [
                'incident' => null,
            ];
        }
        $this->notify($plan->rootRun, 'transit-incident-v1', $run);
        return [
            'incident' => $kind,
            'runId' => $run,
        ];
    }

    public function initialize(WorkflowRun $actual, array $arguments): array
    {
        if (PortableDescriptor::encode($arguments) !== PortableDescriptor::encode($actual->workflowArguments())) {
            throw new LogicException('The recorded incident arguments differ from original native admission.');
        }
        [$key, , $assignment] = $this->actor($actual);
        $stages = $assignment['policy'] === TransitPolicy::REFUND ? ['refund'] : ['reserve', 'book', 'refund'];
        $stages[] = 'claim';
        if ($assignment['kind'] === 'damaged') {
            $stages[] = 'recover';
        }
        if ($assignment['policy'] === TransitPolicy::REPLACE) {
            // Pin the replacement graph at the provider before any replacement effect.
            $this->provider->shipments()
                ->register($this->replacement($assignment)['graph']);
        }
        $obligations = [];
        foreach ($stages as $stage) {
            $obligations[$stage] = $this->obligation($assignment, $stage)
                ->toArray();
        }
        return [
            'key' => $key,
            'kind' => $assignment['kind'],
            'policy' => $assignment['policy'],
            'nativeRun' => $actual->id,
            'principal' => $this->authority->principal,
            'scope' => $this->scope($key, $actual->id)
                ->toArray(),
            'obligations' => $obligations,
        ];
    }

    /**
     * Records the settled customer remedy once and wakes the original Order. Claim and recovery never gate it.
     */
    public function settle(WorkflowRun $actual): array
    {
        [$key, $row, $assignment] = $this->actor($actual);
        if ($row->remedy === null) {
            $remedy = $this->remedyFacts($assignment, $this->scope($key, $actual->id));
            if ($remedy === null) {
                return [
                    'settled' => false,
                ];
            }
            $payload = PortableDescriptor::encode($remedy);
            PostDeliveryRecords::storeOnce($this->authority->db, self::TABLE, $key, 'remedy', $payload);
            $row = $this->row($key);
        }
        $remedy = $this->decode($row->remedy, $row->remedy_sha256);
        $this->notify($this->authority->plan($key)->rootRun, 'transit-remedy-v1', $actual->id);
        return [
            'settled' => true,
            'resolution' => $remedy['resolution'],
        ];
    }

    /**
     * Recorded carrier facts of the confirmed replacement booking; never a decision about the original parcel.
     */
    public function track(WorkflowRun $actual): array
    {
        [$key, , $assignment] = $this->actor($actual);
        $scope = $this->scope($key, $actual->id);
        $this->registered($scope, $this->obligation($assignment, 'book'), $actual->id, observed: true);
        if ((PostDeliveryRecords::forward($scope, 'incident.book')['status'] ?? null) !== 'confirmed') {
            throw new LogicException('Replacement tracking requires its confirmed replacement booking.');
        }
        $events = $this->provider->shipments()
            ->inspect($this->replacement($assignment)['graph'])
            ->toArray()['events'];
        return PostDeliveryRecords::carrierProgress($events);
    }

    /**
     * Warehouse receipt alone never makes recovered goods saleable. The first inspection-based plan is stored.
     */
    public function inspect(WorkflowRun $actual): array
    {
        [$key, $row, $assignment] = $this->actor($actual);
        $scope = $this->scope($key, $actual->id);
        $this->registered($scope, $this->obligation($assignment, 'recover'), $actual->id, observed: true);
        if ($row->plan !== null) {
            return $this->decode($row->plan, $row->plan_sha256);
        }
        $recover = $this->recovery($assignment);
        $snapshot = $this->provider->shipments()
            ->inspectUnitReturn($recover);
        $facts = $snapshot['facts'];
        if (! $facts['return_requested']) {
            return [
                'action' => 'unrecoverable',
                'status' => $snapshot['observations']['return']['status'],
            ];
        }
        if (! $facts['warehouse_inspected']) {
            return [
                'action' => 'wait',
                'receivedUnits' => $facts['received_units'],
            ];
        }
        $saleable = $facts['saleable_units'];
        $plan = PortableDescriptor::encode([
            'action' => 'settle',
            'receivedUnits' => $facts['received_units'],
            'acceptedUnits' => $facts['accepted_units'],
            'saleableUnits' => $saleable,
            'receiptId' => $snapshot['events']['received']['id'],
            'inspectionId' => $snapshot['events']['inspected']['id'],
            // Only accepted saleable recovered units re-enter saleable stock.
            'restock' => $saleable === [] ? null : $this->obligation($assignment, 'restock', $saleable)
                ->toArray(),
        ]);
        PostDeliveryRecords::storeOnce($this->authority->db, self::TABLE, $key, 'plan', $plan);
        $stored = $this->row($key);
        return $this->decode($stored->plan, $stored->plan_sha256);
    }

    /**
     * The protected incident outcome. Only a settled customer remedy is stored; it never changes afterwards.
     */
    public function publish(WorkflowRun $actual): array
    {
        [$key, $row, $assignment] = $this->actor($actual);
        if ($row->outcome !== null) {
            return $this->decode($row->outcome, $row->outcome_sha256);
        }
        $scope = $this->scope($key, $actual->id);
        $remedy = $row->remedy === null ? null : $this->decode($row->remedy, $row->remedy_sha256);
        $plan = $row->plan === null ? null : $this->decode($row->plan, $row->plan_sha256);
        $claim = PostDeliveryRecords::forward($scope, 'incident.claim')['status'] ?? null;
        $recovery = null;
        if ($assignment['kind'] === 'damaged') {
            $saleable = $plan['saleableUnits'] ?? [];
            $recovery = $this->provider->shipments()
                ->inspectUnitReturn(
                    $this->recovery($assignment),
                    $saleable === [] ? null : UnitReturnTerms::restock($this->recovery($assignment), $saleable)
                );
        }
        $outcome = [
            'version' => 1,
            'status' => $remedy === null ? 'unsettled' : 'settled',
            'incident' => $assignment['kind'],
            'policy' => $assignment['policy'],
            'resolution' => $remedy['resolution'] ?? 'remedy-in-progress',
            'originalOrderState' => $this->authority->subject($key, 'order')
->state,
            'originalShipmentState' => $this->authority->subject($key, 'shipment')
->state,
            'originalBooking' => $assignment['booking']->identity->key(),
            'carrierEvent' => $assignment['event'],
            ...($remedy ?? []),
            'claim' => $this->claimRequest($assignment)
                ->identity->key(),
            'claimStatus' => $claim,
            'recovery' => $recovery === null ? null : $this->recovery($assignment)
                ->identity->key(),
            'recoveryStatus' => $recovery['observations']['return']['status'] ?? null,
            'receivedUnits' => $plan['receivedUnits'] ?? ($recovery['facts']['received_units'] ?? []),
            'acceptedUnits' => $plan['acceptedUnits'] ?? [],
            'saleableUnits' => $plan['saleableUnits'] ?? [],
            'restockedUnits' => $recovery['facts']['restocked_units'] ?? [],
        ];
        if ($remedy === null) {
            return $outcome;
        }
        $payload = PortableDescriptor::encode($outcome);
        PostDeliveryRecords::storeOnce($this->authority->db, self::TABLE, $key, 'outcome', $payload);
        $stored = $this->row($key);
        return $this->decode($stored->outcome, $stored->outcome_sha256);
    }

    /**
     * Recorded facts for the original Order's remedy transition; read only through its assessment and wait.
     */
    public function remedy(WorkflowRun $order): array
    {
        [$actor, , $plan] = $this->authority->actor($order);
        if ($actor->role !== 'order' || $plan->rootRun !== $order->id) {
            throw new LogicException('The transit remedy belongs to its original Order.');
        }
        $this->authority->transit->policy($actor->root_key);
        $row = $this->authority->db->table(self::TABLE)
            ->where('id', $actor->root_key)
            ->first();
        if ($row === null || $row->native_run === null) {
            return [
                'incident' => false,
                'settled' => false,
            ];
        }
        $row = $this->row($actor->root_key);
        $remedy = $row->remedy === null ? null : $this->decode($row->remedy, $row->remedy_sha256);
        return [
            'incident' => true,
            'settled' => $remedy !== null,
            'remedyId' => $remedy === null ? null : hash('sha256', $row->remedy),
        ];
    }

    /**
     * The carrier claim filed by this incident and the carrier's later decision, if any. Never a customer remedy.
     */
    public function claim(string $key): array
    {
        $assignment = $this->assignment($this->row($key));
        $claim = $this->claimRequest($assignment);
        return [
            'claim' => $claim->identity->key(),
            'status' => $this->provider->lookup($claim)
                ->status->value,
            'carrierDecision' => $this->provider->shipments()
                ->claimOutcome($claim),
        ];
    }

    /**
     * Authorization callback for every incident handler, before provider I/O.
     */
    public function operation(OperationContext $context, string $mode): void
    {
        if (! in_array($mode, ['forward', 'compensate'], true)) {
            throw new LogicException('The incident operation mode is unavailable.');
        }
        $this->authorizeScope($context->scope, $this->authority->principal, $mode);
        $key = $this->parse($context->scope);
        $row = $this->row($key);
        $assignment = $this->assignment($row);
        $plan = $row->plan === null ? null : $this->decode($row->plan, $row->plan_sha256);
        $stages = $assignment['policy'] === TransitPolicy::REFUND ? ['refund'] : ['reserve', 'book', 'refund'];
        $stages[] = 'claim';
        if ($assignment['kind'] === 'damaged') {
            $stages[] = 'recover';
        }
        $stage = substr($context->obligation->key, strlen('incident.'));
        $expected = null;
        if ($stage === 'restock' && ($plan['restock'] ?? null) !== null) {
            $expected = $this->obligation($assignment, 'restock', $plan['saleableUnits']);
        } elseif (str_starts_with($context->obligation->key, 'incident.') && in_array($stage, $stages, true)) {
            $expected = $this->obligation($assignment, $stage);
        }
        if ($expected === null || $context->originalRunId !== $row->native_run
            || $context->obligation->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The incident operation belongs to another incident, Order, policy or unit.');
        }
        $this->registered($context->scope, $expected, $row->native_run, $context->operationId);
        if ($mode !== 'forward') {
            return;
        }
        $reserved = PostDeliveryRecords::forward($context->scope, 'incident.reserve')['status'] ?? null;
        $refused = match ($stage) {
            // Dispatch needs the confirmed replacement reservation; a fallback refund needs its definitive refusal.
            'book' => $reserved !== 'confirmed',
            'refund' => $assignment['policy'] === TransitPolicy::REPLACE && $reserved !== 'refused',
            default => false,
        };
        if ($refused) {
            throw new LogicException('The incident stage is not permitted by its recorded predecessor outcome.');
        }
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        $key = $this->parse($scope);
        $this->authority->authorize($key, $principal);
        if ($scope->application !== Catalogue::APPLICATION || $scope->namespace !== Catalogue::NAMESPACE
            || ! in_array($operation, ['register', 'forward', 'compensate', 'request', 'inspect'], true)) {
            throw new LogicException('The incident scope is outside the selected host boundary.');
        }
        $row = $this->row($key);
        if (! is_string($row->native_run) || $scope->toArray() !== $this->scope($key, $row->native_run)->toArray()) {
            throw new LogicException('The incident scope substituted its original native incident.');
        }
        return $row->assignment_sha256;
    }

    public function scope(string $key, string $run): ScopeIdentity
    {
        return new ScopeIdentity(Catalogue::APPLICATION, Catalogue::NAMESPACE, $key . self::SCOPE_SUFFIX, $run);
    }

    /**
     * Tests and operators can name the exact provider commands of one incident.
     *
     * @return array{refund: UnitRefundCommand, reserve: Request, release: Request, graph: ShipmentDescriptor, claim: Request, recover: Request}
     */
    public function commands(string $key, string $kind): array
    {
        [$original, $booking] = app(ReturnStore::class)->original($key);
        $assignment = [
            'kind' => $kind,
            'booking' => $booking,
            'original' => $original,
        ];
        return [
            'refund' => $this->command($assignment),
            ...$this->replacement($assignment),
            'claim' => $this->claimRequest($assignment),
            'recover' => $this->recovery($assignment),
        ];
    }

    private function confirmedIncident(ShipmentDescriptor $graph): ?string
    {
        $events = $this->provider->shipments()
            ->inspect($graph)
            ->toArray()['events'];
        if (! isset($events['handed-over']) || isset($events['delivered'])) {
            return null;
        }
        foreach (ShipmentLedger::INCIDENTS as $kind) {
            if (isset($events[$kind])) {
                return $kind;
            }
        }
        return null;
    }

    private function notify(string $rootRun, string $kind, string $run): void
    {
        WorkflowStub::loadRun($rootRun, Catalogue::NAMESPACE)
            ->withRequestId(hash('sha256', PortableDescriptor::encode([$kind, $rootRun, $run])))
            ->attemptSignal('physical-milestone');
    }

    /**
     * Settled customer remedy, read from this incident's own recorded obligations and provider facts.
     */
    private function remedyFacts(array $assignment, ScopeIdentity $scope): ?array
    {
        $units = range(0, count($assignment['original']->amounts) - 1);
        $refunded = null;
        $reserved = PostDeliveryRecords::forward($scope, 'incident.reserve')['status'] ?? null;
        if ($assignment['policy'] === TransitPolicy::REFUND || $reserved === 'refused') {
            $this->registered($scope, $this->obligation($assignment, 'refund'), $scope->generation, observed: true);
            $refunded = $this->refunds->lookup($this->command($assignment));
            if ($refunded['confirmedUnits'] !== $units) {
                return null;
            }
            return [
                'resolution' => $reserved === 'refused' ? 'refunded-replacement-unavailable' : 'refunded',
                'refundedUnits' => $refunded['confirmedUnits'],
                'refundedAmountMinor' => $assignment['original']->amountFor($refunded['confirmedUnits']),
                'refundPayoutMinor' => $refunded['ownPayoutMinor'],
                'replacementReservation' => $reserved === null ? null : $this->replacement(
                    $assignment
                )['reserve']->identity->key(),
                'replacementBooking' => null,
            ];
        }
        if ($reserved !== 'confirmed' || (PostDeliveryRecords::forward(
            $scope,
            'incident.book'
        )['status'] ?? null) !== 'confirmed') {
            return null;
        }
        $replacement = $this->replacement($assignment);
        $events = $this->provider->shipments()
            ->inspect($replacement['graph'])
            ->toArray()['events'];
        if (! isset($events['delivered'])) {
            return null;
        }
        return [
            'resolution' => 'replaced',
            'refundedUnits' => [],
            'refundedAmountMinor' => 0,
            'refundPayoutMinor' => 0,
            'replacementReservation' => $replacement['reserve']->identity->key(),
            'replacementBooking' => $replacement['graph']->booking->identity->key(),
        ];
    }

    private function actor(WorkflowRun $actual): array
    {
        $args = $actual->workflowArguments();
        if ($actual->namespace !== Catalogue::NAMESPACE || $actual->workflow_class !== TransitIncident::class
            || count($args) !== 1 || ! is_string($args[0] ?? null)) {
            throw new LogicException('The native actor is outside the original transit incident type.');
        }
        $key = $args[0];
        $row = $this->row($key);
        if ($row->native_run !== $actual->id || $actual->workflow_instance_id !== self::instance($key)) {
            throw new LogicException('The native incident actor differs from its pinned original admission.');
        }
        return [$key, $row, $this->assignment($row)];
    }

    private function row(string $key): object
    {
        $this->authority->authorize($key, $this->authority->principal);
        $row = $this->authority->db->table(self::TABLE)
            ->where('id', $key)
            ->first();
        if ($row === null || $row->root_key !== $key
            || ! hash_equals($row->assignment_sha256, hash('sha256', $row->assignment))) {
            throw new LogicException('The original incident assignment is unavailable or changed.');
        }
        return $row;
    }

    /**
     * @return array{kind: string, policy: string, event: string, booking: Request, original: \DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts}
     */
    private function assignment(object $row): array
    {
        $value = json_decode($row->assignment, true, flags: JSON_THROW_ON_ERROR);
        [$original, $booking] = app(ReturnStore::class)->original($row->root_key);
        if ($value['key'] !== $row->root_key || $value['booking'] !== $booking->identity->key()
            || $value['policy'] !== $this->authority->transit->policy($row->root_key)
            || ! in_array($value['kind'], ShipmentLedger::INCIDENTS, true)) {
            throw new LogicException('The incident assignment substituted its original booking or policy.');
        }
        return [
            'kind' => $value['kind'],
            'policy' => $value['policy'],
            'event' => $value['event'],
            'booking' => $booking,
            'original' => $original,
        ];
    }

    /**
     * @return array{reserve: Request, release: Request, graph: ShipmentDescriptor}
     */
    private function replacement(array $assignment): array
    {
        $original = ShipmentTerms::fromArray($assignment['booking']->terms);
        return PostDeliveryRecords::replacement(
            $assignment['booking'],
            'transit.' . $assignment['kind'],
            $original->sku,
            $original->quantity
        );
    }

    private function claimRequest(array $assignment): Request
    {
        return ShipmentLedger::claim($assignment['booking'], $assignment['kind']);
    }

    private function recovery(array $assignment): Request
    {
        return UnitReturnTerms::recovery(
            $assignment['booking'],
            self::RECOVERY_KEY,
            range(0, count($assignment['original']->amounts) - 1)
        );
    }

    private function command(array $assignment): UnitRefundCommand
    {
        // Every original captured unit; the gateway's prior-repayment accounting prevents a second payout.
        return new UnitRefundCommand(
            $assignment['original'],
            'transit-' . $assignment['kind'],
            range(0, count($assignment['original']->amounts) - 1)
        );
    }

    private function obligation(array $assignment, string $stage, array $saleable = []): Obligation
    {
        $replacement = $stage === 'reserve' || $stage === 'book' ? $this->replacement($assignment) : null;
        [$handler, $input] = match ($stage) {
            'refund' => [self::REFUND_HANDLER, [
                'version' => 1,
                'refund' => $this->command($assignment)
                    ->toArray(),
            ]],
            'reserve' => [self::RESERVE_HANDLER, [
                'forward' => $replacement['reserve']->toArray(),
                'compensation' => $replacement['release']->toArray(),
            ]],
            'book' => [self::BOOK_HANDLER, $replacement['graph']->toArray()],
            'claim' => [self::CLAIM_HANDLER, [
                'version' => 1,
                'request' => $this->claimRequest($assignment)
                    ->toArray(),
            ]],
            'recover' => [self::RECOVER_HANDLER, [
                'version' => 1,
                'request' => $this->recovery($assignment)
                    ->toArray(),
            ]],
            'restock' => [self::RESTOCK_HANDLER, [
                'version' => 1,
                'request' => UnitReturnTerms::restock($this->recovery($assignment), $saleable)->toArray(),
            ]],
            default => throw new LogicException('The incident stage is unavailable.'),
        };
        return new Obligation('incident.' . $stage, $handler, '1', $input);
    }

    private function registered(
        ScopeIdentity $scope,
        Obligation $expected,
        string $run,
        ?string $operationId = null,
        bool $observed = false
    ): void {
        PostDeliveryRecords::registered('incident', $scope, $expected, $run, $operationId, $observed);
    }

    private function parse(ScopeIdentity $scope): string
    {
        [$key, $rest] = PostDeliveryRecords::parse('incident', $scope, self::SCOPE_SUFFIX);
        if ($rest !== '') {
            throw new LogicException('The scope is not an original transit incident scope.');
        }
        return $key;
    }

    private function decode(string $payload, ?string $sha256): array
    {
        return PostDeliveryRecords::decode('incident', $payload, $sha256);
    }
}
