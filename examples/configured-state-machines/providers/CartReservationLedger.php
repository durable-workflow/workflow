<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use PDOStatement;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Internal stock accounting inside the same transaction that records the provider outcome/effect. */
final readonly class CartReservationLedger
{
    public function __construct(
        private PDO $db,
        private Closure $clock,
    ) {
    }

    public static function handles(Request $request): bool
    {
        return CartReservationTerms::selected($request->terms);
    }

    public function admit(Request $request, Status $status): Status
    {
        $this->assertTransaction();
        return $request->action === 'reserve'
            ? $this->reserve($request, $status)
            : $this->release($request, $status);
    }

    public function complete(Request $request, Status $status): void
    {
        $this->assertTransaction();
        $terms = CartReservationTerms::fromArray($request->terms);
        $original = $request->action === 'reserve' ? $request : $this->original($request);
        $row = $this->row($original);
        if ($row === null) {
            throw new LogicException('The original cart admission record is unavailable.');
        }
        if ($request->action === 'release') {
            if ($status === Status::Confirmed && $this->release($request, $status) !== Status::Confirmed) {
                throw new LogicException('An unresolved or physically dispatched reservation cannot be released.');
            }
            return;
        }
        if ($status === Status::Confirmed) {
            if (! (bool) $row['held'] || (bool) $row['released']) {
                throw new LogicException('A late reservation result needs its original admitted stock hold.');
            }
            // An admitted hold can confirm after expiry. It must then be released, not resurrected as a new lease.
            return;
        }
        if ((bool) $row['held']) {
            $this->returnStock($original, $terms);
        }
        $this->query('UPDATE provider_cart_reservations SET held = 0 WHERE id = ?', [$original->identity->key()]);
    }

    public function closeAdmission(Request $request): void
    {
        $this->assertTransaction();
        $this->ensureOriginal($request, CartReservationTerms::fromArray($request->terms));
        $this->query('UPDATE provider_cart_reservations SET admission_closed = 1 WHERE id = ?', [
            $request->identity->key(),
        ]);
    }

    public function bindFulfillment(
        CartReservationDescriptor $reservation,
        ShipmentDescriptor $shipment,
        bool $paidBeforeExpiry = false
    ): void {
        $this->assertTransaction();
        $request = $reservation->reserve;
        $terms = $reservation->terms;
        $physical = ShipmentTerms::fromArray($shipment->booking->terms);
        if (! $request->identity->sameOwner($shipment->booking->identity)
            || $terms->sku !== $physical->sku || $terms->quantity !== $physical->quantity
            || $shipment->deduction === null) {
            throw new LogicException(
                'Cart fulfillment must preserve its original owner, stock and physical deduction.'
            );
        }
        $payload = PortableDescriptor::encode($shipment->toArray());
        $this->assertCompletion($request);
        $prior = $this->query('SELECT * FROM provider_cart_fulfillments WHERE reservation_id = ?', [
            $request->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($prior !== false) {
            $this->fulfillment($request);
            if ($prior['descriptor'] !== $payload) {
                throw new LogicException('Checkout cannot replace its original physical graph.');
            }
            return;
        }
        $this->assertOperation($request, $this->operation($request->identity->key()));
        $row = $this->row($request);
        if ($row === null || ! (bool) $row['held'] || (bool) $row['released'] || (bool) $row['admission_closed']
            || $this->operation($request->identity->key())['status'] !== Status::Confirmed
                ->value
            || ($this->now() >= $terms->expiresAt && ! $paidBeforeExpiry) || $this->hasPhysicalDeduction($request)) {
            throw new LogicException('Checkout requires its confirmed original unexpired stock hold before dispatch.');
        }
        $ledger = new ShipmentLedger($this->db);
        $ledger->register($shipment);
        if ($ledger->observe($shipment->booking)->status !== Status::Unknown || $ledger->events(
            $shipment->booking
        ) !== []) {
            throw new LogicException('The physical graph must be bound before booking or carrier events.');
        }
        $this->query('INSERT INTO provider_cart_fulfillments
            (reservation_id, booking_id, descriptor, descriptor_sha256) VALUES (?, ?, ?, ?)', [
            $request->identity->key(), $shipment->booking->identity->key(), $payload, hash('sha256', $payload),
        ]);
        $this->query('UPDATE provider_cart_reservations SET fulfillment_key = ? WHERE id = ?', [
            $shipment->booking->identity->key(), $request->identity->key(),
        ]);
    }

    public function assertCompletion(Request $request): void
    {
        $this->assertOperation($request, $this->operation($request->identity->key()));
        $original = $request->action === 'reserve' ? $request : $this->original($request);
        $row = $this->row($original);
        if ($row === null || ($request->action === 'reserve' && ! (bool) $row['held'] && ! (bool) $row['released'])
            || ($request->action === 'release' && (! (bool) $row['released'] || (bool) $row['held']))) {
            throw new LogicException('The confirmed cart operation lost its original stock evidence.');
        }
        $this->fulfillment($original);
    }

    public function inspect(CartReservationDescriptor $descriptor): array
    {
        $original = $descriptor->reserve;
        $terms = $descriptor->terms;
        $row = $this->row($original);
        $operation = $this->operation($original->identity->key());
        if (($row === null) !== ($operation === null)) {
            throw new LogicException('The cart inventory and original operation disagree.');
        }
        if ($operation !== null) {
            $this->assertOperation($original, $operation);
        }
        $stock = $this->stock($original, $terms);
        $fulfillment = $row === null ? null : $this->fulfillment($original);
        $now = $this->now();
        return [
            'original' => $descriptor->toArray(),
            'observedAt' => $now,
            'inventoryKnown' => $stock !== null,
            'availableQuantity' => $stock === null ? null : (int) $stock['available'],
            'reservationStatus' => $operation['status'] ?? Status::Unknown->value,
            'heldQuantity' => $row !== null && (bool) $row['held'] ? $terms->quantity : 0,
            'released' => $row !== null && (bool) $row['released'],
            'admissionClosed' => $row !== null && ((bool) $row['admission_closed'] || (bool) ($operation['closed'] ?? false)),
            'deadlinePassed' => $now >= $terms->expiresAt,
            'fulfillmentBooking' => $fulfillment?->booking->identity->key(),
            'eligibleForFulfillment' => $row !== null && (bool) $row['held'] && ! (bool) $row['released']
                && $fulfillment === null
                && ! (bool) $row['admission_closed'] && ! (bool) $operation['closed']
                && $operation['status'] === Status::Confirmed->value && $now < $terms->expiresAt,
        ];
    }

    private function reserve(Request $request, Status $status): Status
    {
        $terms = CartReservationTerms::fromArray($request->terms);
        $row = $this->ensureOriginal($request, $terms);
        $now = $this->now();
        if ($now >= $terms->expiresAt) {
            $this->query('UPDATE provider_cart_reservations SET admission_closed = 1 WHERE id = ?', [
                $request->identity->key(),
            ]);
        }
        if ((bool) $row['admission_closed'] || $now >= $terms->expiresAt || $now < $terms->createdAt) {
            return Status::NotExecuted;
        }
        if (in_array($status, [Status::NotExecuted, Status::Refused], true)) {
            return $status;
        }
        if ($terms->previous !== null && ! $this->predecessorSettled($request, $terms)) {
            return Status::NotExecuted;
        }
        $stock = $this->stock($request, $terms);
        if ($stock === null) {
            // The provider explicitly did not execute this attempt; this is not a claim of zero stock.
            return Status::NotExecuted;
        }
        if ((int) $stock['available'] < $terms->quantity) {
            return Status::Refused;
        }
        if ((bool) $row['held'] || (bool) $row['released']) {
            throw new LogicException('A reservation cannot allocate its original stock twice.');
        }
        $this->query('UPDATE provider_cart_stock SET available = available - ? WHERE id = ?', [
            $terms->quantity, CartInventory::stockKey($request->identity->client, $terms->sku),
        ]);
        $this->query('UPDATE provider_cart_reservations SET held = 1 WHERE id = ?', [$request->identity->key()]);
        return $status;
    }

    private function release(Request $request, Status $status): Status
    {
        $original = $this->original($request);
        $terms = CartReservationTerms::fromArray($request->terms);
        $row = $this->row($original);
        if ($row === null) {
            throw new LogicException('The original cart stock record is unavailable.');
        }
        $this->query('UPDATE provider_cart_reservations SET admission_closed = 1 WHERE id = ?', [
            $original->identity->key(),
        ]);
        if (in_array($status, [Status::NotExecuted, Status::Refused], true)) {
            return $status;
        }
        $operation = $this->operation($original->identity->key());
        if ($operation === null || $operation['status'] !== Status::Confirmed->value || ! (bool) $row['held']) {
            return Status::Unknown;
        }
        if ($status !== Status::Confirmed) {
            return $status;
        }
        $physical = $this->fulfillment($original);
        if ($physical === null && $this->hasPhysicalDeduction($original)) {
            return Status::Unknown;
        }
        if ($physical !== null && ! $this->physicalStockReturned($physical)) {
            return Status::Unknown;
        }
        $this->returnStock($original, $terms);
        $this->query('UPDATE provider_cart_reservations SET held = 0, released = 1 WHERE id = ?', [
            $original->identity->key(),
        ]);
        return Status::Confirmed;
    }

    private function ensureOriginal(Request $request, CartReservationTerms $terms): array
    {
        $row = $this->row($request);
        $operation = $this->operation($request->identity->key());
        if (($row === null) !== ($operation === null)) {
            throw new LogicException('The original cart admission or operation record was lost.');
        }
        if ($row !== null) {
            return $row;
        }
        if ($this->query('SELECT effect_id FROM provider_effects WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetchColumn() !== false) {
            throw new LogicException('A retained cart effect cannot reconstruct its lost admission records.');
        }
        $rootKey = hash('sha256', PortableDescriptor::encode([$request->identity->client, $terms->cartId]));
        $owner = PortableDescriptor::encode(array_intersect_key($request->identity->toArray(), array_flip([
            'client', 'subject', 'generation',
        ])));
        $root = $this->query('SELECT * FROM provider_cart_roots WHERE id = ?', [$rootKey])->fetch(PDO::FETCH_ASSOC);
        if ($root === false) {
            if ($terms->previous !== null) {
                throw new LogicException('A successor cannot reconstruct a missing original cart.');
            }
            $this->query('INSERT INTO provider_cart_roots (id, owner, original_key) VALUES (?, ?, ?)', [
                $rootKey, $owner, $request->identity->key(),
            ]);
        } elseif ($root['owner'] !== $owner || ($terms->previous === null && $root['original_key'] !== $request->identity->key())) {
            throw new LogicException('The original cart owner or reservation identity was substituted.');
        } elseif ($terms->previous !== null && $root['original_key'] !== $terms->previous->key()) {
            throw new LogicException('Only the original cart lease may admit its one late-payment successor.');
        } elseif ($terms->previous === null) {
            throw new LogicException('The original cart ledger cannot be reconstructed after it is lost.');
        }
        if ($terms->previous !== null) {
            $previous = $this->query('SELECT successor FROM provider_cart_reservations WHERE id = ?', [
                $terms->previous->key(),
            ])->fetch(PDO::FETCH_ASSOC);
            if ($previous !== false && $previous['successor'] === $request->identity->key()) {
                throw new LogicException('The admitted successor ledger cannot be reconstructed after it is lost.');
            }
        }
        $this->query('INSERT INTO provider_cart_reservations
            (id, fingerprint, held, released, admission_closed, successor) VALUES (?, ?, 0, 0, 0, NULL)', [
            $request->identity->key(), $request->fingerprint(),
        ]);
        return $this->row($request) ?? throw new LogicException('The original cart admission was not recorded.');
    }

    private function predecessorSettled(Request $request, CartReservationTerms $terms): bool
    {
        $previous = $this->operation($terms->previous?->key() ?? '');
        if ($previous === null) {
            return false;
        }
        $original = Request::fromArray(json_decode($previous['request'], true, flags: JSON_THROW_ON_ERROR));
        $old = CartReservationTerms::fromArray($original->terms);
        $row = $this->row($original);
        if ($original->action !== 'reserve' || ! $original->identity->sameOwner($request->identity)
            || $old->cartId !== $terms->cartId || $old->sku !== $terms->sku || $old->quantity !== $terms->quantity
            || $terms->createdAt < $old->expiresAt || $row === null) {
            throw new LogicException('The successor changed the original cart, stock or reservation lineage.');
        }
        if (! (bool) $row['released'] && ! ((bool) $previous['closed'] && ! (bool) $row['held']
            && in_array($previous['status'], [Status::NotExecuted->value, Status::Refused->value], true))) {
            return false;
        }
        if ($row['successor'] !== null && $row['successor'] !== $request->identity->key()) {
            throw new LogicException('Another original successor already owns this cart reservation step.');
        }
        $this->query('UPDATE provider_cart_reservations SET successor = ? WHERE id = ?', [
            $request->identity->key(), $original->identity->key(),
        ]);
        return true;
    }

    private function original(Request $release): Request
    {
        $row = $this->operation($release->target?->key() ?? '');
        if ($row === null) {
            throw new LogicException('A cart release needs its original provider admission.');
        }
        $original = Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR));
        new CartReservationDescriptor($original, $release);
        $this->assertOperation($original, $row);
        return $original;
    }

    private function row(Request $request): ?array
    {
        $row = $this->query('SELECT * FROM provider_cart_reservations WHERE id = ?', [$request->identity->key()])
            ->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && $row['fingerprint'] !== $request->fingerprint()) {
            throw new LogicException('The original cart lease content changed.');
        }
        if ($row !== false) {
            $terms = CartReservationTerms::fromArray($request->terms);
            $key = hash('sha256', PortableDescriptor::encode([$request->identity->client, $terms->cartId]));
            $root = $this->query('SELECT * FROM provider_cart_roots WHERE id = ?', [$key])->fetch(PDO::FETCH_ASSOC);
            $owner = PortableDescriptor::encode(array_intersect_key($request->identity->toArray(), array_flip([
                'client', 'subject', 'generation',
            ])));
            if ($root === false || $root['owner'] !== $owner
                || $root['original_key'] !== ($terms->previous?->key() ?? $request->identity->key())) {
                throw new LogicException('The original cart ownership record is unavailable or changed.');
            }
        }
        return $row === false ? null : $row;
    }

    private function operation(string $key): ?array
    {
        $row = $this->query('SELECT * FROM provider_operations WHERE id = ?', [$key])->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $original = Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR));
            if ($original->identity->key() !== $key || ! hash_equals($row['fingerprint'], $original->fingerprint())) {
                throw new LogicException('The provider operation identity or content changed.');
            }
        }
        return $row === false ? null : $row;
    }

    private function stock(Request $request, CartReservationTerms $terms): ?array
    {
        $row = $this->query('SELECT * FROM provider_cart_stock WHERE id = ?', [
            CartInventory::stockKey($request->identity->client, $terms->sku),
        ])->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function assertOperation(Request $request, ?array $operation): void
    {
        if ($operation === null || $operation['fingerprint'] !== $request->fingerprint()) {
            throw new LogicException('The cart observation targets another original operation.');
        }
        $effect = $this->query('SELECT effect_id FROM provider_effects WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetchColumn();
        if (($operation['status'] === Status::Confirmed->value && $effect !== 'effect-' . $request->identity->key())
            || ($operation['status'] !== Status::Confirmed->value && $effect !== false)) {
            throw new LogicException('The original cart outcome lost its matching effect evidence.');
        }
    }

    private function returnStock(Request $request, CartReservationTerms $terms): void
    {
        $stock = $this->stock($request, $terms);
        if ($stock === null || (int) $stock['available'] > PHP_INT_MAX - $terms->quantity) {
            throw new LogicException('The original stock receipt is unavailable or exceeds its quantity range.');
        }
        $this->query('UPDATE provider_cart_stock SET available = available + ? WHERE id = ?', [
            $terms->quantity, CartInventory::stockKey($request->identity->client, $terms->sku),
        ]);
    }

    private function hasPhysicalDeduction(Request $original): bool
    {
        $rows = $this->query('SELECT id FROM provider_operations WHERE json_extract(request, \'$.action\') = ?', [
            'deduct-stock',
        ])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $operation = $this->operation($row['id']);
            $deduction = Request::fromArray(json_decode($operation['request'], true, flags: JSON_THROW_ON_ERROR));
            if ($deduction->identity->sameOwner($original->identity) && in_array($operation['status'], [
                Status::Confirmed->value, Status::Processing->value, Status::Unknown->value,
            ], true)) {
                return true;
            }
        }
        return false;
    }

    private function fulfillment(Request $original): ?ShipmentDescriptor
    {
        $reservation = $this->row($original);
        if ($reservation === null) {
            throw new LogicException('The original cart stock record is unavailable.');
        }
        $row = $this->query('SELECT * FROM provider_cart_fulfillments WHERE reservation_id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            if ($reservation['fulfillment_key'] !== null) {
                throw new LogicException('The original cart fulfillment binding was lost.');
            }
            return null;
        }
        if (! hash_equals($row['descriptor_sha256'], hash('sha256', $row['descriptor']))) {
            throw new LogicException('The original cart fulfillment lost its evidence integrity.');
        }
        $graph = ShipmentDescriptor::fromArray(json_decode($row['descriptor'], true, flags: JSON_THROW_ON_ERROR));
        $terms = CartReservationTerms::fromArray($original->terms);
        $physical = ShipmentTerms::fromArray($graph->booking->terms);
        if ($row['booking_id'] !== $graph->booking->identity->key()
            || $reservation['fulfillment_key'] !== $row['booking_id'] || $graph->deduction === null
            || ! $original->identity->sameOwner($graph->booking->identity)
            || $terms->sku !== $physical->sku || $terms->quantity !== $physical->quantity) {
            throw new LogicException('The physical graph targets another original cart or stock quantity.');
        }
        (new ShipmentLedger($this->db))->assertDescriptor($graph);
        return $graph;
    }

    private function physicalStockReturned(ShipmentDescriptor $graph): bool
    {
        $ledger = new ShipmentLedger($this->db);
        $events = $ledger->events($graph->booking);
        $request = $graph->deduction ?? throw new LogicException('The original physical deduction is unavailable.');
        $deduction = $ledger->observe($request);
        $this->query('UPDATE provider_shipments SET handover_closed = 1 WHERE id = ?', [
            $graph->booking->identity->key(),
        ]);
        if (! isset($events['handed-over'])) {
            return (in_array($deduction->status, [Status::NotExecuted, Status::Refused], true)
                    && $deduction->effectId === null)
                || ($deduction->status === Status::Unknown
                    && $this->operation($request->identity->key()) === null);
        }
        $restock = $ledger->observe($graph->restock);
        $quantity = $graph->booking->terms['quantity'];
        return $deduction->status === Status::Confirmed && $deduction->effectId !== null
            && $restock->status === Status::Confirmed && $restock->effectId !== null
            && ($events['received']['quantity'] ?? null) === $quantity
            && ($events['inspected']['accepted'] ?? null) === $quantity
            && ($events['inspected']['saleable'] ?? null) === $quantity;
    }

    private function query(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($bindings);
        return $statement;
    }

    private function assertTransaction(): void
    {
        if (! $this->db->inTransaction()) {
            throw new LogicException('Cart admission must share the original provider effect transaction.');
        }
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (! is_int($now) || $now < 0) {
            throw new LogicException('The provider clock must return a nonnegative integer Unix timestamp.');
        }
        return $now;
    }
}
