<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

require_once __DIR__ . '/../../../examples/configured-state-machines/providers/bootstrap.php';

/** Native adapter fixture, not the consumer's Payment state-machine implementation. */
#[Type('test.retained-provider.v1')]
final class TestRetainedProviderWorkflow extends Workflow
{
    public function handle(): array
    {
        $scope = new DurableScope($this, self::scope($this->runId()), 'provider-worker');
        return $scope->execute(
            new Obligation('payment.capture', 'example.provider', '1', self::descriptor($this->runId()))
        );
    }

    public static function scope(string $run): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'provider-example', 'order-1.payment', $run);
    }

    public static function forward(string $run): Request
    {
        return new Request(new Identity('shop-a', 'order-1', $run, 'capture-1'), 'capture', [
            'amountMinor' => 100,
            'currency' => 'USD',
        ]);
    }

    public static function refund(string $run): Request
    {
        return new Request(new Identity('shop-a', 'order-1', $run, 'refund-1'), 'refund', [
            'amountMinor' => 100,
            'currency' => 'USD',
        ], self::forward($run)->identity);
    }

    public static function descriptor(string $run): array
    {
        return [
            'forward' => self::forward($run)->toArray(),
            'compensation' => self::refund($run)->toArray(),
        ];
    }
}
