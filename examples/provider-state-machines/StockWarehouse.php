<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Support\WorkflowFiberContext;

/** Disposable warehouse simulator: physical counters and original command receipts commit together. */
final readonly class StockWarehouse
{
    private const RESERVATION_ROW = 'SELECT * FROM allocation_reservations WHERE id = ?';

    public function __construct(
        private PDO $db
    ) {
        $this->assertHost();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('The disposable warehouse requires its own SQLite provider database.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void
    {
        $this->assertHost();
        $this->db->exec('CREATE TABLE IF NOT EXISTS allocation_stock (
            id TEXT PRIMARY KEY, initial_quantity INTEGER NOT NULL, available INTEGER NOT NULL,
            damaged INTEGER NOT NULL, missing INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS allocation_reservations (
            id TEXT PRIMARY KEY, descriptor TEXT NOT NULL, release_descriptor TEXT NOT NULL,
            allocation TEXT NOT NULL, revision INTEGER NOT NULL, closed INTEGER NOT NULL, result TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS allocation_events (
            id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, result TEXT NOT NULL, result_sha256 TEXT NOT NULL)');
    }

    public function seed(string $client, string $sku, int $quantity): void
    {
        $this->assertHost();
        $id = self::stockKey($client, $sku);
        if ($quantity < 0 || $quantity > 1_000_000_000) {
            throw new InvalidArgumentException('The warehouse seed requires its bounded original quantity.');
        }
        $this->transaction(function () use ($id, $quantity): void {
            $old = $this->row('SELECT * FROM allocation_stock WHERE id = ?', [$id]);
            if ($old !== null) {
                if ((int) $old['initial_quantity'] !== $quantity) {
                    throw new LogicException('Warehouse seed cannot replace its original inventory.');
                }
                return;
            }
            $this->query('INSERT INTO allocation_stock VALUES (?, ?, ?, 0, 0)', [$id, $quantity, $quantity]);
        });
    }

    public function reserve(Request $original, Request $release): array
    {
        $this->assertHost();
        $quantity = $original->terms['quantity'] ?? 0;
        $pending = new StockAllocation($original, array_fill(
            0,
            is_int($quantity) && $quantity > 0 && $quantity <= 1000 ? $quantity : 0,
            'unavailable'
        ));
        $quantity = count($pending->units);
        $this->assertRelease($original, $release);
        return $this->transaction(function () use ($original, $release, $quantity): array {
            $old = $this->row(self::RESERVATION_ROW, [$original->identity->key()]);
            if ($old !== null) {
                $this->assertOriginal($old, $original);
                if ($old['release_descriptor'] !== PortableDescriptor::encode($release->toArray())) {
                    throw new LogicException('The original release operation cannot be replaced.');
                }
                return $this->snapshot($old, $original);
            }
            $stock = $this->readInventory($original->identity->client, $original->terms['sku']);
            $held = min($quantity, $stock['available']);
            $allocation = new StockAllocation($original, array_merge(
                array_fill(0, $held, 'held'),
                array_fill(0, $quantity - $held, 'unavailable')
            ));
            $this->query(
                'UPDATE allocation_stock SET available = available - ? WHERE id = ?',
                [$held, self::stockKey($original->identity->client, $original->terms['sku'])]
            );
            $this->query('INSERT INTO allocation_reservations VALUES (?, ?, ?, ?, 1, 0, NULL)', [
                $original->identity->key(), PortableDescriptor::encode($original->toArray()),
                PortableDescriptor::encode($release->toArray()), PortableDescriptor::encode($allocation->toArray()),
            ]);
            return [
                'revision' => 1,
                'closed' => false,
                'allocation' => $allocation->toArray(),
            ];
        });
    }

    public function inspect(Request $original): array
    {
        $this->assertHost();
        return $this->snapshot($this->original($original), $original);
    }

    public function disposition(Request $original, string $eventKey, array $units, string $condition): array
    {
        $this->assertHost();
        PortableDescriptor::identifier($eventKey, 'original warehouse observation');
        if (! in_array($condition, ['damaged', 'missing'], true) || ! array_is_list($units) || $units === []) {
            throw new InvalidArgumentException('A warehouse observation needs explicit original units and condition.');
        }
        foreach ($units as $unit) {
            if (! is_int($unit) || $unit < 0 || $unit >= ($original->terms['quantity'] ?? 0)) {
                throw new InvalidArgumentException('The warehouse observation contains a foreign unit ordinal.');
            }
        }
        if (count($units) !== count(array_unique($units))) {
            throw new InvalidArgumentException('A warehouse observation cannot repeat original unit ordinals.');
        }
        sort($units);
        $id = hash('sha256', PortableDescriptor::encode([$original->identity->client, $eventKey]));
        $hash = hash('sha256', PortableDescriptor::encode([$original->toArray(), $units, $condition]));
        return $this->transaction(fn (): array => $this->recordDisposition($original, $units, $condition, $id, $hash));
    }

    public function release(Request $release): array
    {
        $this->assertHost();
        if ($release->action !== 'release' || $release->target === null) {
            throw new InvalidArgumentException('Only the pinned original release operation may close a reservation.');
        }
        return $this->transaction(function () use ($release): array {
            $row = $this->row(self::RESERVATION_ROW, [$release->target->key()]);
            if ($row === null || $row['release_descriptor'] !== PortableDescriptor::encode($release->toArray())) {
                throw new LogicException('The release differs from its pinned original compensation.');
            }
            $original = Request::fromArray(json_decode($row['descriptor'], true, 32, JSON_THROW_ON_ERROR));
            $this->assertOriginal($row, $original);
            $allocation = StockAllocation::fromArray(json_decode($row['allocation'], true, 32, JSON_THROW_ON_ERROR));
            $units = $allocation->decide($original, StockAllocation::CANCEL)['heldUnits'];
            $result = [
                'status' => 'released',
                'originalReservation' => $original->fingerprint(),
                'releasedUnits' => $units,
                'allocation' => $allocation->toArray(),
                'revision' => (int) $row['revision'] + ((bool) $row['closed'] ? 0 : 1),
            ];
            if ((bool) $row['closed']) {
                if ($row['result'] !== PortableDescriptor::encode($result)) {
                    throw new LogicException('The original warehouse release receipt changed.');
                }
                return $result;
            }
            $stock = $this->readInventory($original->identity->client, $original->terms['sku']);
            if (count(
                $units
            ) > $stock['initial_quantity'] - $stock['available'] - $stock['damaged'] - $stock['missing']) {
                throw new LogicException('The original release exceeds the warehouse held inventory.');
            }
            $this->query('UPDATE allocation_stock SET available = available + ? WHERE id = ?', [
                count($units), self::stockKey($original->identity->client, $original->terms['sku']),
            ]);
            $this->query('UPDATE allocation_reservations SET closed = 1, revision = ?, result = ? WHERE id = ?', [
                $result['revision'], PortableDescriptor::encode($result), $original->identity->key(),
            ]);
            return $result;
        });
    }

    public function inventory(string $client, string $sku): array
    {
        $this->assertHost();
        return $this->readInventory($client, $sku);
    }

    private function recordDisposition(
        Request $original,
        array $units,
        string $condition,
        string $id,
        string $hash
    ): array {
        $row = $this->original($original);
        $old = $this->row('SELECT * FROM allocation_events WHERE id = ?', [$id]);
        if ($old !== null) {
            if ($old['fingerprint'] !== $hash) {
                throw new LogicException('The original warehouse observation changed content or owner.');
            }
            if (! hash_equals($old['result_sha256'], hash('sha256', $old['result']))) {
                throw new LogicException('The retained warehouse observation result changed.');
            }
            $result = json_decode($old['result'], true, 32, JSON_THROW_ON_ERROR);
            return [
                'revision' => $result['revision'],
                'closed' => $result['closed'],
                'allocation' => StockAllocation::fromArray($result['allocation'])->toArray(),
            ];
        }
        if ((bool) $row['closed']) {
            throw new LogicException('A closed reservation cannot acquire a later warehouse observation.');
        }
        $allocation = StockAllocation::fromArray(json_decode($row['allocation'], true, 32, JSON_THROW_ON_ERROR));
        $states = $allocation->units;
        foreach ($units as $unit) {
            if ($states[$unit] !== 'held') {
                throw new LogicException('Only original suitable held units can become damaged or missing.');
            }
            $states[$unit] = $condition;
        }
        $allocation = new StockAllocation($original, $states);
        $revision = (int) $row['revision'] + 1;
        $result = [
            'revision' => $revision,
            'closed' => false,
            'allocation' => $allocation->toArray(),
        ];
        $this->query('UPDATE allocation_reservations SET allocation = ?, revision = ? WHERE id = ?', [
            PortableDescriptor::encode($allocation->toArray()), $revision, $original->identity->key(),
        ]);
        $sql = $condition === 'damaged'
            ? 'UPDATE allocation_stock SET damaged = damaged + ? WHERE id = ?'
            : 'UPDATE allocation_stock SET missing = missing + ? WHERE id = ?';
        $this->query($sql, [count($units), self::stockKey($original->identity->client, $original->terms['sku'])]);
        $payload = PortableDescriptor::encode($result);
        $this->query(
            'INSERT INTO allocation_events VALUES (?, ?, ?, ?)',
            [$id, $hash, $payload, hash('sha256', $payload)]
        );
        return $result;
    }

    private function readInventory(string $client, string $sku): array
    {
        $row = $this->row('SELECT * FROM allocation_stock WHERE id = ?', [self::stockKey($client, $sku)]);
        if ($row === null) {
            throw new LogicException('The original authoritative warehouse inventory is unavailable.');
        }
        $values = array_intersect_key($row, array_flip(['initial_quantity', 'available', 'damaged', 'missing']));
        foreach ($values as $value) {
            if (preg_match('/\A\d{1,10}\z/D', (string) $value) !== 1 || (int) $value > 1_000_000_000) {
                throw new LogicException('The warehouse inventory has an invalid physical quantity.');
            }
        }
        $values = array_map(static fn ($value): int => (int) $value, $values);
        if ($values['initial_quantity'] < $values['available'] + $values['damaged'] + $values['missing']) {
            throw new LogicException('The warehouse inventory exceeds its original physical quantity.');
        }
        return $values;
    }

    private function original(Request $original): array
    {
        $row = $this->row(self::RESERVATION_ROW, [$original->identity->key()]);
        if ($row === null) {
            throw new LogicException('The original warehouse reservation is unavailable.');
        }
        $this->assertOriginal($row, $original);
        return $row;
    }

    private function assertOriginal(array $row, Request $original): void
    {
        $allocation = StockAllocation::fromArray(json_decode($row['allocation'], true, 32, JSON_THROW_ON_ERROR));
        if ($row['descriptor'] !== PortableDescriptor::encode($original->toArray())
            || $allocation->reservation->fingerprint() !== $original->fingerprint()) {
            throw new LogicException('The warehouse reservation changed original owner or command content.');
        }
    }

    private function assertRelease(Request $original, Request $release): void
    {
        if ($release->action !== 'release' || $release->target?->toArray() !== $original->identity->toArray()
            || $release->terms !== $original->terms) {
            throw new InvalidArgumentException('Warehouse reservation and release must pin the same original stock.');
        }
        self::stockKey($original->identity->client, $original->terms['sku']);
    }

    private function snapshot(array $row, Request $original): array
    {
        $this->assertOriginal($row, $original);
        return [
            'revision' => (int) $row['revision'],
            'closed' => (bool) $row['closed'],
            'allocation' => StockAllocation::fromArray(
                json_decode($row['allocation'], true, 32, JSON_THROW_ON_ERROR)
            )->toArray(),
        ];
    }

    private static function stockKey(string $client, string $sku): string
    {
        PortableDescriptor::identifier($client, 'warehouse client');
        PortableDescriptor::identifier($sku, 'warehouse SKU');
        return hash('sha256', PortableDescriptor::encode([$client, $sku]));
    }

    private function row(string $sql, array $values): ?array
    {
        $row = $this->query($sql, $values)
            ->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function query(string $sql, array $values): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function assertHost(): void
    {
        if (WorkflowFiberContext::active() || $this->db->inTransaction()) {
            throw new LogicException('Warehouse commands require their owned host transaction outside replay.');
        }
    }

    private function transaction(\Closure $body): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $body();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
