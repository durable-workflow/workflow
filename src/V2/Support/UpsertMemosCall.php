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
     * @param array<string, mixed> $memos Key-value pairs where values must be JSON-encodable
     */
    public function __construct(array $memos)
    {
        if ($memos === []) {
            throw new LogicException('Workflow v2 upsertMemos requires at least one memo.');
        }

        $normalized = [];

        foreach ($memos as $key => $value) {
            // Same key validation as search attributes
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key) !== 1) {
                throw new LogicException(
                    'Workflow v2 memo keys must be 1-64 URL-safe characters using letters, numbers, ".", "_", "-", and ":".'
                );
            }

            // Null means delete
            if ($value === null) {
                $normalized[$key] = null;

                continue;
            }

            // Validate JSON-encodability
            try {
                json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new LogicException(sprintf(
                    'Workflow v2 memo [%s] must be JSON-encodable. Error: %s',
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
