<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class StraightLineWorkflowRequiredException extends LogicException
{
    public static function forWorkflow(string $class): self
    {
        return new self(sprintf(
            'Workflow v2 workflow [%s] must use straight-line helpers and must not yield.',
            $class,
        ));
    }

    public static function forAsyncCallback(): self
    {
        return new self('Workflow v2 async() callbacks must use straight-line helpers and must not yield.');
    }

    public static function forCallback(): self
    {
        return new self('Workflow v2 callbacks must use straight-line helpers and must not yield.');
    }
}
