<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** @internal Every call belongs to the unit gateway's independent provider transaction. */
final readonly class UnitRefundRecords
{
    private const COMMAND_ROW = 'SELECT * FROM unit_refund_commands WHERE id = ?';

    public function __construct(
        private PDO $db
    ) {
    }

    public function install(): void
    {
        foreach ([
            'CREATE TABLE unit_money_quotes (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, descriptor TEXT NOT NULL)',
            'CREATE TABLE unit_refund_plans (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, status TEXT NOT NULL, lose_ack INTEGER NOT NULL)',
            'CREATE TABLE unit_refund_commands (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, descriptor TEXT NOT NULL, status TEXT NOT NULL, closed INTEGER NOT NULL, admitted INTEGER NOT NULL, claimed TEXT NOT NULL, covered TEXT NOT NULL)',
            'CREATE TABLE unit_refund_claims (quote_id TEXT NOT NULL, unit INTEGER NOT NULL, command_id TEXT NOT NULL, PRIMARY KEY (quote_id, unit))',
            'CREATE TABLE unit_refund_payouts (command_id TEXT PRIMARY KEY, quote_id TEXT NOT NULL, payout_id TEXT NOT NULL UNIQUE, units TEXT NOT NULL, amount_minor INTEGER NOT NULL, currency TEXT NOT NULL, fingerprint TEXT NOT NULL)',
        ] as $sql) {
            $this->query($sql);
        }
    }

    public function register(OriginalUnitAmounts $original): void
    {
        $id = $original->capture->identity->key();
        $old = $this->row('SELECT * FROM unit_money_quotes WHERE id = ?', [$id]);
        if ($old !== null) {
            $this->quote($original);
            return;
        }
        if ($this->row('SELECT id FROM provider_operations WHERE id = ?', [$id]) !== null) {
            throw new LogicException('Original unit amounts must be pinned before capture admission.');
        }
        $this->query('INSERT INTO unit_money_quotes VALUES (?, ?, ?)', [
            $id, $original->fingerprint(), PortableDescriptor::encode($original->toArray()),
        ]);
    }

    public function quote(OriginalUnitAmounts $original): void
    {
        $row = $this->row('SELECT * FROM unit_money_quotes WHERE id = ?', [$original->capture->identity->key()]);
        if ($row === null || $row['fingerprint'] !== $original->fingerprint()
            || $row['descriptor'] !== PortableDescriptor::encode($original->toArray())) {
            throw new LogicException('The original charged-unit allocation is unavailable or changed.');
        }
    }

    public function captureConfirmed(OriginalUnitAmounts $original): bool
    {
        $capture = $original->capture;
        $row = $this->row('SELECT * FROM provider_operations WHERE id = ?', [$capture->identity->key()]);
        $effect = $this->row('SELECT * FROM provider_effects WHERE operation_id = ?', [$capture->identity->key()]);
        if ($row !== null && ($row['fingerprint'] !== $capture->fingerprint()
            || $row['request'] !== PortableDescriptor::encode($capture->toArray()))) {
            throw new LogicException('Unit refunds cannot adopt another original capture.');
        }
        if ($row === null || $row['status'] !== Status::Confirmed->value) {
            if ($effect !== null) {
                throw new LogicException('The original capture has inconsistent effect evidence.');
            }
            return false;
        }
        // Journal.closed fences future admission; a committed capture need not have that separate fence.
        if (! in_array($row['closed'], [0, 1], true)
            || $effect === null || $effect['effect_id'] !== 'effect-' . $capture->identity->key()) {
            throw new LogicException('The original capture lost its matching committed effect.');
        }
        return true;
    }

    public function command(UnitRefundCommand $command): ?array
    {
        $row = $this->row(self::COMMAND_ROW, [$command->id()]);
        if ($row !== null && ($row['fingerprint'] !== $command->fingerprint()
            || $row['descriptor'] !== PortableDescriptor::encode($command->toArray()))) {
            throw new LogicException('The original unit refund request changed units, owner or allocation.');
        }
        if ($row !== null) {
            $this->validateState($command, $row);
        }
        return $row;
    }

    /**
     * Null means another original unresolved command still owns at least one requested unit.
     */
    public function selectClaims(UnitRefundCommand $command): ?array
    {
        $claimed = [];
        $covered = [];
        foreach ($command->units as $unit) {
            $row = $this->row('SELECT * FROM unit_refund_claims WHERE quote_id = ? AND unit = ?', [
                $command->original->capture->identity->key(), $unit,
            ]);
            if ($row === null) {
                $claimed[] = $unit;
                continue;
            }
            $owner = $this->owner($row['command_id'], $command->original);
            $status = Status::from($owner['status']);
            if (in_array($status, [Status::Processing, Status::Unknown], true)) {
                return null;
            }
            if ($status === Status::Confirmed) {
                $covered[] = [
                    'unit' => $unit,
                    'commandId' => $row['command_id'],
                ];
            } elseif ((bool) $owner['closed']) {
                $claimed[] = $unit;
            } else {
                throw new LogicException('A nonterminal unit claim has no closed original fate.');
            }
        }
        return [
            'claimed' => $claimed,
            'covered' => $covered,
        ];
    }

    public function admit(UnitRefundCommand $command, array $selection): void
    {
        foreach ($selection['claimed'] as $unit) {
            $this->query('INSERT INTO unit_refund_claims VALUES (?, ?, ?)
                ON CONFLICT(quote_id, unit) DO UPDATE SET command_id = excluded.command_id', [
                $command->original->capture->identity->key(), $unit, $command->id(),
            ]);
        }
    }

    public function write(UnitRefundCommand $command, Status $status, bool $admitted, array $selection): void
    {
        $closed = in_array($status, [Status::Confirmed, Status::Refused, Status::NotExecuted], true);
        $this->query('INSERT INTO unit_refund_commands VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET status = excluded.status, closed = excluded.closed,
            admitted = excluded.admitted, claimed = excluded.claimed, covered = excluded.covered', [
            $command->id(), $command->fingerprint(), PortableDescriptor::encode($command->toArray()),
            $status->value, (int) $closed, (int) $admitted,
            PortableDescriptor::encode($selection['claimed']), PortableDescriptor::encode($selection['covered']),
        ]);
    }

    public function payout(UnitRefundCommand $command, array $claimed): void
    {
        if ($claimed === []) {
            return;
        }
        $amount = $command->original->amountFor($claimed);
        if ($amount === 0) {
            return;
        }
        $payload = $this->payoutPayload($command, $claimed);
        $this->query('INSERT INTO unit_refund_payouts VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $command->id(), $payload['quote'], $payload['payoutId'], PortableDescriptor::encode($claimed),
            $amount, $payload['currency'], hash('sha256', PortableDescriptor::encode($payload)),
        ]);
    }

    public function settledUnits(UnitRefundCommand $command): array
    {
        $confirmed = [];
        $evidence = [];
        foreach ($command->units as $unit) {
            $claim = $this->row('SELECT * FROM unit_refund_claims WHERE quote_id = ? AND unit = ?', [
                $command->original->capture->identity->key(), $unit,
            ]);
            if ($claim === null) {
                continue;
            }
            $owner = $this->owner($claim['command_id'], $command->original);
            if ($owner['status'] !== Status::Confirmed->value) {
                continue;
            }
            $originalCommand = UnitRefundCommand::fromArray(
                json_decode($owner['descriptor'], true, 32, JSON_THROW_ON_ERROR)
            );
            $claimed = json_decode($owner['claimed'], true, 32, JSON_THROW_ON_ERROR);
            if (! (bool) $owner['admitted'] || ! in_array($unit, $claimed, true)) {
                throw new LogicException('The confirmed refund does not own the original unit.');
            }
            $payout = $this->verifiedPayout($originalCommand, $claimed);
            $confirmed[] = $unit;
            $evidence[] = [$unit, $owner['id'], $payout['payoutId'] ?? null, $command->original->amounts[$unit]];
        }
        return [
            'units' => $confirmed,
            'evidenceSha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
        ];
    }

    public function verifiedPayout(UnitRefundCommand $command, array $claimed): ?array
    {
        $row = $this->row('SELECT * FROM unit_refund_payouts WHERE command_id = ?', [$command->id()]);
        $amount = $claimed === [] ? 0 : $command->original->amountFor($claimed);
        if ($amount === 0) {
            if ($row !== null) {
                throw new LogicException('A covered or zero-amount refund cannot create another payout.');
            }
            return null;
        }
        $expected = $this->payoutPayload($command, $claimed);
        if ($row === null || $row['quote_id'] !== $expected['quote'] || $row['payout_id'] !== $expected['payoutId']
            || $row['units'] !== PortableDescriptor::encode($claimed) || (int) $row['amount_minor'] !== $amount
            || $row['currency'] !== $expected['currency']
            || $row['fingerprint'] !== hash('sha256', PortableDescriptor::encode($expected))) {
            throw new LogicException('A confirmed original refund lost its matching payout receipt.');
        }
        return $expected;
    }

    public function row(string $sql, array $values = []): ?array
    {
        $row = $this->query($sql, $values)
            ->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function query(string $sql, array $values = []): \PDOStatement
    {
        if (! $this->db->inTransaction()) {
            throw new LogicException('Unit refund storage requires its owned provider transaction.');
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function owner(string $id, OriginalUnitAmounts $original): array
    {
        $row = $this->row(self::COMMAND_ROW, [$id]);
        if ($row === null) {
            throw new LogicException('The original unit claim owner is unavailable.');
        }
        $command = UnitRefundState::command($row);
        if ($command->id() !== $id || $command->original->fingerprint() !== $original->fingerprint()) {
            throw new LogicException('The original refund claim owner or charged allocation changed.');
        }
        $this->validateState($command, $row);
        return $row;
    }

    private function validateState(UnitRefundCommand $command, array $row): void
    {
        $selection = UnitRefundState::selection($command, $row);
        $this->quote($command->original);
        $this->validateClaims($command, $row, $selection['claimed']);
        $this->validateCovered($command, $selection['covered']);
        if ($row['status'] === Status::Confirmed->value) {
            $this->verifiedPayout($command, $selection['claimed']);
        } elseif ($this->row(
            'SELECT command_id FROM unit_refund_payouts WHERE command_id = ?',
            [$command->id()]
        ) !== null) {
            throw new LogicException('An unresolved or refused original refund has inconsistent payout evidence.');
        }
    }

    private function validateClaims(UnitRefundCommand $command, array $row, array $claimed): void
    {
        $actual = [];
        foreach ($this->query('SELECT * FROM unit_refund_claims WHERE command_id = ? ORDER BY unit', [
            $command->id(),
        ])->fetchAll(PDO::FETCH_ASSOC) as $claim) {
            if ($claim['quote_id'] !== $command->original->capture->identity->key()
                || ! in_array($claim['unit'], $claimed, true)) {
                throw new LogicException('An original refund claim moved outside its admitted unit set.');
            }
            $actual[] = $claim['unit'];
        }
        if ((! (bool) $row['closed'] || $row['status'] === Status::Confirmed->value) && $actual !== $claimed) {
            throw new LogicException('An unresolved or confirmed refund lost its original unit claim.');
        }
    }

    private function validateCovered(UnitRefundCommand $command, array $covered): void
    {
        foreach ($covered as $receipt) {
            $row = $this->row(self::COMMAND_ROW, [$receipt['commandId']]);
            $claim = $this->row('SELECT command_id FROM unit_refund_claims WHERE quote_id = ? AND unit = ?', [
                $command->original->capture->identity->key(), $receipt['unit'],
            ]);
            if ($row === null || $row['status'] !== Status::Confirmed->value
                || $claim === null || $claim['command_id'] !== $receipt['commandId']) {
                throw new LogicException('The original covered unit lost its committed refund owner.');
            }
            $owner = UnitRefundState::command($row);
            if ($owner->id() !== $receipt['commandId'] || $owner->original->fingerprint() !== $command->original->fingerprint()) {
                throw new LogicException('The covered refund receipt belongs to another original command.');
            }
            $selection = UnitRefundState::selection($owner, $row);
            if (! in_array($receipt['unit'], $selection['claimed'], true)) {
                throw new LogicException('The covered refund receipt did not pay the original unit.');
            }
            $this->verifiedPayout($owner, $selection['claimed']);
        }
    }

    private function payoutPayload(UnitRefundCommand $command, array $claimed): array
    {
        return [
            'quote' => $command->original->capture->identity->key(),
            'payoutId' => 'unit-payout-' . $command->id(),
            'units' => $claimed,
            'amountMinor' => $command->original->amountFor($claimed),
            'currency' => $command->original->capture->terms['currency'],
        ];
    }
}
