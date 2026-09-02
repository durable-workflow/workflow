<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class QueueVisibilityDetail
{
    /**
     * @param list<array<string, mixed>> $pollers
     * @param array<string, mixed> $stats
     * @param list<array<string, mixed>> $currentLeases
     * @param array<string, mixed> $repair
     */
    public function __construct(
        public readonly string $name,
        private readonly array $pollers,
        private readonly array $stats,
        private readonly array $currentLeases,
        private readonly array $repair,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pollers(): array
    {
        return $this->pollers;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function currentLeases(): array
    {
        return $this->currentLeases;
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(): array
    {
        return $this->repair;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'pollers' => $this->pollers,
            'stats' => $this->stats,
            'current_leases' => $this->currentLeases,
            'repair' => $this->repair,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'name' => $this->name,
            'stats' => $this->stats,
            'repair' => $this->repair,
        ];
    }
}
