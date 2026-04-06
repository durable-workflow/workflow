<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class WorkerCompatibility
{
    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        $configured = self::normalizeList(config('workflows.v2.compatibility.supported'));

        if ($configured === []) {
            $current = self::current();

            return $current === null ? [] : [$current];
        }

        if (in_array('*', $configured, true)) {
            return ['*'];
        }

        return $configured;
    }

    public static function current(): ?string
    {
        return self::normalize(config('workflows.v2.compatibility.current'));
    }

    public static function supports(?string $required): bool
    {
        $required = self::normalize($required);

        if ($required === null) {
            return true;
        }

        $supported = self::supported();

        return in_array('*', $supported, true)
            || in_array($required, $supported, true);
    }

    public static function mismatchReason(?string $required): ?string
    {
        $required = self::normalize($required);

        if ($required === null || self::supports($required)) {
            return null;
        }

        $supported = self::supported();

        return sprintf(
            'Requires compatibility [%s]; this worker supports [%s].',
            $required,
            $supported === [] ? 'none' : implode(', ', $supported),
        );
    }

    private static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = array_map(
            static fn (mixed $item): ?string => self::normalize($item),
            $value,
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (?string $item): bool => $item !== null,
        )));
    }
}
