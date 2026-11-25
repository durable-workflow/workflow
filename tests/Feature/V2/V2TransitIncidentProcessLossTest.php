<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use Illuminate\Support\Carbon;
use Tests\Support\TransitIncidentFixture;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-07/SHP-08 under worker loss. Real worker processes die with SIGKILL after each incident effect
 * commits and before DW records it. A fresh process recovers the same original obligation through the
 * production watchdog; every effect happens exactly once.
 */
final class V2TransitIncidentProcessLossTest extends TestCase
{
    use TransitIncidentFixture;
    use WorkerLossControl;

    private array $process = [];

    public function testFreshWorkersRecoverEachDamagedParcelRefundClaimRecoveryAndRestockOnce(): void
    {
        $commands = $this->begin('damaged', [0]);
        $cuts = [];
        foreach ([
            IncidentStore::REFUND_HANDLER,
            IncidentStore::CLAIM_HANDLER,
            IncidentStore::RECOVER_HANDLER,
        ] as $handler) {
            $cuts[] = $this->cut($handler);
        }
        // The carrier returned the damaged goods; the warehouse receives and inspects them.
        $this->warehouse($commands['recover'], 'received', [0, 1, 2]);
        $this->warehouse($commands['recover'], 'inspected', [0, 1, 2], [0, 1], [0]);
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId('recovery-inspection')
            ->signal('incident-milestone');
        $cuts[] = $this->cut(IncidentStore::RESTOCK_HANDLER);
        $this->current();
        $this->assertSame('completed', $this->incidentRun()->status->value, $this->progress());

        $this->assertRecovered($cuts);
        $this->assertSame(
            [...self::DELIVERED, 'carrier-claim', 'recover-damaged-units', 'restock-units'],
            $this->effects()
        );
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame([0], $outcome['restockedUnits']);
        $this->assertSame('remedied', $this->subjectState('order'));
        $this->assertReplayQuiet();
    }

    public function testFreshWorkersRecoverEachLostParcelReplacementReservationAndBookingOnce(): void
    {
        $commands = $this->begin('lost');
        $cuts = [$this->cut(IncidentStore::RESERVE_HANDLER), $this->cut(IncidentStore::BOOK_HANDLER)];
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'delivered');
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId('replacement-delivery')
            ->signal('incident-milestone');
        // The Order remedy wait is woken by the incident's own notification.
        $this->current();
        $this->assertSame('completed', $this->incidentRun()->status->value, $this->progress());

        $this->assertRecovered($cuts);
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertSame('replaced', $this->incidentOutcome()['resolution']);
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertReplayQuiet();
    }

    public function testFreshWorkersRecoverEachDamagedParcelReplacementBookingRecoveryAndRestockOnce(): void
    {
        $commands = $this->begin('damaged', [0]);
        $cuts = [$this->cut(IncidentStore::BOOK_HANDLER)];
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'delivered');
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId('replacement-delivery')
            ->signal('incident-milestone');
        // The delivered replacement settles the remedy before the claim and the damaged-goods recovery.
        $cuts[] = $this->cut(IncidentStore::RECOVER_HANDLER);
        $this->warehouse($commands['recover'], 'received', [0, 1, 2]);
        $this->warehouse($commands['recover'], 'inspected', [0, 1, 2], [0, 1], [0]);
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId('recovery-inspection')
            ->signal('incident-milestone');
        $cuts[] = $this->cut(IncidentStore::RESTOCK_HANDLER);
        $this->current();
        $this->assertSame('completed', $this->incidentRun()->status->value, $this->progress());

        $this->assertRecovered($cuts);
        $this->assertSame(
            [...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim', 'recover-damaged-units', 'restock-units'],
            $this->effects()
        );
        $this->assertSame([], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('damaged', $outcome['incident']);
        $this->assertSame('replaced', $outcome['resolution']);
        $this->assertSame([0], $outcome['restockedUnits']);
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertReplayQuiet();
    }

    public function testFreshWorkersRecoverTheRefusedReplacementBookingReleaseAndFallbackRefundOnce(): void
    {
        $this->begin('lost', book: Status::Refused);
        $cuts = [$this->cut(IncidentStore::RELEASE_HANDLER), $this->cut(IncidentStore::REFUND_HANDLER)];
        $this->current();
        $this->assertSame('completed', $this->incidentRun()->status->value, $this->progress());

        $this->assertRecovered($cuts);
        $this->assertSame([...self::DELIVERED, 'reserve', 'release', 'carrier-claim'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded-replacement-failed', $outcome['resolution']);
        $this->assertSame('booking-refused', $outcome['replacementFailure']);
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertReplayQuiet();
    }

    private function begin(string $kind, array $saleable = [], Status $book = Status::Confirmed): array
    {
        $this->requireWorkerLossSupport();
        [, $shipment] = $this->handedOver();
        $commands = $this->planIncident($kind, saleable: $saleable, book: $book);
        $this->record($kind);
        $this->hint($shipment, 'original-' . $kind);
        $this->drain(fn (): bool => $this->incidentRow()?->native_run !== null);
        $this->process = [
            'key' => $this->terms->subjectKey(),
            'unitAmounts' => $this->unitAmounts(),
            'policy' => $this->transitPolicy(),
            'wakes' => $this->transitWakes(),
            'provider' => $this->providerPath,
        ];
        return $commands;
    }

    /**
     * One worker dies after the handler's provider effect commits; a fresh one recovers that operation.
     *
     * @return array{array, array} The crash report and the in-flight obligation row it left.
     */
    private function cut(string $handler): array
    {
        $row = null;
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/TransitIncidentProcessLossProcessTest.php',
            'DWF_INCIDENT_PROCESS_INPUT',
            [
                ...$this->process,
                'handler' => $handler,
                'clock' => now()
                    ->toISOString(),
            ],
            function (array $cut, array $data) use (&$row): array {
                $row = $this->obligation($cut);
                // The provider effect exists; DW has not recorded its outcome.
                $this->assertSame('in_flight', $row['forward_status']);
                $this->assertNull($row['forward_result']);
                return [
                    ...$data,
                    'scope' => $cut['scope'],
                    'operationId' => $cut['operationId'],
                    'clock' => $cut['clock'],
                ];
            }
        );
        Carbon::setTestNow($resumed['clock']);
        return [$cut, $row];
    }

    private function assertRecovered(array $cuts): void
    {
        foreach ($cuts as [$cut, $row]) {
            $recovered = $this->obligation($cut);
            // The same original operation was recovered; no replacement obligation or identity appeared.
            $this->assertSame($row['id'], $recovered['id']);
            $this->assertSame($row['fingerprint'], $recovered['fingerprint']);
            $this->assertSame('observed', $recovered['forward_status']);
        }
    }

    private function assertReplayQuiet(): void
    {
        $requests = $this->requests();
        foreach ([$this->incidentRun()->id, $this->shipment()->id] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($requests, $this->requests());
    }

    private function obligation(array $cut): array
    {
        $ledger = (new ScopeRepository())->inspect(ScopeIdentity::fromArray($cut['scope']));
        $rows = array_values(array_filter(
            $ledger['obligations'],
            static fn (array $row): bool => $row['id'] === $cut['operationId']
        ));
        $this->assertCount(1, $rows);
        return $rows[0];
    }
}
