<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\Models\WorkflowSearchAttribute;

final class UpsertSearchAttributesCall implements YieldedCommand
{
    /**
     * @var array<string, scalar|list<string>|null>
     */
    public readonly array $attributes;

    /**
     * @param array<string, scalar|list<string>|null> $attributes
     */
    public function __construct(array $attributes)
    {
        if ($attributes === []) {
            throw new LogicException('Workflow v2 upsertSearchAttributes requires at least one attribute.');
        }

        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key) !== 1) {
                throw new LogicException(
                    'Workflow v2 search attribute keys must be 1-64 URL-safe characters using letters, numbers, ".", "_", "-", and ":".'
                );
            }

            if ($value !== null && ! is_scalar($value) && ! is_array($value)) {
                throw new LogicException(sprintf(
                    'Workflow v2 search attribute [%s] must be a scalar value, string list, or null.',
                    $key,
                ));
            }

            if ($value === null) {
                $normalized[$key] = null;

                continue;
            }

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    throw new LogicException(sprintf(
                        'Workflow v2 search attribute [%s] list value must be a JSON array.',
                        $key,
                    ));
                }

                $list = [];

                foreach ($value as $entry) {
                    if (! is_string($entry)) {
                        throw new LogicException(sprintf(
                            'Workflow v2 search attribute [%s] list values must contain only strings.',
                            $key,
                        ));
                    }

                    $trimmed = trim($entry);

                    if (strlen($trimmed) > WorkflowSearchAttribute::MAX_KEYWORD_LENGTH) {
                        throw new LogicException(sprintf(
                            'Workflow v2 search attribute [%s] list values must be up to %d characters.',
                            $key,
                            WorkflowSearchAttribute::MAX_KEYWORD_LENGTH,
                        ));
                    }

                    $list[] = $trimmed;
                }

                $normalized[$key] = $list;

                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                if (strlen($trimmed) > WorkflowSearchAttribute::MAX_KEYWORD_LENGTH) {
                    throw new LogicException(sprintf(
                        'Workflow v2 search attribute [%s] must be up to %d characters.',
                        $key,
                        WorkflowSearchAttribute::MAX_KEYWORD_LENGTH,
                    ));
                }

                $normalized[$key] = $trimmed === '' ? null : $trimmed;

                continue;
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        $this->attributes = $normalized;
    }
}
