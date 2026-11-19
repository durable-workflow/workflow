<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** @internal Retries within one native owner adopt its route; a fresh authorized recovery owner gets a fresh route. */
final class ChildRecoveryRequest
{
    public static function key(string $operationId, ?string $ownerRunId): string
    {
        PortableDescriptor::identifier($operationId, 'original child obligation');
        if ($ownerRunId === null) {
            return 'parent-' . $operationId;
        }
        PortableDescriptor::identifier($ownerRunId, 'requesting native run');
        return 'parent-v2-' . hash('sha256', PortableDescriptor::encode([$operationId, $ownerRunId]));
    }
}
