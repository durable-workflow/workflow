<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ProviderStateMachines\IncomingPaymentInspectionActivity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

/** Observation qualification only; a closed technical run is not a settled incoming-payment duty. */
#[Signal('incoming-observed')]
final class TestIncomingPaymentInspectionWorkflow extends Workflow
{
    public function handle(array $identity, array $descriptor): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'incoming-worker');
        $scope->register(new Obligation('incoming.watch', 'test.incoming.watch', '1', $descriptor));
        $before = self::activity(IncomingPaymentInspectionActivity::class, $identity, $descriptor);
        self::await('incoming-observed');
        return [
            'before' => $before,
            'after' => self::activity(IncomingPaymentInspectionActivity::class, $identity, $descriptor),
        ];
    }
}
