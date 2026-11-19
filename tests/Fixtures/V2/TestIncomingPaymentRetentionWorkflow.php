<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

/** Native retained receivable qualification, separate from Order fulfillment and Payment route policy. */
final class TestIncomingPaymentRetentionWorkflow extends Workflow
{
    public function handle(array $identity, array $input, bool $execute): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'incoming-worker');
        $watch = $scope->register(new Obligation('incoming.watch', 'test.incoming.retained', '1', $input));
        return $execute ? $watch->execute(new ActivityOptions(maxAttempts: 1)) : [
            'referenceNotOpened' => true,
        ];
    }
}
