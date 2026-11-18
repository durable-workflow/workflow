<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\TransitIncidentFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-07/SHP-08 under worker loss. Real worker processes die with SIGKILL after each incident effect
 * commits and before DW records it. Each next process recovers the same original obligation through the
 * production watchdog; every effect happens exactly once.
 */
final class V2TransitIncidentProcessLossTest extends TestCase
{
    use TransitIncidentFixture;

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
            $cuts[] = $this->crash($handler);
        }
        // The carrier returned the damaged goods; the warehouse receives and inspects them.
        $this->warehouse($commands['recover'], 'received', [0, 1, 2]);
        $this->warehouse($commands['recover'], 'inspected', [0, 1, 2], [0, 1], [0]);
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId('recovery-inspection')
            ->signal('incident-milestone');
        $cuts[] = $this->crash(IncidentStore::RESTOCK_HANDLER);
        $this->assertSame('completed', $this->resume(), $this->progress());

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
        $cuts = [$this->crash(IncidentStore::RESERVE_HANDLER), $this->crash(IncidentStore::BOOK_HANDLER)];
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'delivered');
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId('replacement-delivery')
            ->signal('incident-milestone');
        $this->assertSame('completed', $this->resume(), $this->progress());
        // The Order remedy wait is woken by the incident's own notification.
        $this->current();

        $this->assertRecovered($cuts);
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertSame('replaced', $this->incidentOutcome()['resolution']);
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertReplayQuiet();
    }

    private function begin(string $kind, array $saleable = []): array
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        if (DB::connection()->getConfig('database') === ':memory:') {
            $this->markTestSkipped('A replacement worker process needs the shared application database.');
        }
        [, $shipment] = $this->handedOver();
        $commands = $this->planIncident($kind, saleable: $saleable);
        $this->record($kind);
        $this->hint($shipment, 'original-' . $kind);
        $this->drain(fn (): bool => $this->incidentRow()?->native_run !== null);
        $this->process = [
            'key' => $this->terms->subjectKey(),
            'unitAmounts' => $this->unitAmounts(),
            'policy' => $this->transitPolicy(),
            'provider' => $this->providerPath,
            'database' => DB::connection()->getConfig(),
            'redis' => config('database.redis'),
            'cache' => config('cache'),
        ];
        return $commands;
    }

    private function crash(string $handler): array
    {
        [$process, $input, $report] = $this->worker('crash', $handler);
        try {
            $process->start();
            $crashed = $process->getPid();
            try {
                $process->wait();
                $this->fail('The worker must die at its selected provider cut. ' . $process->getOutput());
            } catch (ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $this->stop($process, $input, $report);
        }
        $this->assertSame($crashed, $cut['pid']);
        $this->assertNotSame(getmypid(), $crashed);
        $row = $this->obligation($cut);
        // The provider effect exists; DW has not recorded its outcome.
        $this->assertSame('in_flight', $row['forward_status']);
        $this->assertNull($row['forward_result']);
        Carbon::setTestNow($cut['clock']);
        return [$cut, $row];
    }

    private function resume(): string
    {
        [$process, $input, $report] = $this->worker('resume', null);
        try {
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $resumed = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $this->stop($process, $input, $report);
        }
        $this->assertNotSame(getmypid(), $resumed['pid']);
        Carbon::setTestNow($resumed['clock']);
        return $resumed['status'];
    }

    /**
     * @return array{Process, string, string}
     */
    private function worker(string $phase, ?string $handler): array
    {
        $input = tempnam(sys_get_temp_dir(), 'incident-loss-input-');
        $report = tempnam(sys_get_temp_dir(), 'incident-loss-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        file_put_contents($input, json_encode([
            ...$this->process,
            'phase' => $phase,
            'handler' => $handler,
            'clock' => now()
                ->toISOString(),
            'report' => $report,
        ], JSON_THROW_ON_ERROR));
        return [new Process(
            [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/TransitIncidentProcessLossProcessTest.php'],
            dirname(__DIR__, 3),
            [
                'DWF_INCIDENT_PROCESS_INPUT' => $input,
            ],
            timeout: 300
        ), $input, $report];
    }

    private function stop(Process $process, string $input, string $report): void
    {
        if ($process->isRunning()) {
            $process->stop(0, SIGKILL);
        }
        unlink($input);
        unlink($report);
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
