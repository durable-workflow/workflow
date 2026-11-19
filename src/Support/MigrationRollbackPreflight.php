<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Migrations\Migrator;
use LogicException;

/**
 * Checks every package migration in a rollback before the migrator runs any down().
 *
 * A guarded down() alone refuses too late: migrations later in the same batch
 * roll back first and have already dropped their schema by then.
 */
final class MigrationRollbackPreflight
{
    private const DIRECTORY = __DIR__ . '/../migrations';

    public function handle(MigrationsStarted $event): void
    {
        if ($event->method !== 'down' || ($event->options['pretend'] ?? false)) {
            return;
        }
        $migrator = app('migrator');
        if (! $migrator instanceof Migrator) {
            return;
        }
        $refusals = self::refusals($migrator, self::rollbackSet($migrator, $event->options));
        if ($refusals !== []) {
            throw new LogicException(
                'Schema rollback refused before any migration ran. ' . implode(' ', $refusals)
            );
        }
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    public static function refusals(Migrator $migrator, array $names): array
    {
        $refusals = [];
        foreach ($names as $name) {
            $path = self::DIRECTORY . '/' . $name . '.php';
            if (! is_file($path)) {
                continue;
            }
            $migration = require $path;
            if (! $migration instanceof WorkflowMigration) {
                continue;
            }
            $refusal = $migration->rollbackRefusal($migrator->resolveConnection($migration->getConnection()));
            if ($refusal !== null && ! in_array($refusal, $refusals, true)) {
                $refusals[] = $refusal;
            }
        }
        return $refusals;
    }

    /**
     * Mirrors Migrator::getMigrationsForRollback() and reset(): rollback passes
     * step and batch (or nothing), while reset passes only pretend.
     *
     * @return list<string>
     */
    private static function rollbackSet(Migrator $migrator, array $options): array
    {
        $repository = $migrator->getRepository();
        if (! $repository->repositoryExists()) {
            return [];
        }
        if (array_keys($options) === ['pretend']) {
            return array_values($repository->getRan());
        }
        $steps = (int) ($options['step'] ?? 0);
        $batch = (int) ($options['batch'] ?? 0);
        if ($steps > 0) {
            $rows = $repository->getMigrations($steps);
        } elseif ($batch > 0) {
            $rows = $repository->getMigrationsByBatch($batch);
        } else {
            $rows = $repository->getLast();
        }
        return array_values(array_map(static fn (mixed $row): string => (string) ((object) $row)->migration, $rows));
    }
}
