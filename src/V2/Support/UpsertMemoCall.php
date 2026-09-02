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
            if (! is_string($key) || ! MemoPayload::isValidKey($key)) {
                throw new LogicException(sprintf(
                    'Workflow v2 memo keys must match %s.',
                    MemoPayload::KEY_PATTERN,
                ));
            }

            if ($value !== null) {
                try {
                    MemoPayload::envelope($value);
                } catch (\InvalidArgumentException $exception) {
                    throw new LogicException(sprintf(
                        'Workflow v2 memo [%s] must be a portable Avro value. Error: %s',
                        $key,
                        $exception->getMessage(),
                    ));
                }
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        $this->entries = $normalized;
    }
}
