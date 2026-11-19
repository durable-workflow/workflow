<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\CompensationScopes;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Recovery\ScopeRetention;
use Workflow\V2\Compensation\Security\ScopeAuthorization;

/**
 * Host wiring for the example: what a service provider does in a real application.
 */
final class OrderCompensation
{
    public const APPLICATION = 'example.orders';

    public const NAMESPACE = 'compensation-example';

    public const PRINCIPAL = 'order-worker';

    public static function configure(Container $app, ConnectionInterface $db): EffectLedger
    {
        $ledger = new EffectLedger($db);
        $app->instance(ScopeAuthorization::class, new OrderScopeAuthorization());
        $app->instance(HandlerRegistry::class, new HandlerRegistry([
            'stock.reserve' => [
                '1' => new LedgerOperation($ledger, 'stock.reserve'),
            ],
            'carrier.book' => [
                '1' => new LedgerOperation($ledger, 'carrier.book'),
            ],
        ]));
        return $ledger;
    }

    /**
     * One generation per order run: the run ID is unique to that run.
     */
    public static function orderScope(string $orderId, string $generation): ScopeIdentity
    {
        return new ScopeIdentity(self::APPLICATION, self::NAMESPACE, 'order.' . $orderId, $generation);
    }

    public static function shipmentScope(string $orderId, string $generation): ScopeIdentity
    {
        return new ScopeIdentity(self::APPLICATION, self::NAMESPACE, 'shipment.' . $orderId, $generation);
    }

    /**
     * Cancel an order: routes to the open Order run, or to recovery after it closed.
     */
    public static function cancel(ScopeIdentity $order, string $requestKey): array
    {
        return app(ScopeRecovery::class)->request($order, $requestKey, self::PRINCIPAL, 'customer-cancelled');
    }

    public static function describe(ScopeIdentity $scope): array
    {
        return app(ScopeRecovery::class)->describe($scope, self::PRINCIPAL);
    }

    /**
     * Retire a verified scope once its runs have closed. Its obligation inputs and forward values are
     * erased and describe() reports it released. Unresolved compensation cannot be released.
     */
    public static function release(ScopeIdentity $scope): void
    {
        app(ScopeRetention::class)->release($scope, self::PRINCIPAL);
    }
}
