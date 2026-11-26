<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Trusted original partial-stock policy; workflow arguments and signals cannot select or replace it. */
final readonly class StockPolicy
{
    public const HANDLER = 'provider.stock';

    public const SHORTAGE_HANDLER = 'provider.unit-refund';

    public const SHORTAGE_KEY = 'shortage-refund';

    public const SHORTAGE_REQUEST = 'original-stock-shortage';

    public function __construct(
        private Authority $authority,
        private array $policies,
    ) {
        foreach ($policies as $key => $policy) {
            if (is_string($key) && $authority->unitMoney->selected($key) && $policy === StockAllocation::PARTIAL
                && $authority->recipe($key) === Catalogue::COMMON_RESERVE_FIRST) {
                throw new LogicException(
                    'fulfill-partial needs a payment-first unit-money grant: before capture,'
                    . ' reserve-first-common accepts only wait-complete or cancel-all.'
                );
            }
            if (! is_string($key) || ! $authority->unitMoney->selected($key) || ! in_array(
                $policy,
                self::policies($authority->recipe($key)),
                true
            )) {
                throw new LogicException(
                    'Partial-stock selection needs a payment-first unit-money grant and one explicit policy.'
                );
            }
        }
    }

    /**
     * Policies a recipe can apply. Reserve-first plans before capture: no money exists to repay a
     * shortage, and capturing less than the original quote is not a defined policy, so it has no
     * fulfill-partial.
     */
    public static function policies(string $recipe): array
    {
        return match ($recipe) {
            Catalogue::PAYMENT_FIRST => [StockAllocation::PARTIAL, StockAllocation::WAIT, StockAllocation::CANCEL],
            Catalogue::COMMON_RESERVE_FIRST => [StockAllocation::WAIT, StockAllocation::CANCEL],
            default => [],
        };
    }

    public function selected(string $key): bool
    {
        return array_key_exists($key, $this->policies);
    }

    public function grants(): array
    {
        return array_keys($this->policies);
    }

    public function policy(string $key): string
    {
        if (! $this->selected($key)) {
            throw new LogicException('The original business generation has no trusted partial-stock policy.');
        }
        return $this->policies[$key];
    }

    public function version(string $key): string
    {
        return $this->selected($key) ? '.stock-policy-v1.' . $this->policies[$key] : '';
    }

    public function handler(string $key): string
    {
        return $this->selected($key) ? self::HANDLER : 'provider.effect';
    }

    /**
     * The immutable plan recorded before booking; null means no final original plan exists yet.
     */
    public function recorded(string $key): ?array
    {
        [$row, $terms] = $this->authority->assignment($key);
        if (! $this->selected($key) || $row->stock_plan === null) {
            return null;
        }
        $plan = json_decode($row->stock_plan, true, 32, JSON_THROW_ON_ERROR);
        $original = Request::fromArray($terms->descriptor('order')['forward']);
        if (! is_array($plan) || PortableDescriptor::encode($plan) !== $row->stock_plan
            || ($plan['reservationFingerprint'] ?? null) !== $original->fingerprint()
            || ($plan['policy'] ?? null) !== $this->policy($key)
            || ! in_array($plan['action'] ?? null, ['fulfill', 'cancel'], true)
            || ($plan['requestedQuantity'] ?? null) !== $terms->quantity) {
            throw new LogicException('The recorded original stock plan changed owner, policy or reservation.');
        }
        return $plan;
    }

    /**
     * Booking-only Shipment books exactly the recorded fulfilled original units.
     */
    public function descriptor(OrderTerms $terms, string $role): array
    {
        $descriptor = $terms->descriptor($role);
        if ($role !== 'shipment' || ! $this->selected($terms->subjectKey())) {
            return $descriptor;
        }
        $plan = $this->recorded($terms->subjectKey());
        if ($plan === null || $plan['action'] !== 'fulfill' || $plan['fulfillUnits'] === []) {
            throw new LogicException('Booking requires the original recorded stock fulfilment plan.');
        }
        foreach (['forward', 'compensation'] as $part) {
            $descriptor[$part]['terms']['quantity'] = count($plan['fulfillUnits']);
            $descriptor[$part]['terms']['units'] = $plan['fulfillUnits'];
        }
        return $descriptor;
    }

    /**
     * Repayment of original units the recorded plan cannot fulfil; null when nothing is owed.
     */
    public function shortage(string $key): ?Obligation
    {
        $plan = $this->recorded($key);
        if ($plan === null || $plan['action'] !== 'fulfill' || $plan['requiredRefundUnits'] === []) {
            return null;
        }
        [, $terms] = $this->authority->assignment($key);
        $command = new UnitRefundCommand(
            $this->authority->unitMoney->original($terms),
            self::SHORTAGE_REQUEST,
            $plan['requiredRefundUnits']
        );
        return new Obligation(self::SHORTAGE_KEY, self::SHORTAGE_HANDLER, '1', [
            'version' => 1,
            'refund' => $command->toArray(),
        ]);
    }
}
