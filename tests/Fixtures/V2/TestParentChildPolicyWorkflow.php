<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\cancellationShield;
use function Workflow\V2\child;
use Workflow\V2\Enums\CancellationPolicy;
use function Workflow\V2\select;
use Workflow\V2\Support\ChildWorkflowOptions;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestParentChildPolicyWorkflow extends Workflow
{
    public function handle(string $policy, bool $parallel = false, bool $selection = false): void
    {
        try {
            $child = static fn () => child(
                TestChildPolicyCleanupWorkflow::class,
                new ChildWorkflowOptions(cancellationPolicy: CancellationPolicy::from($policy)),
            );
            if ($selection) {
                $selected = select([
                    'child' => $child,
                    'ready' => static fn () => timer(0),
                ]);
                $selected->handles['child']->await();
            } elseif ($parallel) {
                all([
                    $child,
                    static fn () => activity(TestGreetingActivity::class, 'ordinary work'),
                ]);
            } else {
                $child();
            }
        } finally {
            cancellationShield(static fn () => upsertMemo([
                'parent_cleanup' => 'complete',
            ]));
        }
    }
}
