<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

final class WorkflowInstanceId
{
    public const MAX_LENGTH = 26;

    public static function assertValid(string $instanceId): void
    {
        if (trim($instanceId) === '' || strlen($instanceId) > self::MAX_LENGTH) {
            throw new LogicException(self::requirementMessage());
        }
    }

    public static function requirementMessage(): string
    {
        return sprintf(
            'Workflow instance ids must be non-empty strings no longer than %d characters.',
            self::MAX_LENGTH,
        );
    }
}
