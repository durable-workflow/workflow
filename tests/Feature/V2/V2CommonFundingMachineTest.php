<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommonFundingMachineFixture;
use Tests\TestCase;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native source integration; no installed, cold-process or complete customer-matrix qualification. */
final class V2CommonFundingMachineTest extends TestCase
{
    use CommonFundingMachineFixture;

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], FundingCatalogue::baseRecipes());
    }

    #[DataProvider('policies')]
    public function testAllPoliciesUseTheSameNativeRouteAndOriginalPhysicalDelivery(string $recipe): void
    {
        $origin = $this->startFunding($recipe);
        if ($recipe === FundingCatalogue::BANK) {
            $this->receive('original-bank-transfer', 1499);
            $this->wakePayment('bank-transfer-confirmed');
        }
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame(
            $recipe === FundingCatalogue::AFTER_DELIVERY ? 'ready' : 'paid',
            $this->state('payment'),
            $this->progress()
        );
        $origin->withRequestId('fulfill-original-purchase')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        $this->recordPhysical('delivered');
        $this->assertSame('fulfilled', $this->state('order'), $this->progress());
        $this->assertSame('paid', $this->state('payment'));
        $this->assertSame('delivered', $this->state('shipment'));
        $publications = DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), 'payment')
        )->get();
        $states = $publications->map(
            static fn (object $row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)
        )
            ->all();
        usort($states, static fn (array $a, array $b): int => (int) $a['revision'] <=> (int) $b['revision']);
        $this->assertSame(['ready', 'collected', 'paid'], array_column($states, 'state'));
        if ($recipe === FundingCatalogue::BANK) {
            $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        } elseif ($recipe === FundingCatalogue::AFTER_DELIVERY) {
            $this->assertSame(
                ['authorize', 'reserve', 'ship-book', 'deduct-stock', 'capture-authorized'],
                $this->effects()
            );
        }
        $before = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($before, $this->effects());
    }
}
