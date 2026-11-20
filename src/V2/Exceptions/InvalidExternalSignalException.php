<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use InvalidArgumentException;

/**
 * A durable signal-external request was refused in workflow code, before any
 * history was recorded or anything was sent.
 *
 * @experimental Durable signal-external may change in a minor release.
 * See docs/api-stability.md#durable-signal-external.
 */
final class InvalidExternalSignalException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $field,
        public readonly string $problem,
    ) {
        parent::__construct(sprintf('Durable signal-external %s is invalid: %s.', $field, $problem));
    }
}
