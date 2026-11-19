<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\CartStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2CartOrderReceiptTest extends TestCase
{
    use CartCheckoutMachineFixture;

    public static function substitutions(): array
    {
        return [
            'different original Cart' => ['cart'],
            'different admitted native run in the same purchase' => ['admission'],
            'different existing Payment publication' => ['publication'],
            'unselected receipt field' => ['extra'],
            'lost original paid publication' => ['lost-publication'],
        ];
    }

    #[DataProvider('substitutions')]
    public function testAnOrderCannotReplaceItsOriginalReceiptOrReconstructMissingPaymentEvidence(string $change): void
    {
        $origin = $this->startCart();
        $this->pay('cart-receipt-original-money');
        $key = $this->terms->subjectKey();
        $store = app(CartStore::class);
        $value = $store->order($key);
        $this->assertSame($origin->runId(), $value['rootRun']);
        $history = $this->history($origin->runId());
        $effects = $this->effects();
        if ($change === 'lost-publication') {
            $this->assertSame(1, DB::table('example_machine_publications')->where(
                'id',
                $value['paymentPublication']['id']
            )->delete());
        } else {
            $value = $this->substitute($value, $change, $origin->runId());
            $payload = PortableDescriptor::encode($value);
            DB::table('provider_cart_orders')->where('purchase_key', $key)->update([
                'receipt' => $payload,
                'receipt_sha256' => hash('sha256', $payload),
            ]);
        }
        $stored = DB::table('provider_cart_orders')->where('purchase_key', $key)->sole()->receipt;
        $refused = false;
        try {
            $store->order($key);
        } catch (LogicException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'A rehashed receipt or missing original publication must be refused.');
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($effects, $this->effects());
        $this->assertSame($stored, DB::table('provider_cart_orders')->where('purchase_key', $key)->sole()->receipt);
        $this->assertSame(1, DB::table('provider_cart_orders')->count());
    }

    private function substitute(array $value, string $change, string $rootRun): array
    {
        if ($change === 'cart') {
            $value['cartId'] = 'another-original-cart';
        } elseif ($change === 'admission') {
            $value['paymentBinding']['admission']['childRunId'] = $rootRun;
        } elseif ($change === 'publication') {
            $other = DB::table('example_machine_publications')->where('id', '<>', $value['paymentPublication']['id'])
                ->value('id');
            $this->assertIsString($other);
            $value['paymentPublication']['id'] = $other;
        } else {
            $value['unselectedEvidence'] = 'cannot-add-to-the-original-receipt';
        }
        return $value;
    }
}
