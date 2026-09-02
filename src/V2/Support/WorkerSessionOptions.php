<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;

/**
 * Durable affinity contract for a sequence of activity calls.
 *
 * A worker session is created lazily by the matching/server layer when the
 * first in-session activity is admitted. Subsequent activities with the same
 * session id are routed to the current lease holder until the session is
 * closed, expires, or is reacquired after holder failure.
 *
 * @api Stable v2 authoring API for worker-session activity affinity.
 */
final class WorkerSessionOptions
{
    public readonly ?string $sessionId;

    public readonly ?string $connection;

    public readonly ?string $queue;

    /**
     * @var list<string>
     */
    public readonly array $requirements;

    public readonly ?int $leaseSeconds;

    public readonly ?int $ttlSeconds;

    public readonly ?int $maxConcurrentActivities;

    public readonly bool $createIfMissing;

    public readonly bool $allowReacquireAfterFailure;

    /**
     * @param list<string> $requirements Fleet capabilities the holding worker must advertise.
     */
    public function __construct(
        ?string $sessionId = null,
        ?string $connection = null,
        ?string $queue = null,
        array $requirements = [],
        ?int $leaseSeconds = null,
        ?int $ttlSeconds = null,
        ?int $maxConcurrentActivities = null,
        bool $createIfMissing = true,
        bool $allowReacquireAfterFailure = true,
    ) {
        $this->sessionId = self::normalizeOptionalString($sessionId, 'session id');
        $this->connection = self::normalizeOptionalString($connection, 'connection');
        $this->queue = self::normalizeOptionalString($queue, 'queue');
        $this->requirements = self::normalizeRequirements($requirements);
        $this->leaseSeconds = self::normalizePositiveInt($leaseSeconds, 'leaseSeconds');
        $this->ttlSeconds = self::normalizePositiveInt($ttlSeconds, 'ttlSeconds');
        $this->maxConcurrentActivities = self::normalizePositiveInt(
            $maxConcurrentActivities,
            'maxConcurrentActivities',
        );
        $this->createIfMissing = $createIfMissing;
        $this->allowReacquireAfterFailure = $allowReacquireAfterFailure;
    }

    public static function named(string $sessionId, ?string $queue = null): self
    {
        return new self(sessionId: $sessionId, queue: $queue);
    }

    public function withSessionId(string $sessionId): self
    {
        return new self(
            sessionId: $sessionId,
            connection: $this->connection,
            queue: $this->queue,
            requirements: $this->requirements,
            leaseSeconds: $this->leaseSeconds,
            ttlSeconds: $this->ttlSeconds,
            maxConcurrentActivities: $this->maxConcurrentActivities,
            createIfMissing: $this->createIfMissing,
            allowReacquireAfterFailure: $this->allowReacquireAfterFailure,
        );
    }

    /**
     * @return array{
     *     session_id: string,
     *     connection: string|null,
     *     queue: string|null,
     *     requirements: list<string>,
     *     lease_seconds: int|null,
     *     ttl_seconds: int|null,
     *     max_concurrent_activities: int|null,
     *     create_if_missing: bool,
     *     allow_reacquire_after_failure: bool
     * }
     */
    public function toSnapshot(): array
    {
        if ($this->sessionId === null) {
            throw new InvalidArgumentException('Worker session id must be set before snapshotting activity options.');
        }

        return [
            'session_id' => trim($this->sessionId),
            'connection' => $this->connection,
            'queue' => $this->queue,
            'requirements' => $this->normalizedRequirements(),
            'lease_seconds' => $this->leaseSeconds,
            'ttl_seconds' => $this->ttlSeconds,
            'max_concurrent_activities' => $this->maxConcurrentActivities,
            'create_if_missing' => $this->createIfMissing,
            'allow_reacquire_after_failure' => $this->allowReacquireAfterFailure,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizedRequirements(): array
    {
        return $this->requirements;
    }

    private static function normalizeOptionalString(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Worker session %s must be a non-empty string.', $field));
        }

        return $value;
    }

    private static function normalizePositiveInt(?int $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('Worker session %s must be a positive integer.', $field));
        }

        return $value;
    }

    /**
     * @param list<string> $requirements
     * @return list<string>
     */
    private static function normalizeRequirements(array $requirements): array
    {
        $normalized = [];

        foreach (array_values($requirements) as $position => $requirement) {
            if (! is_string($requirement) || trim($requirement) === '') {
                throw new InvalidArgumentException(sprintf(
                    'Worker session requirement at index %d must be a non-empty string.',
                    $position,
                ));
            }

            $normalized[] = trim($requirement);
        }

        return array_values(array_unique($normalized));
    }
}
