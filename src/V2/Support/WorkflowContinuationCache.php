<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use PDO;
use WeakMap;
use Workflow\V2\WorkflowStub;

/** @internal Experimental process-local storage. No queue affinity or task authority. */
final class WorkflowContinuationCache
{
    /**
     * @var array<string, WorkflowContinuation>
     */
    private array $entries = [];

    /**
     * @var array<string, int>
     */
    private array $generations = [];

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    private int $generation = 0;

    /**
     * @var WeakMap<PDO, int>
     */
    private WeakMap $backends;

    private int $backendGeneration = 0;

    public function __construct()
    {
        $this->backends = new WeakMap();
    }

    public function session(?object $job, int $claimedAttempt): ?WorkflowContinuationSession
    {
        if (! $job instanceof DatabaseJob || PHP_SAPI !== 'cli' || WorkflowStub::faked()
            || config('workflows.v2.live_execution.workflows', []) === []) {
            return null;
        }
        $name = config('workflows.storage.connection');
        $database = DB::connection(is_string($name) ? $name : null);
        if ($database->transactionLevel() !== 0 || $database->getPdo()->inTransaction()) {
            return null;
        }

        return new WorkflowContinuationSession($this, $database, $claimedAttempt);
    }

    public function backendIdentity(Connection $database): int
    {
        // A reconnect must miss even when connection names and persisted rows match.
        // Weak keys do not keep disconnected sessions alive or reuse object IDs.
        $pdo = $database->getPdo();

        return $this->backends[$pdo] ??= ++$this->backendGeneration;
    }

    public function observe(string $key): int
    {
        unset($this->generations[$key]);
        $this->generations[$key] = ++$this->generation;
        // A forgotten generation cannot publish later, even after reentrant commit callbacks.
        while (count($this->generations) > 128) {
            unset($this->generations[array_key_first($this->generations)]);
        }

        return $this->generation;
    }

    public function take(string $key, ?string $scope = null): ?WorkflowContinuation
    {
        $this->trim();
        foreach ($this->entries as $previousKey => $previous) {
            if ($scope !== null && $previous->scope === $scope && $previousKey !== $key) {
                unset($this->entries[$previousKey]);
                $this->count('invalidate_identity');
            }
        }
        $entry = $this->entries[$key] ?? null;
        unset($this->entries[$key]);
        $this->count($entry === null ? 'miss' : 'candidate');

        return $entry;
    }

    public function publish(string $key, int $generation, WorkflowContinuation $entry): void
    {
        $this->trim();
        $now = hrtime(true);
        if ($entry->retainedAt !== 0 && $now - $entry->retainedAt >= 30_000_000_000) {
            $this->count('discard_age');
            return;
        }
        $scope = $entry->scope === '' ? $key : $entry->scope;
        if (($this->generations[$scope] ?? null) !== $generation || ! $this->memoryAvailable()) {
            $this->count('discard_publication');
            return;
        }
        unset($this->entries[$key]);
        while (count($this->entries) >= 4) {
            unset($this->entries[array_key_first($this->entries)]);
            $this->count('evict_capacity');
        }
        if ($entry->retainedAt === 0) {
            $entry->retainedAt = $now;
        }
        $entry->workflow->run->unsetRelations();
        $this->entries[$key] = $entry;
        $this->count('retained');
    }

    public function count(string $reason, int $amount = 1): void
    {
        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + $amount;
    }

    public function invalidate(string $scope): void
    {
        foreach ($this->entries as $key => $entry) {
            if ($entry->scope === $scope) {
                unset($this->entries[$key]);
                $this->count('invalidate_run');
            }
        }
    }

    /**
     * @return array{entries: int, resident_bytes: ?int, counts: array<string, int>}
     */
    public function snapshot(): array
    {
        $this->trim();

        return [
            'entries' => count($this->entries),
            'resident_bytes' => $this->residentBytes(),
            'counts' => $this->counts,
        ];
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->generations = [];
    }

    private function trim(): void
    {
        if (! $this->memoryAvailable()) {
            $this->count('evict_memory');
            $this->entries = [];
            return;
        }
        $now = hrtime(true);
        foreach ($this->entries as $key => $entry) {
            if ($now - $entry->retainedAt >= 30_000_000_000) {
                unset($this->entries[$key]);
                $this->count('evict_age');
            }
        }
    }

    private function memoryAvailable(): bool
    {
        $bytes = $this->residentBytes();

        return $bytes !== null && $bytes < 128 * 1024 * 1024;
    }

    private function residentBytes(): ?int
    {
        $status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
        if (! is_string($status) || preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 1024;
    }
}
