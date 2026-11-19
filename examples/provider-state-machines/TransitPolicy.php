<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;

/**
 * Trusted customer remedy for a parcel the carrier confirms lost or damaged before recipient handover.
 * Bootstrap selects it per business generation; workflow arguments and signals cannot select or replace it.
 */
final readonly class TransitPolicy
{
    public const REFUND = 'refund';

    /**
     * Dispatch an available replacement; a definitively unavailable replacement refunds instead.
     */
    public const REPLACE = 'replace';

    public function __construct(
        private Authority $authority,
        private array $policies,
    ) {
        foreach ($policies as $key => $policy) {
            if (! is_string($key) || ! $authority->unitMoney->authorized($key)
                || $authority->recipe($key) !== FinancialCatalogue::AFTER_STOCK
                || ! in_array($policy, [self::REFUND, self::REPLACE], true)) {
                throw new LogicException(
                    'A transit remedy needs a stock-triggered unit-money grant and one explicit policy.'
                );
            }
        }
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
            throw new LogicException('The original business generation has no trusted transit remedy policy.');
        }
        return $this->policies[$key];
    }

    public function version(string $key): string
    {
        return $this->selected($key) ? '.transit-remedy-v1.' . $this->policies[$key] : '';
    }
}
