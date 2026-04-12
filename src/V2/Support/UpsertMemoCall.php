<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class UpsertMemoCall implements YieldedCommand
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $entries;

    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(array $entries)
    {
        if ($entries === []) {
            throw new LogicException('Workflow v2 upsertMemo requires at least one entry.');
        }

        $normalized = [];

        foreach ($entries as $key => $value) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                throw new LogicException(
                    'Workflow v2 memo keys must be non-empty strings up to 64 characters.'
                );
            }

            $normalized[$key] = self::normalizeValue($value, sprintf('memo.%s', $key));
        }

        ksort($normalized);

        $this->entries = $normalized;
    }

    private static function normalizeValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new LogicException(sprintf(
                'Workflow v2 %s values must be JSON-like scalars, null, arrays, or objects.',
                $path,
            ));
        }

        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $entry): mixed => self::normalizeValue($entry, $path . '[]'),
                $value,
            );
        }

        $normalized = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                throw new LogicException(sprintf(
                    'Workflow v2 %s keys must be non-empty strings up to 64 characters.',
                    $path,
                ));
            }

            $normalized[$key] = self::normalizeValue($entry, sprintf('%s.%s', $path, $key));
        }

        ksort($normalized);

        return $normalized;
    }
}
