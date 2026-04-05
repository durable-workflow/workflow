<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum DuplicateStartPolicy: string
{
    case RejectDuplicate = 'reject_duplicate';
    case ReturnExistingActive = 'return_existing_active';
}
