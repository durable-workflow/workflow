<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\AdvanceBalanceMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** An actual process loss separates the confirmed bank refund from the remaining retained work. */
final class V2AdvanceBalanceColdRecoveryTest extends TestCase
{
    use AdvanceBalanceMachineFixture;

    public static function refundCuts(): array
    {
        return [
            'durable-confirmation' => [false],
            'provider-commit-before-confirmation' => [true],
        ];
    }

    #[DataProvider('refundCuts')]
    public function testFreshProcessRecoversTheOriginalBankRefundAndFinishesCancellation(
        bool $loseAcknowledgement
    ): void {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        $origin = $this->startAdvance(AdvanceBalancePolicy::BEFORE);
        $receipt = $this->receive('original-bank-before-worker-loss', 500);
        $this->wake('incoming-observed', 'original-bank-confirmed');
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $refund = IncomingPaymentOperation::refundRequest(
            $plan->childScope('payment'),
            'payment.incoming',
            $this->terms->advanceBalance->advance,
            $receipt
        );
        $this->provider->plan($refund, Status::Confirmed, $loseAcknowledgement);
        $history = $this->history($origin->runId());
        $request = 'cancel-original-before-worker-loss';
        app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->runId(), $request);
        $input = tempnam(sys_get_temp_dir(), 'advance-recovery-input-');
        $report = tempnam(sys_get_temp_dir(), 'advance-recovery-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'profile' => 'advance-balance',
                'key' => $this->terms->subjectKey(),
                'recipe' => AdvanceBalancePolicy::BEFORE,
                'rootRun' => $origin->runId(),
                'rootScope' => $plan->rootScope()
                    ->toArray(),
                'paymentScope' => $plan->childScope('payment')
                    ->toArray(),
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
                'database' => DB::connection()->getConfig(),
                'redis' => config('database.redis'),
                'cache' => config('cache'),
                'request' => $request,
                'phase' => 'crash',
                'report' => $report,
                'refundId' => $refund->identity->key(),
                'loseAcknowledgement' => $loseAcknowledgement,
            ];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $command = [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/FundingRecoveryProcessTest.php'];
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_PROVIDER_RECOVERY_INPUT' => $input,
            ], timeout: 180);
            $process->start();
            $stoppedPid = $process->getPid();
            try {
                $process->wait();
                $this->fail('The first worker must stop at the durable bank-refund cut. ' . $process->getOutput());
            } catch (ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($stoppedPid, $cut['pid']);
            $this->assertNotSame(getmypid(), $stoppedPid);
            $scopes = new ScopeRepository();
            $payment = $scopes->inspect($plan->childScope('payment'));
            $this->assertSame('requested', $payment['status']);
            $this->assertSame(! $loseAcknowledgement, $cut['bankVerified']);
            if ($loseAcknowledgement) {
                $this->assertNotSame('verified', $payment['obligations'][2]['compensation_status']);
            } else {
                $this->assertSame('verified', $payment['obligations'][2]['compensation_status']);
            }
            $this->assertSame('pending', $payment['obligations'][1]['compensation_status']);
            $this->assertSame('requested', $scopes->metadata($plan->rootScope())['status']);
            $this->assertSame(['reserve', 'refund-incoming'], $this->effects());
            $requestsBefore = $this->refundRequests($refund->identity->key());
            $this->assertSame(1, $requestsBefore);

            $data['phase'] = 'resume';
            $data['clock'] = $cut['clock'];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new Process($command, dirname(__DIR__, 3), [
                'DWF_PROVIDER_RECOVERY_INPUT' => $input,
            ], timeout: 180);
            $process->mustRun();
            $resumed = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $resumed['pid']);
            $this->assertNotSame($stoppedPid, $resumed['pid']);
            $this->assertSame('verified', $resumed['status']);
            $this->assertSame('verified', $scopes->metadata($plan->rootScope())['status']);
            $after = $scopes->inspect($plan->childScope('payment'));
            $this->assertSame($payment['original_run_id'], $after['original_run_id']);
            $this->assertSame(array_column($payment['obligations'], 'id'), array_column($after['obligations'], 'id'));
            $this->assertSame(['verified', 'verified', 'verified', 'verified'], array_column(
                $after['obligations'],
                'compensation_status'
            ));
            $this->assertSame('cancelled', $this->state('order'));
            $this->assertSame(['reserve', 'refund-incoming', 'release'], $this->effects());
            $requestsAfter = $this->refundRequests($refund->identity->key());
            if ($loseAcknowledgement) {
                $this->assertGreaterThanOrEqual($requestsBefore, $requestsAfter);
            } else {
                $this->assertSame($requestsBefore, $requestsAfter);
            }
            $operation = $this->providerDb->prepare(
                'SELECT request, fingerprint FROM provider_operations WHERE id = ?'
            );
            $this->assertNotFalse($operation);
            $operation->execute([$refund->identity->key()]);
            $recorded = $operation->fetch();
            $this->assertIsArray($recorded);
            $this->assertSame($refund->fingerprint(), $recorded['fingerprint']);
            $recordedRequest = Request::fromArray(json_decode($recorded['request'], true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame($refund->toArray(), $recordedRequest->toArray());
            $this->assertSame($history, array_slice($this->history($origin->runId()), 0, count($history)));
        } finally {
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            unlink($input);
            unlink($report);
        }
    }

    private function refundRequests(string $id): int
    {
        $statement = $this->providerDb->prepare(
            'SELECT COUNT(*) FROM provider_requests WHERE operation_id = ? AND kind = ?'
        );
        $this->assertNotFalse($statement);
        $statement->execute([$id, 'command']);
        return (int) $statement->fetchColumn();
    }
}
