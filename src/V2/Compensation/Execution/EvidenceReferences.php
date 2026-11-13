<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * Leaves room for native identity metadata inside the unchanged bounded journal envelope.
 *
 * @internal
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class EvidenceReferences
{
    public const MAX_REFERENCES = 32;

    public static function validate(array $references): void
    {
        if (! array_is_list($references)) {
            throw new InvalidArgumentException('Evidence references must be a list.');
        }
        if (count($references) > self::MAX_REFERENCES) {
            throw new InvalidArgumentException('Compensation data exceeds the evidence reference limit.');
        }
        foreach ($references as $reference) {
            if (! is_string($reference)) {
                throw new InvalidArgumentException('Evidence requires immutable reference strings.');
            }
            PortableDescriptor::identifier($reference, 'evidence reference');
        }
    }
}
