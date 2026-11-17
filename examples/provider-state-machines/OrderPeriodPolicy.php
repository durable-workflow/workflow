<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;

/** Trusted bootstrap binds each genuine Order to one immutable subscription period. */
final readonly class OrderPeriodPolicy
{
    /**
     * @param list<BillingPeriod> $grants
     */
    public function __construct(
        Authority $authority,
        private array $grants = [],
    ) {
        if (! array_is_list($grants)) {
            throw new LogicException('Order-period authority requires an explicit typed grant list.');
        }
        $orders = [];
        $periods = [];
        foreach ($grants as $period) {
            if (! $period instanceof BillingPeriod || $period->order === null) {
                throw new LogicException('An Order-period grant requires its genuine original Order terms.');
            }
            $key = $period->order->subjectKey();
            $authority->authorize($key, $authority->principal);
            if ($authority->recipe($key) !== FundingCatalogue::IMMEDIATE
                || $period->order->fulfillmentDeadline === null
                || $period->order->fulfillmentDeadline <= $period->paymentDeadline
                || isset($orders[$key]) || isset($periods[$period->subjectKey()])) {
                throw new LogicException(
                    'Each period needs its own Order and independent physical fulfillment budget.'
                );
            }
            $orders[$key] = true;
            $periods[$period->subjectKey()] = true;
        }
    }

    public function forOrder(string $key): ?BillingPeriod
    {
        foreach ($this->grants as $period) {
            if ($period->order->subjectKey() === $key) {
                return $period;
            }
        }
        return null;
    }

    /**
     * @return list<BillingPeriod>
     */
    public function grants(): array
    {
        return $this->grants;
    }

    public function version(string $key): string
    {
        $period = $this->forOrder($key);
        return $period === null ? '' : '.order-period-v1:' . $period->fingerprint();
    }
}
