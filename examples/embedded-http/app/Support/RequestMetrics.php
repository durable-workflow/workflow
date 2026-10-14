<?php

declare(strict_types=1);

namespace App\Support;

final class RequestMetrics
{
    public int $queries = 0;
    public int $historyQueries = 0;
    public array $connections = [];

    public function reset(): void
    {
        $this->queries = 0;
        $this->historyQueries = 0;
        $this->connections = [];
    }

    public function record(string $sql, string $connection): void
    {
        ++$this->queries;
        $this->historyQueries += str_contains($sql, 'workflow_history_events') ? 1 : 0;
        $this->connections[$connection] = true;
    }
}
