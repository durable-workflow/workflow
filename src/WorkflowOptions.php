<?php

declare(strict_types=1);

namespace Workflow;

use InvalidArgumentException;

final class WorkflowOptions
{
    public function __construct(
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
        public readonly int $overlapReleaseAfter = 0,
    ) {
        if ($overlapReleaseAfter < 0) {
            throw new InvalidArgumentException('overlapReleaseAfter must be a nonnegative number of seconds.');
        }
    }

    public function __unserialize(array $data): void
    {
        // Options serialized before this setting was introduced keep their zero delay.
        $options = self::set($data);
        $this->connection = $options->connection;
        $this->queue = $options->queue;
        $this->overlapReleaseAfter = $options->overlapReleaseAfter;
    }

    /**
     * @param array{connection?: string|null, queue?: string|null, overlapReleaseAfter?: int} $options
     */
    public static function set(array $options): self
    {
        return new self(
            $options['connection'] ?? null,
            $options['queue'] ?? null,
            array_key_exists('overlapReleaseAfter', $options) ? $options['overlapReleaseAfter'] : 0,
        );
    }
}
