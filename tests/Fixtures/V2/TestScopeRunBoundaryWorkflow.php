<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Enums\ParentClosePolicy;
use function Workflow\V2\signal;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Workflow;

#[Signal('resume')]
final class TestScopeRunBoundaryWorkflow extends Workflow
{
    public function handle(array $identity, string $mode, int $generation = 0, ?array $childIdentity = null): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        if ($generation === 0) {
            $scope->execute(new Obligation('first', 'test.operation', '1', [
                'mode' => $mode === 'unknown' ? 'lost-ack' : 'complete',
            ]), new ActivityOptions(maxAttempts: 1));
            if ($mode === 'continue') {
                if ($childIdentity !== null) {
                    $scope->participate('delivery', ScopeIdentity::fromArray($childIdentity));
                    self::select([
                        'child' => static fn () => self::child(
                            TestScopeBoundaryChildWorkflow::class,
                            new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::Abandon),
                            $childIdentity
                        ),
                        'ready' => static fn () => self::timer(0),
                    ]);
                }
                return self::continueAsNew($identity, $mode, 1, $childIdentity);
            }
            self::activity(TestRedriveSecondActivity::class, new ActivityOptions(maxAttempts: 1), 'boundary');
        } else {
            signal('resume');
        }
        // This is new work, so redrive's reused activity prefix cannot authorize it in the old scope.
        $scope->execute(new Obligation('second', 'test.operation', '1', []));
        return [
            'status' => 'done',
        ];
    }
}
