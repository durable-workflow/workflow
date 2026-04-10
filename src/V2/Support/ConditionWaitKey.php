<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

final class ConditionWaitKey
{
    public const MAX_LENGTH = 128;

    private const PATTERN = '/\A[A-Za-z0-9._:-]+\z/';

    public static function normalize(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $key = trim($key);

        if (! self::isValid($key)) {
            throw new LogicException(self::requirementMessage());
        }

        return $key;
    }

    public static function isValid(string $key): bool
    {
        return $key !== ''
            && strlen($key) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $key) === 1;
    }

    public static function requirementMessage(): string
    {
        return sprintf(
            'Condition wait keys must be non-empty URL-safe strings up to %d characters using only letters, numbers, ".", "_", "-", and ":".',
            self::MAX_LENGTH,
        );
    }
}
