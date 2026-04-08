<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class AllCall implements YieldedCommand
{
    /**
     * @var list<ChildWorkflowCall>
     */
    public readonly array $calls;

    /**
     * @param iterable<int, mixed> $calls
     */
    public function __construct(iterable $calls)
    {
        $normalized = [];

        foreach ($calls as $call) {
            if (! $call instanceof ChildWorkflowCall) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\all() currently supports child() calls only. Received [%s].',
                    get_debug_type($call),
                ));
            }

            $normalized[] = $call;
        }

        $this->calls = $normalized;
    }
}
