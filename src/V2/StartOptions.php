<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\DuplicateStartPolicy;

final class StartOptions
{
    public function __construct(
        public readonly DuplicateStartPolicy $duplicateStartPolicy = DuplicateStartPolicy::RejectDuplicate,
    ) {
    }

    public static function rejectDuplicate(): self
    {
        return new self(DuplicateStartPolicy::RejectDuplicate);
    }

    public static function returnExistingActive(): self
    {
        return new self(DuplicateStartPolicy::ReturnExistingActive);
    }
}
