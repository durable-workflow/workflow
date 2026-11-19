<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\ReturnStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\PartialReturnFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-04 under worker loss. A real worker process dies with SIGKILL after a return effect commits and before
 * DW records it. A new process recovers the same original obligation through the production watchdog.
 */
final class V2PartialReturnProcessLossTest extends TestCase
{
    use PartialReturnFixture;

    public static function cuts(): array
    {
        return [
            'return request committed' => [ReturnStore::REQUEST_HANDLER],
            'restock committed' => [ReturnStore::RESTOCK_HANDLER],
            'return refund committed' => [ReturnStore::REFUND_HANDLER],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalReturnOperationWithoutRepeatingItsEffect(string $handler): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        if (DB::connection()->getConfig('database') === ':memory:') {
            $this->markTestSkipped('A replacement worker process needs the shared application database.');
        }
        [$origin, $shipment] = $this->delivered();
        $key = $this->terms->subjectKey();
        $return = $this->planReturn('rma-loss', [2]);
        $returns = app(ReturnStore::class);
        $runId = $returns->request($key, 'rma-loss', [2])['runId'];
        if ($handler !== ReturnStore::REQUEST_HANDLER) {
            $this->drain();
            $this->warehouse($return, 'received', [2]);
            $this->warehouse($return, 'inspected', [2], [2], [2]);
            WorkflowStub::loadRun($runId, \DurableWorkflow\Examples\ProviderStateMachines\Catalogue::NAMESPACE)
                ->withRequestId('rma-loss-inspection')
                ->signal('return-milestone');
        }
        $history = $this->history($origin->runId());
        $input = tempnam(sys_get_temp_dir(), 'return-loss-input-');
        $report = tempnam(sys_get_temp_dir(), 'return-loss-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'phase' => 'crash',
                'key' => $key,
                'returnKey' => 'rma-loss',
                'unitAmounts' => $this->unitAmounts(),
                'handler' => $handler,
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
                'database' => DB::connection()->getConfig(),
                'redis' => config('database.redis'),
                'cache' => config('cache'),
                'report' => $report,
            ];
            $command = [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/PartialReturnProcessLossProcessTest.php'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_RETURN_PROCESS_INPUT' => $input,
            ], timeout: 300);
            $process->start();
            $crashed = $process->getPid();
            try {
                $process->wait();
                $this->fail('The first worker must die at its selected provider cut. ' . $process->getOutput());
            } catch (ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($crashed, $cut['pid']);
            $this->assertNotSame(getmypid(), $crashed);
            $row = $this->obligation($cut);
            // The provider effect exists; DW has not recorded its outcome.
            $this->assertSame('in_flight', $row['forward_status']);
            $this->assertNull($row['forward_result']);
            if ($handler === ReturnStore::REQUEST_HANDLER) {
                $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
                $this->warehouse($return, 'received', [2]);
                $this->warehouse($return, 'inspected', [2], [2], [2]);
            }

            $data['phase'] = 'resume';
            $data['clock'] = $cut['clock'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_RETURN_PROCESS_INPUT' => $input,
            ], timeout: 300);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $resumed = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($crashed, $resumed['pid']);
            $this->assertSame('completed', $resumed['status'], $this->progress());
            Carbon::setTestNow($resumed['clock']);
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
            unlink($input);
            unlink($report);
        }

        $recovered = $this->obligation($cut);
        // The same original operation was recovered; no replacement obligation or identity appeared.
        $this->assertSame($row['id'], $recovered['id']);
        $this->assertSame($row['fingerprint'], $recovered['fingerprint']);
        $this->assertSame('observed', $recovered['forward_status']);
        $this->assertSame([...self::DELIVERED, 'request-unit-return', 'restock-units'], $this->effects());
        $this->assertSame([499], $this->payouts());
        $outcome = $this->outcome('rma-loss');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame([2], $outcome['restockedUnits']);
        $this->assertSame([2], $outcome['refundedUnits']);
        $this->assertSame(499, $outcome['refundPayoutMinor']);
        $this->assertSame('fulfilled', $this->subjectState('order'));
        $this->assertSame($history, $this->history($origin->runId()));
        $requests = $this->requests();
        foreach ([$runId, $origin->runId(), $shipment->id] as $id) {
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
