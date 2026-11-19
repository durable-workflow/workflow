<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use ReflectionProperty;
use Workflow\V2\Compensation\Activities\ForwardOperationActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

/**
 * Schedules the forward activity with the three arguments that runs recorded before the
 * `dw.compensation.registration-checks` patch use, so the caller value limit does not apply.
 */
final class TestPrePatchForwardWorkflow extends Workflow
{
    public function handle(array $identity, string $mode): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        $registered = $scope->register(new Obligation('first', 'test.operation', '1', [
            'mode' => $mode,
        ]));
        $id = (new ReflectionProperty($registered, 'id'))->getValue($registered);
        $value = Workflow::activity(
            ForwardOperationActivity::class,
            new ActivityOptions(maxAttempts: 1),
            ScopeIdentity::fromArray($identity)->toArray(),
            $id,
            'operator-a',
        );
        return [
            'receiptBytes' => strlen($value['receipt'] ?? ''),
        ];
    }
}
