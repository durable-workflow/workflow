<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

final class WorkflowInstanceId
{
    public const MAX_LENGTH = 191;

    public const WORKER_CHILD_MAX_LENGTH = 128;

    private const PATTERN = '/\A[A-Za-z0-9._:-]+\z/';

    public static function assertValid(string $instanceId): void
    {
        if (! self::isValid($instanceId)) {
            throw new LogicException(self::requirementMessage());
        }
    }

    public static function isValid(string $instanceId): bool
    {
        return $instanceId !== ''
            && strlen($instanceId) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $instanceId) === 1;
    }

    public static function isValidWorkerChildId(string $instanceId): bool
    {
        return strlen($instanceId) <= self::WORKER_CHILD_MAX_LENGTH && self::isValid($instanceId);
    }

    public static function requirementMessage(): string
    {
        return sprintf(
            'Workflow instance ids must be non-empty URL-safe strings up to %d characters using only letters, numbers, ".", "_", "-", and ":".',
            self::MAX_LENGTH,
        );
    }

    public static function validationMessage(string $field = 'workflow_id', int $maxLength = self::MAX_LENGTH): string
    {
        return sprintf(
            'The %s field must be a non-empty URL-safe string up to %d characters using only letters, numbers, ".", "_", "-", and ":".',
            $field,
            $maxLength,
        );
    }
}
