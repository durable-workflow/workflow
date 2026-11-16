<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * A disposable provider simulator on its own SQLite connection/file, never the DW connection.
 * plan() and resolve() are provider-side fixture controls, not application commands.
 */
final readonly class Journal
{
    private const SCHEMA = [
        'CREATE TABLE provider_plans (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, status TEXT NOT NULL, lose_ack INTEGER NOT NULL)',
        'CREATE TABLE provider_operations (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, request TEXT NOT NULL, status TEXT NOT NULL, closed INTEGER NOT NULL)',
        'CREATE TABLE provider_effects (sequence INTEGER PRIMARY KEY AUTOINCREMENT, operation_id TEXT NOT NULL UNIQUE, effect_id TEXT NOT NULL UNIQUE)',
        'CREATE TABLE provider_requests (sequence INTEGER PRIMARY KEY AUTOINCREMENT, operation_id TEXT NOT NULL, kind TEXT NOT NULL)',
    ];

    public ProviderContext $context;

    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $clock = null,
        private ?Closure $beforePhysicalEvent = null,
    ) {
        PortableDescriptor::identifier($client, 'provider client');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->inTransaction()) {
            throw new LogicException('The synthetic provider requires its own SQLite connection and transaction.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->context = new ProviderContext($db, $client, $clock);
    }

    public function install(): void
    {
        $this->transaction(function (): void {
            foreach (self::SCHEMA as $sql) {
                $this->db->exec($sql);
            }
        });
    }

    /**
     * Explicit optional provider support; install() keeps the original four-table fixtures unchanged.
     */
    public function installAuthorizationSupport(): void
    {
        $this->transaction(fn () => $this->authorizationLedger()->install());
    }

    public function shipments(): ShipmentJournal
    {
        return new ShipmentJournal($this->db, $this->client, $this->beforePhysicalEvent);
    }

    public function cartInventory(): CartInventory
    {
        return new CartInventory($this->db, $this->client, $this->clock);
    }

    /**
     * Select the next attempt's result. This never dispatches an operation or creates an effect.
     */
    public function plan(Request $request, Status $status, bool $loseAcknowledgement = false): void
    {
        $this->authorize($request);
        $this->transaction(function () use ($request, $status, $loseAcknowledgement): void {
            $row = $this->operation($request);
            if ($row !== null && ((bool) $row['closed'] || $row['status'] !== Status::NotExecuted->value)) {
                throw new LogicException('Only an unclosed, definitively unexecuted operation can be attempted again.');
            }
            $this->query('INSERT INTO provider_plans (id, fingerprint, status, lose_ack) VALUES (?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET status = excluded.status, lose_ack = excluded.lose_ack', [
                $request->identity->key(), $request->fingerprint(), $status->value, (int) $loseAcknowledgement,
            ]);
            $this->assertPlan($request);
        });
    }

    public function attempt(Request $request): Observation
    {
        $this->authorize($request);
        [$result, $lose] = $this->transaction(function () use ($request): array {
            $row = $this->operation($request);
            $this->recordRequest($request, 'command');
            if ($row !== null && ((bool) $row['closed'] || $row['status'] !== Status::NotExecuted->value)) {
                return [$this->observation($request, $row), false];
            }
            $plan = $this->assertPlan($request);
            $status = Status::from($plan['status']);
            if (CartReservationLedger::handles($request)) {
                $status = $this->cartLedger()
                    ->admit($request, $status);
            } elseif (AuthorizationLedger::handles($request)) {
                $status = $this->authorizationLedger()
                    ->admit($request, $status);
            } elseif (in_array($request->action, ShipmentLedger::ACTIONS, true)) {
                $status = (new ShipmentLedger($this->db))
                    ->admit($request, $status);
            } elseif (IncomingRefundLedger::handles($request)) {
                $status = (new IncomingRefundLedger($this->db, $this->clock ?? static fn (): int => time()))
                    ->admit($request, $status);
            } elseif (CodRefundLedger::handles($request)) {
                $status = (new CodRefundLedger($this->db, $this->clock ?? static fn (): int => time()))
                    ->admit($request, $status);
            } elseif (! $this->targetConfirmed($request)) {
                $status = Status::Unknown;
            }
            $this->write($request, $status, false);
            $lose = (bool) $plan['lose_ack'];
            $this->query('UPDATE provider_plans SET lose_ack = 0 WHERE id = ?', [$request->identity->key()]);
            return [$this->observation($request, $this->operation($request)), $lose];
        });
        // Deliberately outside the provider transaction: rolling back a DW Activity cannot undo this effect.
        if ($lose) {
            throw new LostAcknowledgement('The synthetic provider committed its response before acknowledgement loss.');
        }
        return $result;
    }

    /**
     * A missing lookup is unknown. Reading never creates an operation, fence, or effect.
     */
    public function lookup(Request $request): Observation
    {
        $this->authorize($request);
        return $this->transaction(function () use ($request): Observation {
            $row = $this->operation($request);
            $this->recordRequest($request, 'lookup');
            return $this->observation($request, $row);
        });
    }

    /**
     * Invoke only from an authorized recorded host activity, never directly during workflow replay.
     */
    public function inspectFinancial(FinancialDescriptor $descriptor): FinancialSnapshot
    {
        foreach ($descriptor->requests() as $request) {
            $this->authorize($request);
        }
        return $this->transaction(function () use ($descriptor): FinancialSnapshot {
            $observations = [];
            foreach ($descriptor->requests() as $key => $request) {
                $observations[$key] = $this->observation($request, $this->operation($request));
            }
            $state = $this->authorizationLedger()
                ->inspectCapture($descriptor);
            $clock = $this->clock ?? static fn (): int => time();
            $now = $clock();
            if (! is_int($now) || $now < 0) {
                throw new LogicException('The provider clock must return a nonnegative integer Unix timestamp.');
            }
            $this->recordRequest($descriptor->authorize, 'financial-inspection');
            return new FinancialSnapshot(
                $descriptor,
                $now,
                $observations['authorize'],
                $observations['capture'],
                $observations['void'],
                $observations['refund'],
                $state['closed'],
                $state['inFlight']
            );
        });
    }

    /**
     * Close future admission atomically; an already admitted processing/unknown result remains unresolved.
     */
    public function closeAdmission(Request $request): Observation
    {
        $this->authorize($request);
        return $this->transaction(function () use ($request): Observation {
            $row = $this->operation($request);
            if (CartReservationLedger::handles($request) && $request->action === 'reserve') {
                $this->cartLedger()
                    ->closeAdmission($request);
            }
            if ($row === null) {
                $this->write($request, Status::NotExecuted, true);
            } else {
                $this->query('UPDATE provider_operations SET closed = 1 WHERE id = ?', [$request->identity->key()]);
            }
            return $this->observation($request, $this->operation($request));
        });
    }

    /**
     * Provider-side completion may arrive after cancellation; it must keep the admitted operation's identity.
     */
    public function resolve(Request $request, Status $status): Observation
    {
        $this->authorize($request);
        return $this->transaction(function () use ($request, $status): Observation {
            $row = $this->operation($request);
            if ($row === null || ! in_array($row['status'], [Status::Processing->value, Status::Unknown->value], true)
                || ! in_array($status, [Status::Confirmed, Status::NotExecuted, Status::Refused], true)) {
                throw new LogicException('Only an admitted unresolved operation has a provider completion.');
            }
            if ($status === Status::Confirmed) {
                if (AuthorizationLedger::handles($request)) {
                    $this->authorizationLedger()
                        ->assertCompletion($request);
                } elseif (in_array($request->action, ShipmentLedger::ACTIONS, true)) {
                    (new ShipmentLedger($this->db))
                        ->assertCompletion($request);
                } elseif (IncomingRefundLedger::handles($request)) {
                    (new IncomingRefundLedger($this->db, $this->clock ?? static fn (): int => time()))
                        ->assertCompletion($request);
                } elseif (CodRefundLedger::handles($request)) {
                    (new CodRefundLedger($this->db, $this->clock ?? static fn (): int => time()))
                        ->assertCompletion($request);
                } elseif (! $this->targetConfirmed($request)) {
                    throw new LogicException(
                        'Compensation cannot confirm against an unknown or different original effect.'
                    );
                }
            }
            if (CartReservationLedger::handles($request)) {
                $this->cartLedger()
                    ->complete($request, $status);
            }
            $this->write($request, $status, (bool) $row['closed']);
            return $this->observation($request, $this->operation($request));
        });
    }

    private function authorize(Request $request): void
    {
        if ($request->identity->client !== $this->client) {
            throw new LogicException('The provider credential belongs to another client.');
        }
    }

    private function authorizationLedger(): AuthorizationLedger
    {
        return new AuthorizationLedger($this->db, $this->clock ?? static fn (): int => time());
    }

    private function cartLedger(): CartReservationLedger
    {
        return new CartReservationLedger($this->db, $this->clock ?? static fn (): int => time());
    }

    private function operation(Request $request): ?array
    {
        $row = $this->query('SELECT * FROM provider_operations WHERE id = ?', [$request->identity->key()])
            ->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && ($row['fingerprint'] !== $request->fingerprint()
            || ! hash_equals($row['fingerprint'], hash('sha256', $row['request'])))) {
            throw new LogicException('The original provider command terms were substituted.');
        }
        return $row === false ? null : $row;
    }

    private function assertPlan(Request $request): array
    {
        $row = $this->query('SELECT * FROM provider_plans WHERE id = ?', [$request->identity->key()])
            ->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['fingerprint'] !== $request->fingerprint()) {
            throw new LogicException('The provider has no matching immutable command plan.');
        }
        return $row;
    }

    private function targetConfirmed(Request $request): bool
    {
        if ($request->target === null) {
            return true;
        }
        $row = $this->query('SELECT * FROM provider_operations WHERE id = ?', [$request->target->key()])
            ->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if (! hash_equals($row['fingerprint'], hash('sha256', $row['request']))) {
            throw new LogicException('The original provider command content was substituted.');
        }
        $target = json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR);
        $expected = match ($request->action) {
            'refund' => 'capture',
            'cancel-booking' => 'book',
            'release' => 'reserve',
            default => throw new LogicException('The compensation action is unavailable.'),
        };
        if ($target['identity'] !== PortableDescriptor::snapshot($request->target->toArray())
            || $target['action'] !== $expected) {
            throw new LogicException('Compensation targets another original provider action.');
        }
        return $row['status'] === Status::Confirmed->value;
    }

    private function write(Request $request, Status $status, bool $closed): void
    {
        $this->query('INSERT INTO provider_operations (id, fingerprint, request, status, closed) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET status = excluded.status, closed = excluded.closed', [
            $request->identity->key(), $request->fingerprint(), PortableDescriptor::encode($request->toArray()),
            $status->value, (int) $closed,
        ]);
        if ($status === Status::Confirmed) {
            $this->query(
                'INSERT INTO provider_effects (operation_id, effect_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$request->identity->key(), 'effect-' . $request->identity->key()]
            );
        }
    }

    private function observation(Request $request, ?array $row): Observation
    {
        if ($row !== null && $row['status'] === Status::Confirmed->value && CartReservationLedger::handles($request)) {
            $this->cartLedger()
                ->assertCompletion($request);
        }
        if ($row !== null && $row['status'] === Status::Confirmed->value && IncomingRefundLedger::handles($request)) {
            (new IncomingRefundLedger($this->db, $this->clock ?? static fn (): int => time()))
                ->assertCompletion($request);
        }
        if ($row !== null && $row['status'] === Status::Confirmed->value && AuthorizationLedger::handles($request)) {
            $this->authorizationLedger()
                ->assertCompletion($request);
        }
        if ($row !== null && $row['status'] === Status::Confirmed->value && in_array(
            $request->action,
            ShipmentLedger::ACTIONS,
            true
        )) {
            (new ShipmentLedger($this->db))
                ->assertCompletion($request);
        }
        $effect = $this->query('SELECT effect_id FROM provider_effects WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetchColumn();
        if ($effect !== false && $effect !== 'effect-' . $request->identity->key()) {
            throw new LogicException('The provider evidence belongs to another original operation.');
        }
        return new Observation(
            $row === null ? Status::Unknown : Status::from($row['status']),
            $effect === false ? null : $effect,
            $row !== null && (bool) $row['closed'],
        );
    }

    private function recordRequest(Request $request, string $kind): void
    {
        $this->query(
            'INSERT INTO provider_requests (operation_id, kind) VALUES (?, ?)',
            [$request->identity->key(), $kind]
        );
    }

    private function query(string $sql, array $bindings): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($bindings);
        return $query;
    }

    private function transaction(Closure $action): mixed
    {
        if ($this->db->inTransaction()) {
            throw new LogicException('A caller transaction cannot contain the provider transaction.');
        }
        $this->db->beginTransaction();
        try {
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
