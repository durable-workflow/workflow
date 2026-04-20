<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class UpsertSearchAttributesCall implements YieldedCommand
{
    /**
     * @var array<string, scalar|null>
     */
    public readonly array $attributes;

    /**
     * @param array<string, scalar|null> $attributes
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

            if ($value !== null && ! is_scalar($value)) {
                throw new LogicException(sprintf(
                    'Workflow v2 search attribute [%s] must be a scalar value or null.',
                    $key,
                ));
            }

            if ($value === null) {
                $normalized[$key] = null;

                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                if (strlen($trimmed) > 191) {
                    throw new LogicException(sprintf(
                        'Workflow v2 search attribute [%s] must be up to 191 characters.',
                        $key,
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
