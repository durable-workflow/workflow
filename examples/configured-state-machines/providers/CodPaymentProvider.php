<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Throwable;

/** Optional carrier-origin customer refunds; this does not clear an outstanding merchant remittance. */
final readonly class CodPaymentProvider
{
    public Journal $commands;

    public CodJournal $collections;

    public ?CodCarrierContract $carrier;

    public function __construct(
        private PDO $db,
        string $client,
        private ?Closure $clock = null,
        public bool $physicalContract = false
    ) {
        $this->carrier = $physicalContract ? new CodCarrierContract($db, $clock ?? static fn (): int => time()) : null;
        $this->commands = new Journal(
            $db,
            $client,
            $clock,
            $this->carrier === null ? null : $this->carrier->beforePhysical(...)
        );
        $this->collections = new CodJournal(
            $db,
            $client,
            $clock,
            $this->carrier === null ? null : $this->carrier->register(...),
            $this->carrier === null ? null : $this->carrier->collect(...),
            $this->carrier === null ? null : $this->carrier->inspect(...)
        );
    }

    /**
     * Install optional tables after commands->install() on the same fresh provider-owned connection.
     */
    public function install(): void
    {
        $this->collections->install();
        $this->db->beginTransaction();
        try {
            (new CodRefundLedger($this->db, $this->clock ?? static fn (): int => time()))->install();
            $this->carrier?->install();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function inspect(CodDescriptor $original): array
    {
        if ($this->carrier === null) {
            throw new LogicException('Recorded COD inspection requires its original physical carrier contract.');
        }
        return $this->collections->inspect($original);
    }
}
