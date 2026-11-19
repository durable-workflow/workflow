<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Support\WorkflowFiberContext;

/** Independent simulated payment service. plan()/resolve() are provider fixture controls only. */
final readonly class UnitRefundGateway
{
    private UnitRefundRecords $records;

    public function __construct(
        private PDO $db,
        private string $client
    ) {
        $this->assertHost();
        PortableDescriptor::identifier($client, 'unit refund provider client');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('The unit refund simulator requires its own SQLite provider connection.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->records = new UnitRefundRecords($db);
    }

    public function install(): void
    {
        $this->assertHost();
        $this->transaction(fn () => $this->records->install());
    }

    public function assertProvider(Journal $provider): void
    {
        $this->assertHost();
        $provider->assertUnitRefundConnection($this->db, $this->client);
    }

    public function register(OriginalUnitAmounts $original): void
    {
        $this->authorize($original);
        $this->transaction(fn () => $this->records->register($original));
    }

    public function plan(UnitRefundCommand $command, Status $status, bool $loseAcknowledgement = false): void
    {
        $this->authorize($command->original);
        $this->transaction(function () use ($command, $status, $loseAcknowledgement): void {
            $this->records->quote($command->original);
            if ($this->records->command($command) !== null) {
                throw new LogicException('An original admitted unit refund cannot be replanned or renamed.');
            }
            $this->records->query('INSERT INTO unit_refund_plans VALUES (?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET status = excluded.status, lose_ack = excluded.lose_ack', [
                $command->id(), $command->fingerprint(), $status->value, (int) $loseAcknowledgement,
            ]);
            $this->planRow($command);
        });
    }

    public function attempt(UnitRefundCommand $command): array
    {
        $this->authorize($command->original);
        [$result, $lose] = $this->transaction(function () use ($command): array {
            $this->records->quote($command->original);
            if ($this->records->command($command) !== null) {
                return [$this->observation($command), false];
            }
            $plan = $this->planRow($command);
            $this->progress($command, Status::from($plan['status']));
            $this->records->query('UPDATE unit_refund_plans SET lose_ack = 0 WHERE id = ?', [$command->id()]);
            return [$this->observation($command), (bool) $plan['lose_ack']];
        });
        if ($lose) {
            throw new LostAcknowledgement('Original unit refund committed before acknowledgement loss.');
        }
        return $result;
    }

    public function resolve(UnitRefundCommand $command, Status $status): array
    {
        $this->authorize($command->original);
        return $this->transaction(function () use ($command, $status): array {
            $this->records->quote($command->original);
            $row = $this->records->command($command);
            if ($row === null || (bool) $row['closed']
                || ! in_array($row['status'], [Status::Processing->value, Status::Unknown->value], true)
                || ! in_array($status, [Status::Confirmed, Status::Refused, Status::NotExecuted], true)) {
                throw new LogicException('Only an original unresolved refund has a provider completion.');
            }
            $this->progress($command, $status, $row);
            return $this->observation($command);
        });
    }

    public function lookup(UnitRefundCommand $command): array
    {
        $this->authorize($command->original);
        return $this->transaction(function () use ($command): array {
            $this->records->quote($command->original);
            return $this->observation($command);
        });
    }

    /**
     * Provider-side fence; an already admitted uncertain payout retains its original unresolved fate.
     */
    public function closeAdmission(UnitRefundCommand $command): array
    {
        $this->authorize($command->original);
        return $this->transaction(function () use ($command): array {
            $this->records->quote($command->original);
            $row = $this->records->command($command);
            if ($row === null || (! (bool) $row['admitted'] && ! (bool) $row['closed'])) {
                $this->records->write($command, Status::NotExecuted, false, [
                    'claimed' => [],
                    'covered' => [],
                ]);
            }
            return $this->observation($command);
        });
    }

    private function progress(UnitRefundCommand $command, Status $status, ?array $row = null): void
    {
        $selection = $row !== null && (bool) $row['admitted']
            ? [
                'claimed' => json_decode($row['claimed'], true, 32, JSON_THROW_ON_ERROR),
                'covered' => json_decode($row['covered'], true, 32, JSON_THROW_ON_ERROR),
            ]
            : $this->records->selectClaims($command);
        if ($selection === null) {
            $closedFate = in_array($status, [Status::Refused, Status::NotExecuted], true);
            $this->records->write($command, $closedFate ? $status : Status::Unknown, false, [
                'claimed' => [],
                'covered' => [],
            ]);
            return;
        }
        $this->records->admit($command, $selection);
        $captureConfirmed = $this->records->captureConfirmed($command->original);
        if ($status === Status::Confirmed && ! $captureConfirmed) {
            $status = Status::Unknown;
        }
        if ($captureConfirmed && $selection['claimed'] === []) {
            $status = Status::Confirmed;
        }
        if ($status === Status::Confirmed) {
            $this->records->payout($command, $selection['claimed']);
        }
        $this->records->write($command, $status, true, $selection);
    }

    private function observation(UnitRefundCommand $command): array
    {
        $row = $this->records->command($command);
        $settled = $this->records->settledUnits($command);
        if ($settled['units'] !== [] && ! $this->records->captureConfirmed($command->original)) {
            throw new LogicException('Settled units have incomplete original capture evidence.');
        }
        $payout = null;
        if ($row !== null && $row['status'] === Status::Confirmed->value) {
            $payout = $this->records->verifiedPayout(
                $command,
                json_decode($row['claimed'], true, 32, JSON_THROW_ON_ERROR)
            );
            if (! $this->records->captureConfirmed($command->original) || $settled['units'] !== $command->units) {
                throw new LogicException('A confirmed unit refund has incomplete original payment evidence.');
            }
        }
        return [
            'version' => 1,
            'commandId' => $command->id(),
            'commandFingerprint' => $command->fingerprint(),
            'allocationFingerprint' => $command->original->fingerprint(),
            'captureFingerprint' => $command->original->capture->fingerprint(),
            'status' => $row['status'] ?? Status::Unknown->value,
            'admissionClosed' => $row !== null && (bool) $row['closed'],
            'requestedUnits' => $command->units,
            'confirmedUnits' => $settled['units'],
            'requestedAmountMinor' => $command->original->amountFor($command->units),
            'confirmedAmountMinor' => $settled['units'] === [] ? 0 : $command->original->amountFor($settled['units']),
            'ownPayoutId' => $payout['payoutId'] ?? null,
            'ownPayoutMinor' => $payout['amountMinor'] ?? 0,
            'receiptsSha256' => $settled['evidenceSha256'],
        ];
    }

    private function planRow(UnitRefundCommand $command): array
    {
        $row = $this->records->row('SELECT * FROM unit_refund_plans WHERE id = ?', [$command->id()]);
        if ($row === null || $row['fingerprint'] !== $command->fingerprint()) {
            throw new LogicException('The provider has no matching original unit refund plan.');
        }
        return $row;
    }

    private function authorize(OriginalUnitAmounts $original): void
    {
        $this->assertHost();
        if ($original->capture->identity->client !== $this->client) {
            throw new LogicException('The unit refund provider credential belongs to another client.');
        }
    }

    private function assertHost(): void
    {
        if (WorkflowFiberContext::active() || $this->db->inTransaction()) {
            throw new LogicException(
                'Unit refund operations require their independent host transaction outside replay.'
            );
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
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
