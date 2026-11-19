<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * The recorded outcome of a durable workflow-to-workflow signal.
 *
 * @experimental Durable signal-external may change in a minor release.
 * See docs/api-stability.md#durable-signal-external.
 */
enum ExternalSignalOutcome: string
{
    case Delivered = 'delivered';
    case TargetNotFound = 'target_not_found';
    case TargetClosed = 'target_closed';
    case Rejected = 'rejected';
}
