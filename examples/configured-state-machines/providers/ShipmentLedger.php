<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Called inside the provider's own transaction, never the application or DW transaction. */
final readonly class ShipmentLedger
{
    public const CLAIM = 'carrier-claim';

    public const INCIDENTS = ['lost', 'damaged'];

    public const ACTIONS = [
        'ship-book', 'ship-cancel-booking', 'request-return', 'restock', 'deduct-stock',
        UnitReturnTerms::REQUEST, UnitReturnTerms::RESTOCK, UnitReturnTerms::RECOVER, self::CLAIM,
    ];

    public function __construct(
        private PDO $db
    ) {
    }

    public function install(): void
    {
        $this->db->exec(
            'CREATE TABLE provider_shipments (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, descriptor TEXT NOT NULL, descriptor_sha256 TEXT NOT NULL, handover_closed INTEGER NOT NULL)'
        );
        $this->db->exec(
            'CREATE TABLE provider_shipment_events (id TEXT PRIMARY KEY, booking_id TEXT NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL, payload_sha256 TEXT NOT NULL, UNIQUE(booking_id, kind))'
        );
        $this->db->exec('CREATE TABLE provider_shipment_admissions (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL)');
        $this->db->exec(
            'CREATE TABLE provider_carrier_claim_outcomes (claim_id TEXT PRIMARY KEY, payload TEXT NOT NULL, payload_sha256 TEXT NOT NULL)'
        );
        $this->units()
            ->install();
    }

    public function register(ShipmentDescriptor $descriptor): void
    {
        $payload = PortableDescriptor::encode($descriptor->toArray());
        $this->query('INSERT INTO provider_shipments (id, fingerprint, descriptor, descriptor_sha256, handover_closed)
            VALUES (?, ?, ?, ?, 0) ON CONFLICT DO NOTHING', [$descriptor->booking->identity->key(),
            $descriptor->booking->fingerprint(), $payload, $descriptor->fingerprint()]);
        $this->assertDescriptor($descriptor);
    }

    public function assertDescriptor(ShipmentDescriptor $descriptor): void
    {
        $row = $this->root($descriptor->booking);
        if ($row['descriptor_sha256'] !== $descriptor->fingerprint()) {
            throw new LogicException('The original physical shipment graph was substituted.');
        }
    }

    /**
     * A carrier claim for one confirmed incident of the original parcel; its own identity, never a customer remedy.
     */
    public static function claim(Request $booking, string $incident): Request
    {
        if ($booking->action !== 'ship-book') {
            throw new LogicException('A carrier claim must name its original booking.');
        }
        return new Request(new Identity(
            $booking->identity->client,
            $booking->identity->subject,
            $booking->identity->generation,
            'shipment.full.carrier-claim.' . $incident
        ), self::CLAIM, [
            'incident' => $incident,
            'shipment' => $booking->terms,
        ], $booking->identity);
    }

    public static function claimTerms(array $terms): void
    {
        $keys = array_keys($terms);
        sort($keys);
        if ($keys !== ['incident', 'shipment'] || ! in_array($terms['incident'], self::INCIDENTS, true)
            || ! is_array($terms['shipment'])) {
            throw new \InvalidArgumentException('The carrier claim terms are malformed.');
        }
        ShipmentTerms::fromArray($terms['shipment']);
    }

    /**
     * Provider-fixture carrier decision on a filed claim. It never changes the customer remedy.
     */
    public function recordClaimOutcome(Request $claim, string $outcome, int $amountMinor): array
    {
        if ($claim->action !== self::CLAIM || ! $this->confirmed($claim)
            || ! in_array($outcome, ['accepted', 'rejected'], true) || $amountMinor < 0
            || ($outcome === 'rejected' && $amountMinor !== 0)) {
            throw new LogicException('A claim outcome needs its confirmed original claim and a bounded amount.');
        }
        $this->claimBooking($claim);
        $payload = PortableDescriptor::encode([
            'claim' => $claim->identity->key(),
            'outcome' => $outcome,
            'amountMinor' => $amountMinor,
        ]);
        $this->query(
            'INSERT INTO provider_carrier_claim_outcomes (claim_id, payload, payload_sha256) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            [$claim->identity->key(), $payload, hash('sha256', $payload)]
        );
        $stored = $this->claimOutcome($claim);
        if ($stored === null || PortableDescriptor::encode($stored) !== $payload) {
            throw new LogicException('A carrier claim outcome cannot change.');
        }
        return $stored;
    }

    public function claimOutcome(Request $claim): ?array
    {
        $row = $this->query('SELECT * FROM provider_carrier_claim_outcomes WHERE claim_id = ?', [
            $claim->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (! hash_equals($row['payload_sha256'], hash('sha256', $row['payload']))) {
            throw new LogicException('The original carrier claim outcome was substituted.');
        }
        return json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function admit(Request $request, Status $planned): Status
    {
        if ($request->action === self::CLAIM) {
            $booking = $this->claimBooking($request);
            $events = $this->events($booking);
            // A claim needs the carrier's own confirmed incident; a tracking delay is no incident.
            if (! $this->confirmed($booking) || ! isset($events[$request->terms['incident']])
                || isset($events['delivered'])) {
                return Status::Refused;
            }
            $this->recordAdmission($request);
            return $planned;
        }
        if (UnitReturnLedger::handles($request)) {
            return $this->units()
                ->admit($request, $planned);
        }
        if ($request->action === 'ship-book') {
            $this->root($request);
            $this->recordAdmission($request);
            return $planned;
        }
        $booking = $this->booking($request);
        $root = $this->root($booking);
        $this->assertOriginalRequest($request, $root);
        if ($request->action === 'ship-cancel-booking') {
            // An admitted uncertain cancellation permanently fences new handover too.
            $this->query('UPDATE provider_shipments SET handover_closed = 1 WHERE id = ?', [$booking->identity->key()]);
        }
        if (! $this->permitted($request, $booking)) {
            return Status::Refused;
        }
        $this->recordAdmission($request);
        return $planned;
    }

    public function assertCompletion(Request $request): void
    {
        if ($request->action === self::CLAIM) {
            $this->claimBooking($request);
            if ($this->query('SELECT fingerprint FROM provider_shipment_admissions WHERE id = ?', [
                $request->identity->key(),
            ])->fetchColumn() !== $request->fingerprint()) {
                throw new LogicException('The original carrier claim admission is unavailable or changed.');
            }
            return;
        }
        if (UnitReturnLedger::handles($request)) {
            $this->units()
                ->assertCompletion($request);
            return;
        }
        $booking = $request->action === 'ship-book' ? $request : $this->booking($request);
        $root = $this->root($booking);
        $this->assertOriginalRequest($request, $root);
        $admission = $this->query(
            'SELECT fingerprint FROM provider_shipment_admissions WHERE id = ?',
            [$request->identity->key()]
        )->fetchColumn();
        // Later loss or return does not erase an effect admitted at its original physical boundary.
        if ($admission !== $request->fingerprint()) {
            throw new LogicException('The original physical effect admission is unavailable or changed.');
        }
    }

    /**
     * Provider-fixture event ingestion only. Application commands and signal payloads cannot report physical facts.
     */
    public function record(
        ShipmentDescriptor $descriptor,
        string $eventId,
        string $kind,
        int $quantity,
        int $accepted,
        int $saleable
    ): array {
        PortableDescriptor::identifier($eventId, 'original physical provider event');
        $booking = $descriptor->booking;
        $this->assertDescriptor($descriptor);
        $root = $this->root($booking);
        $terms = ShipmentTerms::fromArray($booking->terms);
        $payload = PortableDescriptor::encode([
            'id' => $eventId,
            'booking' => $booking->identity->key(),
            'kind' => $kind,
            'quantity' => $quantity,
            'accepted' => $accepted,
            'saleable' => $saleable,
        ]);
        $prior = $this->query('SELECT * FROM provider_shipment_events WHERE id = ?', [$eventId])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($prior !== false) {
            $this->decode($prior);
            if ($prior['payload'] !== $payload) {
                throw new LogicException('A physical event cannot change its original booking or contents.');
            }
            return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! $this->confirmed($booking) || $quantity <= 0 || $quantity > $terms->quantity
            || $accepted < 0 || $accepted > $quantity || $saleable < 0 || $saleable > $accepted
            || ($kind !== 'inspected' && ($accepted !== 0 || $saleable !== 0))) {
            throw new LogicException('A physical event needs its confirmed original booking and bounded quantities.');
        }
        $events = $this->events($booking);
        if (isset($events[$kind])) {
            $old = $events[$kind];
            unset($old['id']);
            $duplicate = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            unset($duplicate['id']);
            if ($old !== $duplicate) {
                throw new LogicException('Equivalent physical events cannot revise an earlier fact.');
            }
            return $events[$kind];
        }
        $this->assertEvent($descriptor, $kind, $quantity, $root, $events);
        $this->query(
            'INSERT INTO provider_shipment_events (id, booking_id, kind, payload, payload_sha256) VALUES (?, ?, ?, ?, ?)',
            [$eventId, $booking->identity->key(), $kind, $payload, hash('sha256', $payload)]
        );
        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    public function events(Request $booking): array
    {
        $root = $this->query('SELECT * FROM provider_shipments WHERE id = ?', [$booking->identity->key()])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($root === false) {
            return [];
        }
        $this->root($booking);
        $events = [];
        foreach ($this->query(
            'SELECT * FROM provider_shipment_events WHERE booking_id = ? ORDER BY kind',
            [$booking->identity->key()]
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[$row['kind']] = $this->decode($row);
        }
        return $events;
    }

    public function observe(Request $request): Observation
    {
        $row = $this->query('SELECT * FROM provider_operations WHERE id = ?', [$request->identity->key()])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($row !== false && ($row['fingerprint'] !== $request->fingerprint()
            || ! hash_equals($row['fingerprint'], hash('sha256', $row['request'])))) {
            throw new LogicException('The original physical command content was substituted.');
        }
        if ($row !== false && $row['status'] === Status::Confirmed->value) {
            $this->assertCompletion($request);
        }
        $effect = $this->query(
            'SELECT effect_id FROM provider_effects WHERE operation_id = ?',
            [$request->identity->key()]
        )->fetchColumn();
        if ($effect !== false && $effect !== 'effect-' . $request->identity->key()) {
            throw new LogicException('The physical evidence belongs to another original operation.');
        }
        return new Observation(
            $row === false ? Status::Unknown : Status::from($row['status']),
            $effect === false ? null : $effect,
            $row !== false && (bool) $row['closed']
        );
    }

    public function units(): UnitReturnLedger
    {
        return new UnitReturnLedger($this->db, $this);
    }

    /**
     * @internal Same provider transaction only.
     */
    public function recordAdmission(Request $request): void
    {
        $this->query(
            'INSERT INTO provider_shipment_admissions (id, fingerprint) VALUES (?, ?) ON CONFLICT DO NOTHING',
            [$request->identity->key(), $request->fingerprint()]
        );
        if ($this->query(
            'SELECT fingerprint FROM provider_shipment_admissions WHERE id = ?',
            [$request->identity->key()]
        )->fetchColumn() !== $request->fingerprint()) {
            throw new LogicException('The original physical effect admission changed content.');
        }
    }

    /**
     * @internal Same provider transaction only.
     */
    public function deducted(Request $booking): bool
    {
        $root = $this->root($booking);
        $descriptor = ShipmentDescriptor::fromArray(json_decode($root['descriptor'], true, flags: JSON_THROW_ON_ERROR));
        return $descriptor->deduction === null || $this->confirmed($descriptor->deduction);
    }

    /**
     * @internal Same provider transaction only.
     */
    public function originalTarget(Request $request): Request
    {
        $row = $this->query('SELECT * FROM provider_operations WHERE id = ?', [$request->target?->key()])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($row === false || ! hash_equals($row['fingerprint'], hash('sha256', $row['request']))) {
            throw new LogicException('The original physical predecessor is missing or changed.');
        }
        $target = Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR));
        if ($target->identity->toArray() !== $request->target?->toArray()) {
            throw new LogicException('The physical predecessor identity was substituted.');
        }
        return $target;
    }

    /**
     * @internal Same provider transaction only.
     */
    public function confirmed(Request $request): bool
    {
        $row = $this->query('SELECT o.fingerprint, o.status, o.request, e.effect_id FROM provider_operations o
            LEFT JOIN provider_effects e ON e.operation_id = o.id WHERE o.id = ?', [$request->identity->key()])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($row === false) {
            return false;
        }
        if ($row['fingerprint'] !== $request->fingerprint() || ! hash_equals(
            $row['fingerprint'],
            hash('sha256', $row['request'])
        )) {
            throw new LogicException('The original physical operation content was substituted.');
        }
        return $row['status'] === Status::Confirmed->value && $row['effect_id'] === 'effect-' . $request->identity->key();
    }

    /**
     * @internal Same provider transaction only.
     */
    public function root(Request $booking): array
    {
        $row = $this->query('SELECT * FROM provider_shipments WHERE id = ?', [$booking->identity->key()])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($row === false || $row['fingerprint'] !== $booking->fingerprint()
            || ! hash_equals($row['descriptor_sha256'], hash('sha256', $row['descriptor']))) {
            throw new LogicException('The original physical shipment admission is unavailable.');
        }
        return $row;
    }

    private function assertEvent(
        ShipmentDescriptor $descriptor,
        string $kind,
        int $quantity,
        array $root,
        array $events
    ): void {
        $handedOver = isset($events['handed-over']);
        $delivered = isset($events['delivered']);
        $lost = isset($events['lost']);
        $allowed = match ($kind) {
            'handed-over' => ! (bool) $root['handover_closed'],
            'delivered' => $handedOver && ! $lost && ! isset($events['damaged']),
            'lost', 'damaged', 'non-collection' => $handedOver && ! $delivered && ! $lost,
            'received' => $handedOver && ! $lost && $this->confirmed($descriptor->return),
            'inspected' => isset($events['received']) && $quantity === $events['received']['quantity'],
            default => false,
        };
        if (! $allowed) {
            throw new LogicException('The original physical stage does not permit this event.');
        }
        if (in_array($kind, ['handed-over', 'delivered', 'lost', 'damaged', 'non-collection'], true)
            && $quantity !== $descriptor->booking->terms['quantity']) {
            throw new LogicException('This full-parcel milestone must name the original quantity.');
        }
    }

    private function permitted(Request $request, Request $booking): bool
    {
        if (! $this->confirmed($booking)) {
            return false;
        }
        $events = $this->events($booking);
        return match ($request->action) {
            'ship-cancel-booking' => ! isset($events['handed-over']),
            // Units already in a customer unit return cannot also return or restock through the full parcel.
            'request-return' => isset($events['handed-over']) && ! isset($events['lost'])
                && ! $this->units()
                    ->blocksFullReturn($booking),
            'deduct-stock' => isset($events['handed-over']) && ! isset($events['received']),
            'restock' => $this->confirmed($this->originalTarget($request)) && isset($events['inspected'])
                && $events['inspected']['saleable'] === $booking->terms['quantity'] && $this->deducted($booking),
            default => false,
        };
    }

    private function booking(Request $request): Request
    {
        $target = $this->originalTarget($request);
        if ($request->action === 'restock') {
            if ($target->action !== 'request-return' || ! $target->identity->sameOwner($request->identity)
                || $target->terms !== $request->terms) {
                throw new LogicException('Restocking must target its original return.');
            }
            $target = $this->originalTarget($target);
        }
        if ($target->action !== 'ship-book' || ! $target->identity->sameOwner($request->identity)
            || $target->terms !== $request->terms) {
            throw new LogicException('Physical settlement must name its original booking and terms.');
        }
        return $target;
    }

    private function claimBooking(Request $claim): Request
    {
        $booking = $this->originalTarget($claim);
        if ($booking->action !== 'ship-book'
            || self::claim($booking, $claim->terms['incident'])->fingerprint() !== $claim->fingerprint()) {
            throw new LogicException('A carrier claim must name its original booking, owner and terms.');
        }
        $this->root($booking);
        return $booking;
    }

    private function assertOriginalRequest(Request $request, array $root): void
    {
        $descriptor = ShipmentDescriptor::fromArray(json_decode($root['descriptor'], true, flags: JSON_THROW_ON_ERROR));
        foreach ($descriptor->requests() as $original) {
            if ($original->fingerprint() === $request->fingerprint()) {
                return;
            }
        }
        throw new LogicException('The physical command is not in the original shipment graph.');
    }

    private function decode(array $row): array
    {
        if (! hash_equals($row['payload_sha256'], hash('sha256', $row['payload']))) {
            throw new LogicException('The original physical event content was substituted.');
        }
        $value = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        if ($value['id'] !== $row['id'] || $value['booking'] !== $row['booking_id'] || $value['kind'] !== $row['kind']) {
            throw new LogicException('The original physical event identity was substituted.');
        }
        return $value;
    }

    private function query(string $sql, array $bindings): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($bindings);
        return $query;
    }
}
