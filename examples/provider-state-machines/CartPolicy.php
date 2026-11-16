<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Compensation\Definition\Obligation;

/** Bootstrap-selected cart route and stock obligation for an already authorized original purchase. */
final readonly class CartPolicy
{
    public function __construct(
        private Authority $authority,
        private array $purchases
    ) {
    }

    public function selected(string $key): bool
    {
        return in_array($key, $this->purchases, true);
    }

    public function rootClass(string $key): string
    {
        return $this->selected($key) ? CartCheckout::class : Catalogue::rootClass($this->authority->recipe($key));
    }

    public function stockObligation(string $key): Obligation
    {
        if ($this->selected($key)) {
            return app(CartStore::class)->stockObligation($key);
        }
        [, $terms] = $this->authority->assignment($key);
        return new Obligation('effect', 'provider.effect', '1', $terms->descriptor('order'));
    }
}
