<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;

/** Identity of the original provider assembly, including its clock and physical admission hooks. */
final readonly class ProviderContext
{
    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $clock,
    ) {
    }

    public function assertMatches(PDO $db, string $client, ?Closure $clock): void
    {
        if ($this->db !== $db || $this->client !== $client || $this->clock !== $clock || $db->inTransaction()) {
            throw new LogicException('A shared payment journal must retain its original provider context.');
        }
    }
}
