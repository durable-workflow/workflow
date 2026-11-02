<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class BridgeCommandIdentityConflict extends LogicException
{
    public function __construct()
    {
        parent::__construct('Bridge command identity conflicts with its retained receipt.');
    }
}
