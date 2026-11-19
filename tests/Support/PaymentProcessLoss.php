<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\WorkflowReplayer;

/**
 * Parent side of the payment-family process-loss controls, used with a ProviderMachineFixture variant. One
 * worker process dies after the provider commits a payment effect and before DW records it; a fresh process
 * recovers the same original obligation through the production watchdog.
 */
trait PaymentProcessLoss
{
    use WorkerLossControl;

    /**
     * @param Closure(): array<string, int> $committed Counts each provider effect this cut commits.
     * @return array The recovered original obligation.
     */
    private function loseThenRecoverPayment(string $profile, string $handler, string $mode, Closure $committed): array
    {
        $key = $this->terms->subjectKey();
        $row = null;
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/PaymentProcessLossProcessTest.php',
            'DWF_PAYMENT_PROCESS_INPUT',
            [
                'profile' => $profile,
                'key' => $key,
                'recipe' => $this->store->authority->recipe($key),
                'handler' => $handler,
                'mode' => $mode,
                'provider' => $this->providerPath,
                'clock' => now()
                    ->toISOString(),
            ],
            function (array $cut, array $data) use ($mode, $committed, &$row): array {
                $row = $this->lostObligation($cut);
                // The provider effect exists; DW has not recorded its outcome.
                $this->assertCommittedOnce($committed);
                if ($mode === 'forward') {
                    $this->assertSame('in_flight', $row['forward_status']);
                    $this->assertNull($row['forward_result']);
                } else {
                    $this->assertNotSame('verified', $row['compensation_status']);
                }
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                ];
            },
        );
        Carbon::setTestNow($resumed['clock']);
        $recovered = $this->lostObligation($cut);
        // The same original obligation settled; no replacement obligation or identity appeared.
        $this->assertSame([$row['id'], $row['fingerprint']], [$recovered['id'], $recovered['fingerprint']]);
        $this->assertSame(
            $mode === 'forward' ? 'observed' : 'verified',
            $recovered[$mode === 'forward' ? 'forward_status' : 'compensation_status'],
            $this->progress()
        );
        $this->assertCommittedOnce($committed);
        return $recovered;
    }

    private function assertCommittedOnce(Closure $committed): void
    {
        $counts = $committed();
        $this->assertNotSame([], $counts);
        $this->assertSame(array_fill_keys(array_keys($counts), 1), $counts, $this->progress());
    }

    /**
     * @return Closure(): array<string, int>
     */
    private function providerActions(string ...$actions): Closure
    {
        return fn (): array => array_map(
            fn (string $action): int => count(array_keys($this->effects(), $action, true)),
            array_combine($actions, $actions)
        );
    }

    /**
     * Request attempts may repeat across processes; the committed effect never does.
     */
    private function assertRepeatedRequestOneEffect(Request $request): void
    {
        $count = function (string $table) use ($request): int {
            $statement = $this->providerDb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE operation_id = ?');
            $statement->execute([$request->identity->key()]);
            return (int) $statement->fetchColumn();
        };
        $this->assertSame(1, $count('provider_effects'));
        $this->assertGreaterThan(1, $count('provider_requests'));
    }

    /**
     * Recovery waits on the original fixed deadline; it never starts a new interval from the replacement time.
     */
    private function assertDeadlineKept(string $role, int $deadline): void
    {
        $timers = WorkflowTimer::query()->where('workflow_run_id', $this->roleRun($role)->id)->pluck('fire_at');
        $this->assertNotEmpty($timers);
        $this->assertLessThanOrEqual($deadline, $timers->max()->getTimestamp());
    }

    /**
     * The payment child's compensation outlived the Order's in-origin attempts while its worker was lost, so
     * the Order closed unsettled. The replacement worker's watchdog routed the accepted request to recovery
     * (#1205), and the worker application's finalizer committed the business cancellation.
     */
    private function assertCancelledByAutomaticRecovery(): void
    {
        $this->assertOrderClosedUnsettled('verified');
        $this->assertSame('cancelled', $this->subjectStateOf('order'), $this->progress());
    }

    private function assertOrderClosedUnsettled(string $scopeStatus): void
    {
        $this->assertSame('failed', $this->roleRun('order')->status->value, $this->progress());
        $this->assertSame($scopeStatus, (new ScopeRepository())->metadata(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        )['status'], $this->progress());
    }

    private function subjectStateOf(string $role): string
    {
        $subject = $this->store->authority->subject($this->terms->subjectKey(), $role);
        return $subject->state;
    }

    /**
     * Replay reproduces each recorded outcome without provider I/O; an Order that closed unsettled replays
     * its same recorded compensation failure.
     */
    private function assertReplayRequestsNothing(string ...$roles): void
    {
        $requests = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        foreach ($roles as $role) {
            $run = $this->roleRun($role);
            try {
                app(WorkflowReplayer::class)->replay($run);
                $this->assertNotSame('failed', $run->status->value);
            } catch (RuntimeException $error) {
                $this->assertSame(['failed', 'scope_compensation_unknown'], [
                    $run->status->value,
                    $error->getMessage(),
                ]);
            }
        }
        $this->assertSame(
            $requests,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
    }

    private function roleRun(string $role): WorkflowRun
    {
        $actor = DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), $role)
        )->sole();
        return WorkflowRun::query()->findOrFail($actor->native_run);
    }

    private function lostObligation(array $cut): array
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
