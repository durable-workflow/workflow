<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * @internal Customer unit returns after original delivery, inside the provider's own transaction.
 * A unit can belong to one return. The full-parcel return and unit returns of one booking exclude each other.
 */
final readonly class UnitReturnLedger
{
    public function __construct(
        private PDO $db,
        private ShipmentLedger $shipments
    ) {
    }

    public static function handles(Request $request): bool
    {
        return in_array(
            $request->action,
            [UnitReturnTerms::REQUEST, UnitReturnTerms::RESTOCK, UnitReturnTerms::RECOVER],
            true
        );
    }

    public function install(): void
    {
        $this->db->exec(
            'CREATE TABLE provider_unit_returns (id TEXT PRIMARY KEY, booking_id TEXT NOT NULL, units TEXT NOT NULL)'
        );
        $this->db->exec(
            'CREATE TABLE provider_unit_return_units (booking_id TEXT NOT NULL, unit INTEGER NOT NULL, return_id TEXT NOT NULL, PRIMARY KEY (booking_id, unit))'
        );
        $this->db->exec(
            'CREATE TABLE provider_unit_return_events (id TEXT PRIMARY KEY, return_id TEXT NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL, payload_sha256 TEXT NOT NULL, UNIQUE(return_id, kind))'
        );
    }

    public function admit(Request $request, Status $planned): Status
    {
        [$booking, $return, $descriptor] = $this->chain($request);
        $terms = UnitReturnTerms::fromArray($request->terms);
        if ($request->action !== UnitReturnTerms::RESTOCK) {
            $events = $this->shipments->events($booking);
            // A request is permission to send goods back; it is not receipt, acceptance or settlement.
            // Carrier recovery of a parcel damaged before handover is never a delivered-goods return.
            $stage = $request->action === UnitReturnTerms::REQUEST ? isset($events['delivered'])
                : isset($events['damaged']) && ! isset($events['delivered']);
            $permitted = $this->shipments->confirmed($booking) && $stage && ! isset($events['lost'])
                && ! $this->admitted($descriptor->return) && ! $this->overlaps($booking, $request, $terms->units);
            if ($permitted) {
                $this->query(
                    'INSERT INTO provider_unit_returns (id, booking_id, units) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [
                        $request->identity->key(), $booking->identity->key(), PortableDescriptor::encode($terms->units),
                    ]
                );
                foreach ($terms->units as $unit) {
                    $this->query(
                        'INSERT INTO provider_unit_return_units (booking_id, unit, return_id) VALUES (?, ?, ?)',
                        [$booking->identity->key(), $unit, $request->identity->key()]
                    );
                }
            }
        } else {
            $inspection = $this->events($return)['inspected'] ?? null;
            // Only accepted saleable units of this same inspected return can re-enter saleable stock.
            $permitted = $this->shipments->confirmed($return) && $inspection !== null
                && $inspection['saleable'] === $terms->units && $this->shipments->deducted($booking);
        }
        if (! $permitted) {
            return Status::Refused;
        }
        $this->shipments->recordAdmission($request);
        return $planned;
    }

    /**
     * A full-parcel return cannot reuse units that already belong to a customer unit return.
     */
    public function blocksFullReturn(Request $booking): bool
    {
        return $this->query('SELECT id FROM provider_unit_returns WHERE booking_id = ?', [$booking->identity->key()])
            ->fetchColumn() !== false;
    }

    public function assertCompletion(Request $request): void
    {
        $this->chain($request);
        if (! $this->admitted($request)) {
            throw new LogicException('The original unit return admission is unavailable or changed.');
        }
    }

    /**
     * Provider-fixture warehouse input only. Application commands and signal payloads cannot report receipt.
     */
    public function record(
        Request $return,
        string $eventId,
        string $kind,
        array $units,
        array $accepted,
        array $saleable
    ): array {
        PortableDescriptor::identifier($eventId, 'original unit return event');
        if (! in_array($return->action, [UnitReturnTerms::REQUEST, UnitReturnTerms::RECOVER], true)
            || ! $this->shipments->confirmed($return)) {
            throw new LogicException('Warehouse receipt needs its confirmed original unit return.');
        }
        $this->chain($return);
        $terms = UnitReturnTerms::fromArray($return->terms);
        $units = $this->subset($units, $terms->units, false);
        $accepted = $this->subset($accepted, $units, true);
        $saleable = $this->subset($saleable, $accepted, true);
        if (! in_array($kind, ['received', 'inspected'], true) || ($kind === 'received' && $accepted !== [])) {
            throw new LogicException('Receipt alone accepts no unit; inspection decides acceptance.');
        }
        $payload = PortableDescriptor::encode([
            'id' => $eventId,
            'return' => $return->identity->key(),
            'kind' => $kind,
            'units' => $units,
            'accepted' => $accepted,
            'saleable' => $saleable,
        ]);
        $prior = $this->query('SELECT * FROM provider_unit_return_events WHERE id = ?', [$eventId])
            ->fetch(PDO::FETCH_ASSOC);
        if ($prior !== false) {
            $this->decode($prior);
            if ($prior['payload'] !== $payload) {
                throw new LogicException('A unit return event cannot change its original return or contents.');
            }
            return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }
        $events = $this->events($return);
        if (isset($events[$kind])) {
            $old = $events[$kind];
            $new = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            unset($old['id'], $new['id']);
            if ($old !== $new) {
                throw new LogicException('Equivalent unit return events cannot revise an earlier fact.');
            }
            return $events[$kind];
        }
        if ($kind === 'inspected' && ($events['received']['units'] ?? null) !== $units) {
            throw new LogicException('Inspection must cover exactly the units the warehouse received.');
        }
        $this->query(
            'INSERT INTO provider_unit_return_events (id, return_id, kind, payload, payload_sha256) VALUES (?, ?, ?, ?, ?)',
            [$eventId, $return->identity->key(), $kind, $payload, hash('sha256', $payload)]
        );
        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    public function events(Request $return): array
    {
        $events = [];
        foreach ($this->query(
            'SELECT * FROM provider_unit_return_events WHERE return_id = ? ORDER BY kind',
            [$return->identity->key()]
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[$row['kind']] = $this->decode($row);
        }
        return $events;
    }

    /**
     * @return array{Request, Request, ShipmentDescriptor}
     */
    private function chain(Request $request): array
    {
        $return = $request;
        if ($request->action === UnitReturnTerms::RESTOCK) {
            $return = $this->shipments->originalTarget($request);
            $terms = UnitReturnTerms::fromArray($request->terms);
            if (! in_array($return->action, [UnitReturnTerms::REQUEST, UnitReturnTerms::RECOVER], true)
                || UnitReturnTerms::restock($return, $terms->units)->fingerprint() !== $request->fingerprint()) {
                throw new LogicException('Unit restocking must name its original unit return.');
            }
        }
        if (! in_array($return->action, [UnitReturnTerms::REQUEST, UnitReturnTerms::RECOVER], true)) {
            throw new LogicException('The unit return operation is unavailable.');
        }
        $booking = $this->shipments->originalTarget($return);
        $terms = UnitReturnTerms::fromArray($return->terms);
        $factory = $return->action === UnitReturnTerms::REQUEST ? UnitReturnTerms::request(...)
            : UnitReturnTerms::recovery(...);
        if ($booking->action !== 'ship-book'
            || $factory($booking, $terms->returnKey, $terms->units)
                ->fingerprint() !== $return->fingerprint()) {
            throw new LogicException('A unit return must name its original booking, owner and terms.');
        }
        $root = $this->shipments->root($booking);
        $descriptor = ShipmentDescriptor::fromArray(json_decode($root['descriptor'], true, flags: JSON_THROW_ON_ERROR));
        return [$booking, $return, $descriptor];
    }

    private function overlaps(Request $booking, Request $request, array $units): bool
    {
        foreach ($units as $unit) {
            $owner = $this->query(
                'SELECT return_id FROM provider_unit_return_units WHERE booking_id = ? AND unit = ?',
                [$booking->identity->key(), $unit]
            )->fetchColumn();
            if ($owner !== false && $owner !== $request->identity->key()) {
                return true;
            }
        }
        return false;
    }

    private function admitted(Request $request): bool
    {
        $admission = $this->query(
            'SELECT fingerprint FROM provider_shipment_admissions WHERE id = ?',
            [$request->identity->key()]
        )->fetchColumn();
        if ($admission !== false && $admission !== $request->fingerprint()) {
            throw new LogicException('The original physical admission changed content.');
        }
        return $admission !== false;
    }

    private function subset(array $units, array $within, bool $empty): array
    {
        if (! array_is_list($units) || (! $empty && $units === [])) {
            throw new LogicException('Unit return facts need explicit original unit ordinals.');
        }
        foreach ($units as $position => $unit) {
            if (! is_int($unit) || ! in_array($unit, $within, true)
                || ($position > 0 && $unit <= $units[$position - 1])) {
                throw new LogicException('Unit return facts must be ascending units of the same return stage.');
            }
        }
        return $units;
    }

    private function decode(array $row): array
    {
        if (! hash_equals($row['payload_sha256'], hash('sha256', $row['payload']))) {
            throw new LogicException('The original unit return event content was substituted.');
        }
        $value = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        if ($value['id'] !== $row['id'] || $value['return'] !== $row['return_id'] || $value['kind'] !== $row['kind']) {
            throw new LogicException('The original unit return event identity was substituted.');
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
