<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommonFundingMachineFixture;
use Tests\TestCase;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native source integration; no installed, cold-process or complete customer-matrix qualification. */
final class V2CommonFundingMachineTest extends TestCase
{
    use CommonFundingMachineFixture;

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], array_values(array_filter(
            FundingCatalogue::baseRecipes(),
            static fn (string $recipe): bool => $recipe !== FundingCatalogue::BANK
        )));
    }

    #[DataProvider('policies')]
    public function testAllPoliciesUseTheSameNativeRouteAndOriginalPhysicalDelivery(string $recipe): void
    {
        $this->assertOriginalFundingRoute($recipe);
    }
}
