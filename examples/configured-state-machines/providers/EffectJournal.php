<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** @internal Effect persistence inside the original Journal provider transaction. */
final readonly class EffectJournal
{
    public function __construct(
        private PDO $db,
        private ?Closure $clock,
        private bool $captureEffectTimes,
        private AuthorizationLedger $authorization,
        private CartReservationLedger $cart
    ) {
    }

    public function write(Request $request, Status $status, bool $closed): void
    {
        $this->query('INSERT INTO provider_operations (id, fingerprint, request, status, closed) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET status = excluded.status, closed = excluded.closed', [
            $request->identity->key(), $request->fingerprint(), PortableDescriptor::encode($request->toArray()),
            $status->value, (int) $closed,
        ]);
        if ($status === Status::Confirmed) {
            $created = $this->query(
                'INSERT INTO provider_effects (operation_id, effect_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$request->identity->key(), 'effect-' . $request->identity->key()]
            );
            if ($this->captureEffectTimes && $created->rowCount() === 1) {
                $at = ($this->clock ?? static fn (): int => time())();
                if (! is_int($at) || $at <= 0) {
                    throw new LogicException('The original provider commit time must be a positive integer.');
                }
                // Same independent provider transaction as both the operation and the committed effect.
                $this->query(
                    'INSERT INTO provider_effect_times (operation_id, fingerprint, effect_id, confirmed_at) VALUES (?, ?, ?, ?)',
                    [
                        $request->identity->key(), $request->fingerprint(), 'effect-' . $request->identity->key(), $at,
                    ]
                );
            }
            if ($this->captureEffectTimes) {
                $this->effectTime($request, 'effect-' . $request->identity->key());
            }
        }
    }

    public function effectTime(Request $request, string $effect): int
    {
        $row = $this->query('SELECT * FROM provider_effect_times WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['fingerprint'] !== $request->fingerprint() || $row['effect_id'] !== $effect
            || ! is_int($row['confirmed_at']) || $row['confirmed_at'] <= 0) {
            throw new LogicException('The original provider commit timestamp is missing or substituted.');
        }
        return $row['confirmed_at'];
    }

    public function observation(Request $request, ?array $row): Observation
    {
        if ($row !== null && $row['status'] === Status::Confirmed->value && CartReservationLedger::handles($request)) {
            $this->cart
                ->assertCompletion($request);
        }
        if ($row !== null && $row['status'] === Status::Confirmed->value && IncomingRefundLedger::handles($request)) {
            (new IncomingRefundLedger($this->db, $this->clock ?? static fn (): int => time()))
                ->assertCompletion($request);
        }
        if ($row !== null && $row['status'] === Status::Confirmed->value && AuthorizationLedger::handles($request)) {
            $this->authorization
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
        if ($this->captureEffectTimes && $effect !== false) {
            $this->effectTime($request, $effect);
        }
        return new Observation(
            $row === null ? Status::Unknown : Status::from($row['status']),
            $effect === false ? null : $effect,
            $row !== null && (bool) $row['closed'],
        );
    }

    private function query(string $sql, array $bindings): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($bindings);
        return $query;
    }
}
