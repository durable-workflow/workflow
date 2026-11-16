<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use PDO;
use Throwable;

/** Optional carrier-origin customer refunds; this does not clear an outstanding merchant remittance. */
final readonly class CodPaymentProvider
{
    public Journal $commands;

    public CodJournal $collections;

    public function __construct(
        private PDO $db,
        string $client,
        private ?Closure $clock = null
    ) {
        $this->commands = new Journal($db, $client, $clock);
        $this->collections = new CodJournal($db, $client, $clock);
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
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
