<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use ReflectionMethod;
use Workflow\V2\Activity;
use Workflow\V2\Exceptions\ActivityEntryArgumentsMismatchException;

/** @internal Preserves recorded input across method dependency injection. */
final class ActivityEntryArguments
{
    public static function resolve(Activity $activity, array $recorded, ReflectionMethod $entry): array
    {
        $resolved = $activity->resolveMethodDependencies($recorded, $entry);

        // A new injected value must not push recorded input past the last
        // parameter, where PHP would silently discard it. Existing variadic
        // handlers can consume those values and keep their ordinary behavior.
        if (! $entry->isVariadic() && count($resolved) > $entry->getNumberOfParameters()
            && count($resolved) > count($recorded)) {
            throw new ActivityEntryArgumentsMismatchException(
                $activity->execution->activity_type ?? $activity::class,
                $entry->getName(),
            );
        }

        return $resolved;
    }
}
