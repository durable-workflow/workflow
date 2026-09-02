<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

/**
 * Fairness key for workload-class scheduling.
 *
 * A fairness key identifies the workload class the task belongs to —
 * commonly a tenant id, team name, or workflow type. Under contention on
 * a shared task queue, dispatch is rebalanced so no single fairness-key
 * class starves the others. Tasks with no fairness key are treated as
 * one shared class.
 *
 * Keys must be 1..{@see self::MAX_LENGTH} URL-safe characters and are
 * normalized to a canonical lowercase form so distinct casings of the
 * same logical class share a fairness budget.
 */
final class TaskFairnessKey
{
    public const MAX_LENGTH = 64;

    /**
     * Synthetic class label used when a task carries no fairness key. Tasks
     * without a key share this single implicit class so unmarked tenants are
     * not crowded out by a single keyed class. Exposed publicly so the
     * fairness scheduler and the observability surface bucket against the
     * same label.
     */
    public const DEFAULT_CLASS = '__default__';

    private const PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    public static function classFor(?string $fairnessKey): string
    {
        return ($fairnessKey === null || $fairnessKey === '')
            ? self::DEFAULT_CLASS
            : $fairnessKey;
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('Fairness key must be a string or null.');
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            throw new LogicException(sprintf(
                'Fairness key must be 1..%d URL-safe characters using letters, numbers, ".", "_", "-", or ":".',
                self::MAX_LENGTH,
            ));
        }

        return strtolower($trimmed);
    }

    public static function normalizeWeight(mixed $value): int
    {
        if ($value === null) {
            return 1;
        }

        if (! is_int($value) || $value < 1 || $value > 1000) {
            throw new LogicException('Fairness weight must be an integer in the range 1..1000.');
        }

        return $value;
    }
}
