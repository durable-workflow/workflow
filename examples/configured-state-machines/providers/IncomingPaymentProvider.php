<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use PDO;
use Throwable;

/** Optional bank receipt/refund setup on one provider-owned connection; commands use the existing engine. */
final readonly class IncomingPaymentProvider
{
    public Journal $commands;

    public IncomingPaymentJournal $receipts;

    public function __construct(
        private PDO $db,
        string $client,
        private ?Closure $clock = null
    ) {
        $this->commands = new Journal($db, $client, $clock);
        $this->receipts = new IncomingPaymentJournal($db, $client, $clock);
    }

    /**
     * Install only the optional bank tables, after commands->install() creates the original four-table journal.
     */
    public function install(): void
    {
        $this->receipts->install();
        $this->db->beginTransaction();
        try {
            (new IncomingRefundLedger($this->db, $this->clock ?? static fn (): int => time()))->install();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
