<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class AllCall implements YieldedCommand
{
    /**
     * @var list<ActivityCall|ChildWorkflowCall>
     */
    public readonly array $calls;

    public readonly ?string $kind;

    /**
     * @param iterable<int, mixed> $calls
     */
    public function __construct(iterable $calls)
    {
        $normalized = [];
        $kind = null;

        foreach ($calls as $call) {
            $callKind = match (true) {
                $call instanceof ActivityCall => 'activity',
                $call instanceof ChildWorkflowCall => 'child',
                default => null,
            };

            if ($callKind === null) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\all() currently supports activity() calls or child() calls only. Received [%s].',
                    get_debug_type($call),
                ));
            }

            if ($kind !== null && $kind !== $callKind) {
                throw new LogicException(
                    'Workflow\\V2\\all() currently supports homogeneous groups of activity() calls or child() calls. Mixed groups are not yet supported.',
                );
            }

            $kind ??= $callKind;
            $normalized[] = $call;
        }

        $this->calls = $normalized;
        $this->kind = $kind;
    }
}
