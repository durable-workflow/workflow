<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;

final class SelectionResult
{
    /**
     * @param array<int|string, DurableOperationHandle> $handles
     */
    public function __construct(
        public readonly int|string $key,
        public readonly int $index,
        public readonly string $kind,
        public readonly string $identity,
        public readonly mixed $value,
        public readonly ?Throwable $failure,
        public readonly DurableOperationHandle $winner,
        public readonly array $handles,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }

    public function result(): mixed
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->value;
    }

    /**
     * @return array<int|string, DurableOperationHandle>
     */
    public function remaining(): array
    {
        $remaining = $this->handles;
        unset($remaining[$this->key]);

        return $remaining;
    }
}
