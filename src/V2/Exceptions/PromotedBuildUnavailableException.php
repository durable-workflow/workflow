<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

final class PromotedBuildUnavailableException extends RuntimeException
{
    public const REASON = 'promoted_build_unavailable';

    public function __construct()
    {
        parent::__construct('No live eligible worker on the promoted build serves this workflow type and queue.');
    }
}
