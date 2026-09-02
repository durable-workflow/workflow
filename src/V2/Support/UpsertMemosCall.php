<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class UpsertMemosCall implements YieldedCommand
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $memos;

    /**
     * @param array<string, mixed> $memos Key-value pairs containing portable Avro values
     */
    public function __construct(array $memos)
    {
        if ($memos === []) {
            throw new LogicException('Workflow v2 upsertMemos requires at least one memo.');
        }

        $normalized = [];

        foreach ($memos as $key => $value) {
            if (! is_string($key) || ! MemoPayload::isValidKey($key)) {
                throw new LogicException(sprintf(
                    'Workflow v2 memo keys must match %s.',
                    MemoPayload::KEY_PATTERN,
                ));
            }

            // Null means delete
            if ($value === null) {
                $normalized[$key] = null;

                continue;
            }

            try {
                MemoPayload::envelope($value);
            } catch (\InvalidArgumentException $e) {
                throw new LogicException(sprintf(
                    'Workflow v2 memo [%s] must be a portable Avro value. Error: %s',
                    $key,
                    $e->getMessage(),
                ));
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        $this->memos = $normalized;
    }
}
