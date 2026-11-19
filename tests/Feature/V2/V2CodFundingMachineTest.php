<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPayment;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\CodInspectionStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CodFundingMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded native COD source cases; installed and full customer-matrix acceptance remain separate. */
final class V2CodFundingMachineTest extends TestCase
{
    use CodFundingMachineFixture;

    public static function policies(): array
    {
        return array_map(static fn (string $recipe): array => [$recipe], CodFundingPolicy::recipes());
    }

    #[DataProvider('policies')]
    public function testOriginalCarrierOrderSeparatesPayerCollectionFromLateMerchantSettlement(string $recipe): void
    {
        $origin = $this->startCod($recipe);
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $this->assertSame(CodFundingPayment::class, $this->child('payment')->workflow_class);
        $origin->withRequestId('fulfill-original-cod')
            ->signal('fulfill');
        $this->pump();
        $this->assertSame('booked', $this->state('shipment'), $this->progress());
        $this->recordPhysical('handed-over');
        if ($recipe === CodFundingPolicy::AFTER) {
            $this->recordPhysical('delivered');
        }
        $receipt = $this->collect('original-collection', 1499);
        $this->wakePayment('original-payer-paid');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        if ($recipe === CodFundingPolicy::BEFORE) {
            $this->recordPhysical('delivered');
        }
        $this->assertSame('delivered', $this->state('shipment'), $this->progress());
        $this->assertSame('ready', $this->state('order'));
        $this->remit($receipt, 'first-merchant-remittance', 500);
        $this->wakePayment('partial-merchant-receipt');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        $originalDeadline = $this->terms->codPayment->remittanceDeadline;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($originalDeadline + 1));
        $this->pump();
        $this->wakePayment('merchant-deadline-passed');
        $this->assertSame('ready', $this->state('order'), $this->progress());
        $this->assertSame('collected', $this->state('payment'));
        $facts = $this->cod->inspect($this->terms->codPayment)['facts'];
        $this->assertSame(999, $facts['outstanding_remittance_minor']);
        $this->assertTrue($facts['remittance_deadline_passed']);
        $this->assertSame(
            $originalDeadline,
            $this->cod->inspect($this->terms->codPayment)['original']['remittanceDeadline']
        );
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
        $this->remit($receipt, 'remaining-merchant-remittance', 999);
        $this->wakePayment('original-merchant-paid');
        $this->assertSame('paid', $this->state('payment'), $this->progress());
        $this->assertSame('fulfilled', $this->state('order'), $this->progress());
        $before = $this->effects();
        app(WorkflowReplayer::class)->replay($this->child('payment'));
        $this->assertSame($before, $this->effects());
        $this->assertSame(0, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
    }

    public function testSpoofedAndPartialCollectionDoNotConfirmPayerOrMerchantPayment(): void
    {
        $origin = $this->startCod(CodFundingPolicy::BEFORE);
        $this->wakePayment('forged-paid-hint');
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $origin->withRequestId('fulfill-partial-cod')
            ->signal('fulfill');
        $this->pump();
        $this->recordPhysical('handed-over');
        $partial = $this->collect('partial-collection', 500);
        $this->wakePayment('partial-payer-hint');
        $this->assertSame('ready', $this->state('payment'), $this->progress());
        $this->cod->collections->record($this->terms->codPayment, $partial);
        $this->assertSame(500, $this->cod->inspect($this->terms->codPayment)['facts']['collected_amount_minor']);
        $this->collect('remaining-collection', 999);
        $this->wakePayment('full-payer-hint');
        $this->assertSame('collected', $this->state('payment'), $this->progress());
        $this->assertSame('ready', $this->state('order'));
        $this->assertSame(1499, $this->cod->inspect($this->terms->codPayment)['facts']['outstanding_remittance_minor']);
        $this->assertSame(['reserve', 'ship-book', 'deduct-stock'], $this->effects());
    }

    public function testCancellationBeforePayerCollectionFencesBothMoneyDutiesAndReleasesOnlyOriginalStock(): void
    {
        $origin = $this->startCod(CodFundingPolicy::BEFORE);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'cancel-before-cod-collection'
        );
        $this->pump();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->childScope('payment'))['status'],
            $this->progress()
        );
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame('cancelled', $this->state('order'));
        $this->assertSame(['reserve', 'release'], $this->effects());
        $this->assertTrue($this->cod->inspect($this->terms->codPayment)['collectionAdmissionClosed']);
    }

    public function testRecordedCodInspectionRejectsSubstitutedMoneyCarrierContractAndClosureTime(): void
    {
        $this->startCod(CodFundingPolicy::BEFORE);
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $run = $this->child('payment');
        $store = app(CodInspectionStore::class);
        $read = fn (): array => $store->inspect(
            $run,
            'original-recorded-cod-observation',
            $plan->childScope('payment')
                ->toArray(),
            $this->terms->codDescriptor()
        );
        $original = $read();
        $payload = DB::table('provider_machine_assessments')->where('id', $original['snapshotId'])->sole()->payload;
        foreach ([
            'open-time',
            'future-close',
            'missing-close',
            'merchant-money',
            'carrier-owner',
            'carrier-receipt',
            'contract',
        ] as $case) {
            $value = $original;
            switch ($case) {
                case 'open-time':
                    $value['cod']['closedAt'] = '1000';
                    break;
                case 'future-close':
                    $value['cod']['collectionAdmissionClosed'] = true;
                    $value['cod']['closedAt'] = $value['cod']['observedAt'] + 1;
                    break;
                case 'missing-close':
                    $value['cod']['collectionAdmissionClosed'] = true;
                    break;
                case 'merchant-money':
                    $value['cod']['facts']['merchant_receipt_confirmed'] = true;
                    break;
                case 'carrier-owner':
                    $value['cod']['carrierContract']['originalFingerprint'] = str_repeat('0', 64);
                    break;
                case 'carrier-receipt':
                    $value['cod']['carrierContract']['collections'][] = [
                        'receipt' => 'foreign',
                        'sha256' => str_repeat('0', 64),
                        'phase' => CodDescriptor::BEFORE,
                    ];
                    break;
                case 'contract':
                    $value['cod']['admissionContract'] = CodJournal::OPEN;
                    break;
            }
            $changed = PortableDescriptor::encode($value);
            DB::table('provider_machine_assessments')->where('id', $original['snapshotId'])->update([
                'payload' => $changed,
                'payload_sha256' => hash('sha256', $changed),
            ]);
            try {
                $read();
                $this->fail('Recorded original COD substitution was accepted: ' . $case);
            } catch (LogicException|InvalidArgumentException) {
                $this->assertTrue(true, $case);
            } finally {
                DB::table('provider_machine_assessments')->where('id', $original['snapshotId'])->update([
                    'payload' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                ]);
            }
        }
        $this->assertSame($original, $read());
        $this->assertSame(['reserve'], $this->effects());
    }
}
