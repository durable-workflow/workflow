<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

/**
 * Live, exact workflow-type eligibility from a Laravel worker entering its
 * queue loop. Generic compatibility heartbeats do not populate these rows.
 *
 * @internal
 */
final class EmbeddedWorkflowHeartbeat
{
    private const TABLE = 'workflow_embedded_workflow_heartbeats';

    private ?string $workerId = null;

    private ?string $lastScope = null;

    private int $lastWrite = 0;

    public static function enabled(): bool
    {
        return (bool) config('workflows.v2.compatibility.continue_as_new_target_build', false);
    }

    public function record(string $connection, string $queues): void
    {
        try {
            $this->recordScopes($connection, $queues);
        } catch (Throwable $throwable) {
            // A heartbeat write failure must not interrupt the queue worker.
            // Its existing evidence expires under the same cutoff as the fleet.
            report($throwable);
        }
    }

    public function forget(): void
    {
        if ($this->workerId === null) {
            return;
        }

        try {
            ConfiguredV2Models::connection()->table(self::TABLE)
                ->where('worker_id', $this->workerId)
                ->delete();
        } catch (Throwable $throwable) {
            report($throwable);
        } finally {
            $this->workerId = null;
            $this->lastScope = null;
        }
    }

    /**
     * Read under the same storage transaction that admits the continuation.
     * The returned definition belongs to the worker on the selected build.
     *
     * @return array{build: string, workflow_class: class-string<Workflow>, fingerprint: string, command_contract: array<string, mixed>}|null
     */
    public static function promotedDefinition(WorkflowRun $run): ?array
    {
        $configured = config('workflows.v2.compatibility.promoted');
        $build = is_string($configured) ? trim($configured) : '';
        $connection = $run->connection ?? config('queue.default');
        $queue = $run->queue ?? (is_string($connection) ? config(
            'queue.connections.' . $connection . '.queue',
            'default'
        ) : null);
        if ($build === '' || $build === '*' || ! is_string($run->workflow_type) || $run->workflow_type === ''
            || ! is_string($connection) || $connection === '' || ! is_string($queue) || $queue === ''
            || (bool) config('workflows.v2.compatibility.disable_heartbeat_table', false)) {
            return null;
        }

        $database = $run->getConnection();
        if (! $database->getSchemaBuilder()->hasTable(self::TABLE)) {
            return null;
        }

        $key = self::scopeKey(
            NamespaceScope::canonical($run->namespace),
            $connection,
            $queue,
            $build,
            $run->workflow_type
        );
        $records = $database->table(self::TABLE)
            ->where('scope_key', $key)
            ->where('expires_at', '>=', now()->format('Y-m-d H:i:s.u'))
            ->orderByDesc('expires_at')
            ->orderBy('worker_id')
            ->sharedLock()
            ->get(['definition']);
        $selected = null;
        foreach ($records as $record) {
            $definition = is_string($record->definition) ? json_decode($record->definition, true) : null;
            if (! is_array($definition)
                || ! is_string($definition['workflow_class'] ?? null) || $definition['workflow_class'] === ''
                || ! is_string($definition['fingerprint'] ?? null) || $definition['fingerprint'] === ''
                || ! is_array($definition['command_contract'] ?? null)) {
                continue;
            }
            if ($selected !== null && $selected !== $definition) {
                // Reusing a build marker for different definitions is ambiguous.
                return null;
            }
            $selected = $definition;
        }

        /** @var array{workflow_class: class-string<Workflow>, fingerprint: string, command_contract: array<string, mixed>}|null $selected */
        return $selected === null ? null : [
            'build' => $build,
            'workflow_class' => $selected['workflow_class'],
            'fingerprint' => $selected['fingerprint'],
            'command_contract' => $selected['command_contract'],
        ];
    }

    private function recordScopes(string $connection, string $queueNames): void
    {
        $build = WorkerCompatibility::current();
        $namespaces = NamespaceScope::configured()->namespaces();
        $types = config('workflows.v2.types.workflows', []);
        if (! self::enabled() || (bool) config('workflows.v2.compatibility.disable_heartbeat_table', false)
            || $connection === '' || $build === null || $build === '*' || ! WorkerCompatibility::supports($build)
            || $namespaces === null || $namespaces === [] || ! is_array($types) || $types === []) {
            $this->forget();
            return;
        }

        $queues = array_values(
            array_unique(array_filter(
                array_map('trim', explode(',', $queueNames)),
                static fn (string $queue): bool => $queue !== ''
            ))
        );
        if ($queues === []) {
            $this->forget();
            return;
        }

        $identity = hash(
            'sha256',
            json_encode([$namespaces, $connection, $queues, $build, $types], JSON_THROW_ON_ERROR)
        );
        $now = now();
        $ttl = WorkerCompatibilityFleet::heartbeatTtlSeconds();
        if ($identity === $this->lastScope && $now->getTimestamp() - $this->lastWrite < max(1, intdiv($ttl, 3))) {
            return;
        }

        $database = ConfiguredV2Models::connection();
        if (! $database->getSchemaBuilder()->hasTable(self::TABLE)) {
            return;
        }

        $expires = $now->copy()
            ->addSeconds($ttl)
            ->format('Y-m-d H:i:s.u');
        $worker = $this->workerId ??= (string) Str::ulid();
        $rows = [];
        foreach ($types as $type => $class) {
            if (! is_string($type) || $type === '' || ! is_string($class) || ! is_subclass_of($class, Workflow::class)
                || ! (new ReflectionClass($class))->isInstantiable()
                || TypeRegistry::for($class) !== $type) {
                continue;
            }
            $fingerprint = WorkflowDefinition::fingerprint($class);
            if ($fingerprint === null) {
                continue;
            }
            $definition = json_encode([
                'workflow_class' => $class,
                'fingerprint' => $fingerprint,
                'command_contract' => RunCommandContract::snapshot($class),
            ], JSON_THROW_ON_ERROR);

            foreach ($namespaces as $namespace) {
                foreach ($queues as $queue) {
                    $rows[] = [
                        'scope_key' => self::scopeKey($namespace, $connection, $queue, $build, $type),
                        'worker_id' => $worker,
                        'definition' => $definition,
                        'expires_at' => $expires,
                    ];
                }
            }
        }

        $database->transaction(function () use ($database, $identity, $now, $rows, $worker): void {
            $table = $database->table(self::TABLE);
            (clone $table)->where('expires_at', '<', $now->format('Y-m-d H:i:s.u'))
                ->delete();
            if ($this->lastScope !== null && $identity !== $this->lastScope) {
                (clone $table)->where('worker_id', $worker)
                    ->delete();
            }
            foreach (array_chunk($rows, 200) as $batch) {
                $table->upsert($batch, ['scope_key', 'worker_id'], ['definition', 'expires_at']);
            }
        }, 3);
        $this->lastScope = $identity;
        $this->lastWrite = $now->getTimestamp();
    }

    private static function scopeKey(
        string $namespace,
        string $connection,
        string $queue,
        string $build,
        string $type
    ): string {
        return hash('sha256', json_encode([$namespace, $connection, $queue, $build, $type], JSON_THROW_ON_ERROR));
    }
}
