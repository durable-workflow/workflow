<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;
use Workflow\V2\Support\DurableOperationHandle;

final class DurableOperationCancelledException extends RuntimeException
{
    public function __construct(
        public readonly ?string $selectionGroupId,
        public readonly int|string|null $memberKey,
        public readonly ?int $memberIndex,
        public readonly string $operationKind,
        public readonly string $operationIdentity,
    ) {
        parent::__construct(sprintf(
            'Durable %s operation [%s] was cancelled.',
            $operationKind,
            $operationIdentity,
        ));
    }

    public static function forHandle(DurableOperationHandle $handle): self
    {
        return new self($handle->selectionGroupId, $handle->key, $handle->index, $handle->kind, $handle->identity);
    }

    public static function forOperation(string $kind, string $identity): self
    {
        return new self(null, null, null, $kind, $identity);
    }
}
