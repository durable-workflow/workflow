<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ContinueAsNewTarget: string
{
    case Inherited = 'inherited';
    case Promoted = 'promoted';
}
