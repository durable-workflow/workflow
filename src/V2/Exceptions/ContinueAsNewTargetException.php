<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class ContinueAsNewTargetException extends LogicException
{
    public function __construct(
        public readonly string $reason,
        string $message
    ) {
        parent::__construct($reason . ': ' . $message);
    }
}
