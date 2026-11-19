<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\ExchangeStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\ExchangeFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-05 under worker loss. A real worker process dies with SIGKILL after an exchange effect commits and
 * before DW records it. A new process recovers the same original obligation through the production watchdog.
 */
final class V2ExchangeProcessLossTest extends TestCase
{
    use ExchangeFixture;

    public static function cuts(): array
    {
        return [
            'exchange return request committed' => [ExchangeStore::REQUEST_HANDLER, Status::Confirmed],
            'replacement reservation committed' => [ExchangeStore::RESERVE_HANDLER, Status::Confirmed],
            'replacement booking committed' => [ExchangeStore::BOOK_HANDLER, Status::Confirmed],
            'unavailable replacement refund committed' => [ExchangeStore::REFUND_HANDLER, Status::Refused],
        ];
    }

    #[DataProvider('cuts')]
    public function testFreshWorkerRecoversTheOriginalExchangeOperationWithoutRepeatingItsEffect(
        string $handler,
        Status $reservation
    ): void {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        if (DB::connection()->getConfig('database') === ':memory:') {
            $this->markTestSkipped('A replacement worker process needs the shared application database.');
        }
        [$origin, $shipment] = $this->delivered();
        $key = $this->terms->subjectKey();
        $commands = $this->planExchange('swap-loss', 2, reserve: $reservation);
        $runId = $this->exchange('swap-loss', 2)['runId'];
        if ($handler !== ExchangeStore::REQUEST_HANDLER) {
            $this->drain();
            $this->warehouse($commands['request'], 'received', [2]);
            $this->warehouse($commands['request'], 'inspected', [2], [2], [2]);
            WorkflowStub::loadRun($runId, Catalogue::NAMESPACE)
                ->withRequestId('swap-loss-inspection')
                ->signal('exchange-milestone');
        }
        $history = $this->history($origin->runId());
        $input = tempnam(sys_get_temp_dir(), 'exchange-loss-input-');
        $report = tempnam(sys_get_temp_dir(), 'exchange-loss-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'phase' => 'crash',
                'key' => $key,
                'exchangeKey' => 'swap-loss',
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
            $command = [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/ExchangeProcessLossProcessTest.php'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_EXCHANGE_PROCESS_INPUT' => $input,
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
            if ($handler === ExchangeStore::REQUEST_HANDLER) {
                $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
                $this->warehouse($commands['request'], 'received', [2]);
                $this->warehouse($commands['request'], 'inspected', [2], [2], [2]);
            }

            $data['phase'] = 'resume';
            $data['clock'] = $cut['clock'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_EXCHANGE_PROCESS_INPUT' => $input,
            ], timeout: 300);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $resumed = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($crashed, $resumed['pid']);
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
        $returned = [...self::DELIVERED, 'request-unit-return', 'restock-units'];
        if ($reservation === Status::Refused) {
            $this->assertSame('completed', $resumed['status'], $this->progress());
            $this->assertSame($returned, $this->effects());
            $this->assertSame([499], $this->payouts());
            $this->assertSame('refunded-replacement-unavailable', $this->exchangeOutcome('swap-loss')['resolution']);
        } else {
            // The recovered worker reached the replacement delivery wait; the carrier now delivers once.
            $this->assertSame('waiting', $resumed['status'], $this->progress());
            $this->assertSame([...$returned, 'reserve', 'ship-book'], $this->effects());
            $this->carrier($commands, 'handed-over');
            $this->carrier($commands, 'delivered');
            $this->hintExchange(WorkflowRun::query()->findOrFail($runId), 'swap-loss-delivery');
            $this->assertSame([...$returned, 'reserve', 'ship-book'], $this->effects());
            $this->assertSame([], $this->payouts());
            $this->assertSame('replaced', $this->exchangeOutcome('swap-loss')['resolution'], $this->progress());
        }
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
