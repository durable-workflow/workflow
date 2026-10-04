<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\WorkflowStub;

final class V2CancellationScopeRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-03T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testDirectRequestColdReadsAndDuplicatesPreserveOriginalMetadataWithoutCancellingRun(): void
    {
        [$workflow, $run, $parent, $child] = $this->scopeTree('direct');
        $claimBefore = $run->tasks()
            ->sole()
            ->getAttributes();
        $event = CancellationScopeRequests::request(
            $run,
            $child,
            '1.20',
            30,
            'release resource',
            CommandContext::phpApi()->withPrincipal('operator', 'operator-fixture', 'Operator'),
        );
        $context = CancellationScopeRequests::context($run->fresh(), $child);
        $this->assertSame($event->payload['request_id'], $context->requestId);
        $this->assertSame($context->requestId, $context->rootContext->rootRequestId);
        $this->assertSame($child, $context->rootScopeId);
        $this->assertSame('release resource', $context->rootContext->reason);
        $this->assertSameJsonObject([
            'type' => 'operator',
            'id' => 'operator-fixture',
            'label' => 'Operator',
        ], $context->rootContext->requester);
        $this->assertSame('php', $context->rootContext->source);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $context->requestedAt()->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $context->deadline()->toISOString());
        Carbon::setTestNow('2026-10-03T00:00:10Z');
        $duplicate = CancellationScopeRequests::request($run->fresh(), $child, '1.20', 300, 'different reason');
        $this->assertSame($event->id, $duplicate->id);
        $this->assertSame($context->toArray(), CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $parent));
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
        $this->assertSame($claimBefore, $run->tasks()->sole()->getAttributes());
        $this->assertRunCancellationUntouched($run);
    }

    public function testInheritanceHasDistinctAddressIdentityAndOneOriginalRootBudget(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('inherit');
        $first = CancellationScopeRequests::request($run, $parent, '1.20', 30, 'original reason');
        Carbon::setTestNow('2026-10-03T00:00:09Z');
        $inherited = CancellationScopeRequests::request(
            $run,
            $child,
            '1.20',
            300,
            'ignored descendant reason',
            parentScopeId: $parent
        );
        $context = CancellationScopeRequests::context($run->fresh(), $child);
        $this->assertNotSame($first->payload['request_id'], $context->requestId);
        $this->assertSame($first->payload['request_id'], $context->parentRequestId);
        $this->assertSame($first->payload['request_id'], $context->rootContext->rootRequestId);
        $this->assertSame([$parent, $child], array_column($context->lineage, 'scope_id'));
        $this->assertSame('original reason', $context->rootContext->reason);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $context->requestedAt()->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $context->deadline()->toISOString());
        $this->assertSame($context->rootDeadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertSame(
            $inherited->id,
            CancellationScopeRequests::request($run, $child, '1.20', 3600, parentScopeId: $parent)->id
        );
        $this->assertRunCancellationUntouched($run);
    }

    public function testCompetingAncestorRootIsVisibleAndShortensAuthorityWithoutRewritingAcceptedBudget(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('conflict', true);
        CancellationScopeRequests::request($run, $child, '1.20', 30, 'independent scope');
        $accepted = CancellationScopeRequests::context($run, $child)->toArray();
        $ancestor = CancellationScopeRequests::request($run, $parent, '1.20', 10, 'parent budget');
        $conflict = CancellationScopeRequests::request($run, $child, '1.20', 300, parentScopeId: $parent);
        $this->assertSame(HistoryEventType::CancellationScopeRequestConflicted, $conflict->event_type);
        $this->assertSame('cancellation_root_conflict', $conflict->payload['reason']);
        $this->assertSameJsonObject($accepted, $conflict->payload['accepted_cancellation']);
        $incoming = $conflict->payload['incoming_cancellation'];
        $this->assertSame($ancestor->payload['request_id'], $incoming['root_context']['root_request_id']);
        $this->assertSame('2026-10-03T00:00:10.000000Z', $incoming['root_context']['cleanup_deadline_at']);
        $this->assertSame($accepted, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertSame([
            'active' => true,
            'deadline_at' => '2026-10-03T00:00:10.000000Z',
        ], CancellationScopeRequests::authority($run, $child));
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))->firstWhere('id', $conflict->id);
        $this->assertSame('cancellation_scope', $timeline['kind']);
        $this->assertSameJsonObject($accepted, $timeline['cancellation_scope']['accepted_cancellation']);
        $this->assertSameJsonObject($incoming, $timeline['cancellation_scope']['incoming_cancellation']);
        $this->assertSame(
            $conflict->id,
            CancellationScopeRequests::request($run->fresh(), $child, '1.20', 3600, parentScopeId: $parent)->id
        );
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequestConflicted)->count()
        );
        Carbon::setTestNow('2026-10-03T00:00:10Z');
        $this->assertFalse(CancellationScopeRequests::authority($run, $child)['active']);
        $this->assertSame($accepted, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertRunCancellationUntouched($run);
    }

    public function testRunRequestIsCanonicalParentAndOriginalRunFieldsKeepTheirMeaning(): void
    {
        [$workflow, $run, $parent] = $this->scopeTree('run-parent');
        $runRequest = $workflow->requestCancellation('whole run', 30)
            ->cancellationContext();
        Carbon::setTestNow('2026-10-03T00:00:08Z');
        $event = CancellationScopeRequests::request($run, $parent, '1.20', 300, parentScopeId: 'root');
        $context = CancellationScopeRequests::context($run->fresh(), $parent);
        $this->assertSame($runRequest->requestId, $context->rootContext->rootRequestId);
        $this->assertSame($runRequest->requestId, $context->parentRequestId);
        $this->assertSame(['root', $parent], array_column($context->lineage, 'scope_id'));
        $this->assertSame($runRequest->deadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertSame($runRequest->requestId, $run->fresh()->cancellation_request_command_id);
        $this->assertNotSame($runRequest->requestId, $event->payload['request_id']);
        $this->assertSame(
            $runRequest->deadline()
                ->toISOString(),
            CancellationScopeRequests::authority($run, $parent)['deadline_at']
        );
    }

    #[DataProvider('authorityCeilings')]
    public function testShieldedIndependentScopeCannotOutliveRunAuthority(string $ceiling): void
    {
        [$workflow, $run, , $child] = $this->scopeTree('ceiling', true);
        CancellationScopeRequests::request($run, $child, '1.20', 30);
        $original = CancellationScopeRequests::context($run, $child)->toArray();
        if ($ceiling === 'cancellation') {
            $workflow->requestCancellation('run ceiling', 12);
        } else {
            $run->forceFill([
                $ceiling => now()
                    ->addSeconds(12),
            ])->save();
        }
        $this->assertSame(
            '2026-10-03T00:00:12.000000Z',
            CancellationScopeRequests::authority($run, $child)['deadline_at']
        );
        Carbon::setTestNow('2026-10-03T00:00:12Z');
        $this->assertFalse(CancellationScopeRequests::authority($run, $child)['active']);
        $this->assertSame($original, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
    }

    public static function authorityCeilings(): iterable
    {
        yield 'run cancellation' => ['cancellation'];
        yield 'execution deadline' => ['execution_deadline_at'];
        yield 'run deadline' => ['run_deadline_at'];
    }

    public function testTerminalRunReturnsAcceptedRetryButRejectsNewScopeRequest(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('terminal');
        $event = CancellationScopeRequests::request($run, $child, '1.20');
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $this->assertSame($event->id, CancellationScopeRequests::request($run, $child, '1.20', 300)->id);
        $this->assertFalse(CancellationScopeRequests::authority($run, $child)['active']);
        $this->expectExceptionMessage('cancellation_scope_authority_expired');
        CancellationScopeRequests::request($run, $parent, '1.20');
    }

    public function testExpiredParentCannotGrantNewDescendantBudget(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('expired');
        CancellationScopeRequests::request($run, $parent, '1.20', 10);
        Carbon::setTestNow('2026-10-03T00:00:10Z');
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $child, '1.20', 300, parentScopeId: $parent);
            $this->fail('An expired parent must not grant new authority.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_authority_expired', $error->getMessage());
        }
        $this->assertNull(CancellationScopeRequests::context($run, $child));
        $this->assertSame($before, $run->historyEvents()->count());
    }

    #[DataProvider('invalidScopes')]
    public function testUnknownForeignAndImplicitRootAddressesRefuseBeforeWriting(string $kind): void
    {
        [, $run] = $this->scopeTree('invalid');
        $scope = $kind === 'root' ? 'root' : 'missing';
        if ($kind === 'foreign') {
            [, , $scope] = $this->scopeTree('foreign');
        }
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $scope, '1.20');
            $this->fail('An address outside the exact run must be refused.');
        } catch (LogicException $error) {
            $this->assertSame(
                $kind === 'root' ? 'root_scope_requires_run_cancellation' : 'cancellation_scope_not_recorded',
                $error->getMessage()
            );
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertRunCancellationUntouched($run);
    }

    public static function invalidScopes(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'foreign' => ['foreign'];
        yield 'implicit run root' => ['root'];
    }

    #[DataProvider('invalidParents')]
    public function testInheritanceRejectsUnrecordedRequestAndWrongParent(string $kind): void
    {
        [, $run, $parent, $child] = $this->scopeTree('invalid-parent');
        $requestedParent = $kind === 'unrequested' ? $parent : 'root';
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $child, '1.20', parentScopeId: $requestedParent);
            $this->fail('Inheritance must use its accepted canonical immediate parent.');
        } catch (LogicException $error) {
            $this->assertSame(
                $kind === 'unrequested' ? 'cancellation_scope_parent_request_unavailable' : 'cancellation_scope_parent_mismatch',
                $error->getMessage()
            );
        }
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public static function invalidParents(): iterable
    {
        yield 'parent has no request' => ['unrequested'];
        yield 'skip canonical edge' => ['wrong'];
    }

    public function testCorruptCanonicalRequestAddressIsRefusedOnFreshRead(): void
    {
        [, $run, , $child] = $this->scopeTree('corrupt');
        $event = CancellationScopeRequests::request($run, $child, '1.20');
        $payload = $event->payload;
        $payload['cancellation']['lineage'][0]['scope_id'] = 'fabricated';
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_request_history_invalid');
        CancellationScopeRequests::context($run->fresh(), $child);
    }

    #[DataProvider('invalidProtocols')]
    public function testRequestsRequireKnownCandidateProtocol(string $version): void
    {
        [, $run, $parent] = $this->scopeTree('protocol');
        $this->expectExceptionMessage('cancellation_scope_requires_protocol_1_20');
        CancellationScopeRequests::request($run, $parent, $version);
    }

    public static function invalidProtocols(): iterable
    {
        yield 'published default' => ['1.19'];
        yield 'unknown major' => ['2.0'];
    }

    #[DataProvider('invalidTimeouts')]
    public function testRequestBudgetMustBeFiniteAndBounded(int $timeout): void
    {
        [, $run, $parent] = $this->scopeTree('invalid-budget');
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $parent, '1.20', $timeout);
            $this->fail('Unbounded or empty cleanup budgets must be refused.');
        } catch (LogicException $error) {
            $this->assertSame('invalid_scope_cancellation_request', $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public static function invalidTimeouts(): iterable
    {
        yield 'zero seconds' => [0];
        yield 'above maximum' => [3601];
    }

    public function testWholeRunProjectionCannotForgeTheInheritedRootBudget(): void
    {
        [$workflow, $run, $parent] = $this->scopeTree('corrupt-run');
        $workflow->requestCancellation('real request', 30);
        $run->refresh()
            ->forceFill([
                'cancellation_deadline_at' => now()
                    ->addSeconds(300),
            ])->save();
        $this->expectExceptionMessage('cancellation_scope_run_context_mismatch');
        CancellationScopeRequests::request($run, $parent, '1.20', parentScopeId: 'root');
    }

    #[DataProvider('requestRaces')]
    public function testConcurrentRequestsKeepOneAcceptedAddressAndExplicitCompetingRoot(
        bool $winnerInherited,
        bool $loserInherited,
    ): void {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'pgsql'], true)
            || ($driver === 'mysql' && str_contains(
                strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version),
                'mariadb'
            ))
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('MySQL or PostgreSQL row-lock observation and process control are required.');
        }
        [, $run, $parent, $child] = $this->scopeTree('race');
        CancellationScopeRequests::request($run, $parent, '1.20', 30, 'ancestor');
        $parentContext = CancellationScopeRequests::context($run, $parent)->toArray();
        Carbon::setTestNow(now()->addSeconds(5));
        DB::purge();
        $actors = [];
        try {
            $actors['winner'] = $this->forkScopeRequest($run->id, $child, $winnerInherited ? $parent : null, true);
            $this->readActor($actors['winner']);
            $actors['loser'] = $this->forkScopeRequest($run->id, $child, $loserInherited ? $parent : null, false);
            $ready = $this->readActor($actors['loser']);
            fwrite($actors['winner']['socket'], "go\n");
            $uncommitted = $this->readActor($actors['winner']);
            $this->assertSame(HistoryEventType::CancellationScopeRequested->value, $uncommitted['type']);
            fwrite($actors['loser']['socket'], "go\n");
            $query = $driver === 'mysql'
                ? 'SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?'
                : 'SELECT COUNT(*) AS waiting FROM pg_locks WHERE pid = ? AND NOT granted';
            $until = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne($query, [$ready['connection_id']])->waiting;
                if ($waiting > 0) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $until);
            $this->assertGreaterThan(0, $waiting, 'The second actor must actually wait on the database lock.');
            $this->assertNull(CancellationScopeRequests::context($run->fresh(), $child));
            fwrite($actors['winner']['socket'], "commit\n");
            $winner = $this->finishActor($actors['winner']);
            unset($actors['winner']);
            $loser = $this->finishActor($actors['loser']);
            unset($actors['loser']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }
        $accepted = CancellationScopeRequests::context($run->fresh(), $child)->toArray();
        $this->assertSameJsonObject($winner['payload']['cancellation'], $accepted);
        $this->assertSame(
            $winnerInherited ? '2026-10-03T00:00:30.000000Z' : '2026-10-03T00:00:25.000000Z',
            $accepted['lineage'][count($accepted['lineage']) - 1]['cleanup_deadline_at']
        );
        if (! $winnerInherited && $loserInherited) {
            $this->assertSame(HistoryEventType::CancellationScopeRequestConflicted->value, $loser['type']);
            $this->assertSameJsonObject($accepted, $loser['payload']['accepted_cancellation']);
            $this->assertSame(
                $parentContext['root_context']['root_request_id'],
                $loser['payload']['incoming_cancellation']['root_context']['root_request_id']
            );
            $this->assertSame('cancellation_root_conflict', $loser['payload']['reason']);
        } else {
            $this->assertSame($winner['id'], $loser['id']);
            $this->assertSameJsonObject($winner['payload'], $loser['payload']);
        }
        $this->assertSame(
            2,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
        $this->assertSameJsonObject(
            $parentContext,
            CancellationScopeRequests::context($run->fresh(), $parent)->toArray()
        );
        $this->assertRunCancellationUntouched($run);
    }

    public static function requestRaces(): iterable
    {
        yield 'direct duplicate 20 versus 300 seconds' => [false, false];
        yield 'direct duplicate retains inherited root' => [true, false];
        yield 'ancestor conflicts with direct winner' => [false, true];
    }

    /**
     * @return array{pid: int, socket: resource}
     */
    private function forkScopeRequest(string $runId, string $scope, ?string $parent, bool $holdCommit): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 10);
            $ok = false;
            try {
                DB::reconnect();
                $query = DB::connection()->getDriverName() === 'mysql'
                    ? 'SELECT CONNECTION_ID() AS connection_id' : 'SELECT pg_backend_pid() AS connection_id';
                fwrite($sockets[1], json_encode([
                    'connection_id' => (int) DB::selectOne($query)->connection_id,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Scope cancellation actor was not released.');
                }
                if ($holdCommit) {
                    DB::beginTransaction();
                }
                $event = CancellationScopeRequests::request(
                    WorkflowRun::query()->findOrFail($runId),
                    $scope,
                    '1.20',
                    $holdCommit ? 20 : 300,
                    $holdCommit ? 'winner' : 'duplicate',
                    parentScopeId: $parent,
                );
                $result = [
                    'id' => $event->id,
                    'type' => $event->event_type->value,
                    'payload' => $event->payload,
                ];
                if ($holdCommit) {
                    fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
                    if (fgets($sockets[1]) !== "commit\n") {
                        throw new RuntimeException('Scope cancellation commit was not released.');
                    }
                    DB::commit();
                }
                fwrite($sockets[1], json_encode([
                    'result' => $result,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                $ok = true;
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => $error::class . ': ' . $error->getMessage(),
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        return [
            'pid' => $pid,
            'socket' => $sockets[0],
        ];
    }

    /** @param array{pid: int, socket: resource} $actor
     * @return array<string, mixed>
     */
    private function readActor(array $actor): array
    {
        $line = fgets($actor['socket']);
        $this->assertIsString($line, 'Scope cancellation actor did not respond.');
        $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        return $result;
    }

    /** @param array{pid: int, socket: resource} $actor
     * @return array<string, mixed>
     */
    private function finishActor(array $actor): array
    {
        $message = $this->readActor($actor);
        pcntl_waitpid($actor['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        fclose($actor['socket']);
        return $message['result'];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, string, string}
     */
    private function scopeTree(string $name, bool $shield = false): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scope-request-' . $name);
        $workflow->start();
        $run = $workflow->run()
            ->fresh();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'scope-request-fixture',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $parent = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $child = CancellationScopeHistory::open($run, $task, 2, '1.20', $parent, $shield)->payload['scope_id'];
        return [$workflow, $run, $parent, $child];
    }

    private function assertRunCancellationUntouched(WorkflowRun $run): void
    {
        $run = $run->fresh();
        $this->assertFalse($run->status->isTerminal());
        $this->assertNull($run->cancellation_request_command_id);
        $this->assertNull($run->cancellation_requested_at);
        $this->assertNull($run->cancellation_deadline_at);
        $this->assertSame(0, $run->commands()->where('command_type', 'request_cancellation')->count());
    }
}
