<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use PDO;

/** One original engine for a bank advance and carrier balance; each keeps its own money evidence. */
final readonly class AdvanceBalanceProvider
{
    public Journal $commands;

    public IncomingPaymentProvider $incoming;

    public CodPaymentProvider $cod;

    public function __construct(PDO $db, string $client, ?Closure $clock = null)
    {
        $this->cod = new CodPaymentProvider($db, $client, $clock, true);
        $this->commands = $this->cod->commands;
        $this->incoming = new IncomingPaymentProvider($db, $client, $clock, $this->commands);
    }

    /**
     * Fresh provider fixture only; native admission still needs the original paired host responsibilities.
     */
    public function install(): void
    {
        $this->commands->install();
        $this->commands->shipments()
            ->install();
        $this->cod->install();
        $this->incoming->install();
    }
}
