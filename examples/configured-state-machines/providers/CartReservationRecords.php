<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use PDOStatement;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Internal original-identity records shared by cart admission and stock settlement. */
final readonly class CartReservationRecords
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function row(Request $request): ?array
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

    public function operation(string $key): ?array
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

    public function stock(Request $request, CartReservationTerms $terms): ?array
    {
        $row = $this->query('SELECT * FROM provider_cart_stock WHERE id = ?', [
            CartInventory::stockKey($request->identity->client, $terms->sku),
        ])->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function assertOperation(Request $request, ?array $operation): void
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

    public function returnStock(Request $request, CartReservationTerms $terms): void
    {
        $stock = $this->stock($request, $terms);
        if ($stock === null || (int) $stock['available'] > PHP_INT_MAX - $terms->quantity) {
            throw new LogicException('The original stock receipt is unavailable or exceeds its quantity range.');
        }
        $this->query('UPDATE provider_cart_stock SET available = available + ? WHERE id = ?', [
            $terms->quantity, CartInventory::stockKey($request->identity->client, $terms->sku),
        ]);
    }

    public function query(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($bindings);
        return $statement;
    }

    public function ensureRoot(Request $request, CartReservationTerms $terms): void
    {
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
    }

    public function allocateKnownStock(
        Request $request,
        CartReservationTerms $terms,
        array $row,
        array $stock,
        Status $status
    ): Status {
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
}
