<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DenyWorkflowServiceOperationAuthorizer;
use Workflow\V2\Support\RunServiceOperationAuthorizer;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;
use Workflow\V2\Support\ServiceCallIdempotency;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkflowServiceOperationAuthority;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WorkflowServiceOperationAuthorityTest extends TestCase
{
    private WorkflowRun $run;

    private WorkflowTask $task;

    private WorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(WorkflowServiceOperationAuthorizer::class, new RunServiceOperationAuthorizer());
        Carbon::setTestNow('2026-10-25T21:00:00Z');
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
            'workflows.v2.compatibility.current' => 'authority-test',
            'workflows.v2.compatibility.supported' => ['authority-test'],
            'workflows.v2.namespace' => null,
        ]);
        WorkerCompatibilityFleet::clear();
        Queue::fake();
        $workflow = WorkflowStub::make(ServiceAuthorityWorkflow::class, 'authority-owner', 'caller');
        $this->assertTrue($workflow->attemptStart()->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->task = WorkflowTask::query()->where('workflow_run_id', $this->run->id)->sole();
        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
        $this->assertTrue($this->bridge->claimStatus($this->task->id, 'authority-worker')['claimed']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('forgedAuthority')]
    public function testWorkerAuthorityOverridesRefuseTheWholeCompletion(array $options): void
    {
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command() + $options,
        ]);

        $this->assertFalse($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('service_operation_authority_invalid', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function forgedAuthority(): iterable
    {
        yield 'foreign namespace' => [[
            'namespace' => 'foreign',
        ]];
        yield 'foreign caller' => [[
            'caller_namespace' => 'foreign',
        ]];
        yield 'principal subject' => [[
            'principal_subject' => 'administrator',
        ]];
        yield 'principal method' => [[
            'principal_method' => 'system',
        ]];
        yield 'principal roles' => [[
            'principal_roles' => ['admin'],
        ]];
        yield 'principal tenant' => [[
            'principal_tenant' => 'foreign',
        ]];
        yield 'principal claims' => [[
            'principal_claims' => [
                'administrator' => true,
            ],
        ]];
    }

    #[DataProvider('foreignCalls')]
    public function testForeignCallReferencesRefuseBeforeAnyCommandWrites(
        string $status,
        string $mismatch,
        bool $idempotency,
    ): void {
        $call = $this->serviceCall($status);
        $call->forceFill(match ($mismatch) {
            'run' => [
                'caller_workflow_run_id' => 'another-run',
            ],
            'instance' => [
                'caller_workflow_instance_id' => 'another-owner',
            ],
            'caller' => [
                'caller_namespace' => 'another-caller',
            ],
            'catalog' => [
                'operation_name' => 'another-operation',
            ],
            'namespace' => [
                'namespace' => 'foreign',
                'target_namespace' => 'foreign',
            ],
            'endpoint id' => [
                'workflow_service_endpoint_id' => 'foreign-endpoint',
            ],
            'service id' => [
                'workflow_service_id' => 'foreign-service',
            ],
            'operation id' => [
                'workflow_service_operation_id' => 'foreign-operation',
            ],
        })->save();
        $options = $idempotency ? [
            'idempotency_key' => $call->idempotency_key,
        ]
            : [
                'service_call_id' => $call->id,
            ];
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command() + $options,
        ]);

        $this->assertFalse($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('service_call_not_owned', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function foreignCalls(): iterable
    {
        foreach (['pending', 'started', 'completed'] as $status) {
            foreach ([
                'run',
                'instance',
                'caller',
                'catalog',
                'namespace',
                'endpoint id',
                'service id',
                'operation id',
            ] as $mismatch) {
                yield $status . ' ' . $mismatch => [$status, $mismatch, false];
            }
            foreach (['run', 'instance', 'caller'] as $mismatch) {
                yield $status . ' idempotency ' . $mismatch => [$status, $mismatch, true];
            }
        }
    }

    #[DataProvider('ownedCalls')]
    public function testOwnedCallReplayRemainsUsable(string $status, bool $idempotency): void
    {
        $call = $this->serviceCall($status);
        $result = $this->bridge->complete($this->task->id, [
            $this->command() + ($idempotency
                        ? [
                            'idempotency_key' => $call->idempotency_key,
                        ] : [
                            'service_call_id' => $call->id,
                        ])]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertSame($this->run->id, $call->fresh()->caller_workflow_run_id);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ownedCalls(): iterable
    {
        foreach (['started', 'completed'] as $status) {
            yield $status . ' by id' => [$status, false];
            yield $status . ' by key' => [$status, true];
        }
    }

    public function testNewCallsUseTheRunWorkloadPrincipal(): void
    {
        $result = $this->bridge->complete($this->task->id, [$this->command()]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('caller', $call->namespace);
        $this->assertSame('caller', $call->caller_namespace);
        $this->assertSame('workflow:' . $this->run->id, $call->caller_principal_subject);
        $this->assertSame('workflow', $call->caller_principal_method);
        $this->assertNull($call->caller_principal_roles);
        $this->assertNull($call->caller_principal_tenant);
        $this->assertNull($call->caller_principal_claims);
    }

    public function testMissingTrustedContextRefusesBeforeAnyCommandWrites(): void
    {
        $this->app->forgetInstance(WorkflowServiceOperationAuthorizer::class);
        $this->assertInstanceOf(
            DenyWorkflowServiceOperationAuthorizer::class,
            $this->app->make(WorkflowServiceOperationAuthorizer::class)
        );
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command(),
        ]);
        $this->assertFalse($result['completed']);
        $this->assertSame('service_operation_authority_invalid', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testNullRunNamespaceDoesNotUseAmbientConfiguration(): void
    {
        $this->run->instance->forceFill([
            'namespace' => null,
        ])->save();
        $this->run->forceFill([
            'namespace' => null,
        ])->save();
        $this->task->forceFill([
            'namespace' => null,
        ])->save();
        config([
            'workflows.v2.namespace' => 'ambient-namespace',
        ]);

        $result = $this->bridge->complete($this->task->id, [$this->command()]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $call = WorkflowServiceCall::query()->sole();
        $this->assertNull($call->namespace);
        $this->assertNull($call->target_namespace);
        $this->assertNull($call->caller_namespace);
        $this->assertSame($this->run->id, $call->caller_workflow_run_id);
    }

    public function testDefaultIdempotencyKeyCannotReplayAnotherRunsCall(): void
    {
        $call = $this->serviceCall('completed');
        $call->forceFill([
            'caller_workflow_run_id' => 'another-run',
            'idempotency_key' => 'workflow-service-operation:' . $this->run->workflow_instance_id . ':' . $this->run->id . ':2',
        ])->save();
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command(),
        ]);
        $this->assertFalse($result['completed']);
        $this->assertSame('service_call_not_owned', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testRetainedClaimPrefixRefusesForeignCallBeforeWritingItsSibling(): void
    {
        $call = $this->serviceCall('completed');
        $call->forceFill([
            'caller_workflow_instance_id' => 'another-instance',
        ])->save();
        $before = $this->records();
        $result = $this->app->make(\Workflow\V2\Contracts\PreparedLocalActivityTaskBridge::class)
            ->checkpointLocalActivityPrefix(
                $this->task->id,
                'authority-worker',
                $this->task->fresh()
->attempt_count,
                'authority-checkpoint',
                1,
                [
                    [
                        'type' => 'start_timer',
                        'delay_seconds' => 60,
                    ],
                    $this->command() + [
                        'service_call_id' => $call->id,
                    ],
                ],
                '1.20'
            );
        $this->assertFalse($result['checkpointed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('service_call_not_owned', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testDuplicatePrefixStillRequiresCurrentTrustedAuthority(): void
    {
        $call = $this->serviceCall('completed');
        $bridge = $this->app->make(\Workflow\V2\Contracts\PreparedLocalActivityTaskBridge::class);
        $commands = [
            $this->command() + [
                'service_call_id' => $call->id,
            ]];
        $attempt = $this->task->fresh()
->attempt_count;
        $first = $bridge->checkpointLocalActivityPrefix(
            $this->task->id,
            'authority-worker',
            $attempt,
            'authority-checkpoint',
            1,
            $commands,
            '1.20'
        );
        $this->assertTrue($first['checkpointed'], json_encode($first, JSON_THROW_ON_ERROR));
        $before = $this->records();
        $this->app->instance(WorkflowServiceOperationAuthorizer::class, new DenyWorkflowServiceOperationAuthorizer());
        $duplicate = $bridge->checkpointLocalActivityPrefix(
            $this->task->id,
            'authority-worker',
            $attempt,
            'authority-checkpoint',
            1,
            $commands,
            '1.20'
        );
        $this->assertFalse($duplicate['checkpointed'], json_encode($duplicate, JSON_THROW_ON_ERROR));
        $this->assertSame('service_operation_authority_invalid', $duplicate['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testOwnedResolutionFailureCanBeReplayed(): void
    {
        $first = $this->bridge->complete($this->task->id, [$this->command()]);
        $this->assertTrue($first['completed']);
        $call = WorkflowServiceCall::query()->sole();
        $before = $call->getRawOriginal();
        $this->assertSame('failed', $call->status);
        $task = WorkflowTask::query()->whereKey($first['created_task_ids'][0])->sole();
        $this->assertTrue($this->bridge->claimStatus($task->id, 'authority-worker')['claimed']);
        $replay = $this->bridge->complete($task->id, [
            $this->command() + [
                'service_call_id' => $call->id,
            ]]);
        $this->assertTrue($replay['completed'], json_encode($replay, JSON_THROW_ON_ERROR));
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertSame($before, $call->fresh()->getRawOriginal());
    }

    #[DataProvider('duplicateAuthorityChanges')]
    public function testDuplicatePrefixChecksCurrentCallAndCatalogAuthority(string $change, ?string $reason): void
    {
        $call = $this->serviceCall('completed');
        $bridge = $this->app->make(\Workflow\V2\Contracts\PreparedLocalActivityTaskBridge::class);
        $commands = [
            $this->command() + [
                'service_call_id' => $call->id,
            ]];
        $attempt = $this->task->fresh()
->attempt_count;
        $first = $bridge->checkpointLocalActivityPrefix(
            $this->task->id,
            'authority-worker',
            $attempt,
            'authority-checkpoint',
            1,
            $commands,
            '1.20'
        );
        $this->assertTrue($first['checkpointed'], json_encode($first, JSON_THROW_ON_ERROR));
        match ($change) {
            'run' => $call->forceFill([
                'caller_workflow_run_id' => 'another-run',
            ])->save(),
            'instance' => $call->forceFill([
                'caller_workflow_instance_id' => 'another-instance',
            ])->save(),
            'caller' => $call->forceFill([
                'caller_namespace' => 'another-namespace',
            ])->save(),
            'target' => $call->forceFill([
                'target_namespace' => 'another-namespace',
            ])->save(),
            'deleted' => $call->operation->delete(),
            'replacement' => $this->replaceOperation($call),
            'endpoint policy' => $call->endpoint->forceFill([
                'boundary_policy' => [
                    'required_roles' => ['admin'],
                ],
            ])->save(),
            'service policy' => $call->service->forceFill([
                'boundary_policy' => [
                    'required_roles' => ['admin'],
                ],
            ])->save(),
            'operation policy' => $call->operation->forceFill([
                'boundary_policy' => [
                    'required_roles' => ['admin'],
                ],
            ])->save(),
            default => null,
        };
        $before = $this->records();
        $duplicate = $bridge->checkpointLocalActivityPrefix(
            $this->task->id,
            'authority-worker',
            $attempt,
            'authority-checkpoint',
            1,
            $commands,
            '1.20'
        );
        $this->assertSame($reason === null, $duplicate['checkpointed'], json_encode($duplicate, JSON_THROW_ON_ERROR));
        $this->assertSame($reason, $duplicate['reason']);
        $this->assertSame($before, $this->records());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function duplicateAuthorityChanges(): iterable
    {
        foreach (['run', 'instance', 'caller', 'target', 'deleted', 'replacement'] as $change) {
            yield $change => [$change, 'service_call_not_owned'];
        }
        foreach (['endpoint policy', 'service policy', 'operation policy'] as $change) {
            yield $change => [$change, 'missing_required_role'];
        }
        yield 'unchanged owned receipt' => ['none', null];
    }

    #[DataProvider('admissionCaps')]
    public function testOwnedReplayDoesNotReserveFreshAdmissionCapacity(array $rules): void
    {
        $call = $this->serviceCall('completed');
        $policy = new DefaultServiceBoundaryPolicy($rules);
        $this->app->instance(ServiceBoundaryPolicy::class, $policy);
        $request = new ServiceBoundaryRequest(
            principal: new ServiceCallPrincipal(subject: 'workflow:' . $this->run->id, method: 'workflow'),
            callerNamespace: 'caller',
            targetNamespace: 'caller',
            endpointName: 'billing',
            serviceName: 'invoices',
            operationName: 'create',
            operationMode: ServiceCallOperationMode::Async,
        );
        $this->assertTrue($policy->evaluate($request)->isAllowed());
        $this->assertTrue($policy->evaluate($request)->isDenied());
        $result = $this->bridge->complete($this->task->id, [
            $this->command() + [
                'service_call_id' => $call->id,
            ]]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function admissionCaps(): iterable
    {
        yield 'rate' => [[
            'rate_limit' => [
                'requests_per_minute' => 1,
            ],
        ]];
        yield 'concurrency' => [[
            'concurrency' => [
                'max_in_flight' => 1,
            ],
        ]];
    }

    public function testLegacyPolicyWithoutReplayAuthorizationFailsClosed(): void
    {
        $call = $this->serviceCall('completed');
        $this->app->instance(ServiceBoundaryPolicy::class, new class() implements ServiceBoundaryPolicy {
            public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
            {
                throw new \LogicException('Replay must not consume a new admission.');
            }
        });
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            $this->command() + [
                'service_call_id' => $call->id,
            ]]);
        $this->assertFalse($result['completed']);
        $this->assertSame('service_operation_replay_authority_unavailable', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    public function testLegacyPolicyWithPureReplayMethodCanRefuseTheCall(): void
    {
        $call = $this->serviceCall('completed');
        $this->app->instance(ServiceBoundaryPolicy::class, new class() implements ServiceBoundaryPolicy {
            public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
            {
                throw new \LogicException('Replay must not consume a new admission.');
            }

            public function authorizeReplay(ServiceBoundaryRequest $request): ServiceBoundaryDecision
            {
                return ServiceBoundaryDecision::denyAuthorization(
                    reason: 'custom_scope_denied',
                    message: 'Current host replay policy refuses the call.',
                );
            }
        });
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            $this->command() + [
                'service_call_id' => $call->id,
            ]]);
        $this->assertFalse($result['completed']);
        $this->assertSame('custom_scope_denied', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    #[DataProvider('concurrentInsertions')]
    public function testConcurrentKeyInsertionIsReauthorizedAtTheEffectingLookup(
        string $pauseAt,
        bool $foreign,
    ): void {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            $this->markTestSkipped('This interleaving requires independent row-locking transactions.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Process control is required for the transaction interleaving.');
        }
        $call = $this->serviceCall(ServiceCallStatus::Completed->value);
        if ($foreign) {
            $call->caller_workflow_run_id = 'foreign-run';
        }
        $attributes = $call->getAttributes();
        $call->delete();
        $scope = ['caller', 'billing', 'invoices', 'create', 'shared-idempotency-key'];
        DB::purge();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            $ok = false;
            try {
                DB::reconnect();
                DB::statement($driver === 'pgsql'
                    ? 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED'
                    : 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $paused = false;
                $pause = static function () use (&$paused, $sockets): void {
                    if ($paused) {
                        return;
                    }
                    $paused = true;
                    fwrite($sockets[1], "absent\n");
                    if (fgets($sockets[1]) !== "committed\n") {
                        throw new RuntimeException('Concurrent insertion was not committed.');
                    }
                };
                if ($pauseAt === 'lookup') {
                    $lookups = 0;
                    DB::listen(static function (QueryExecuted $query) use ($pause, &$lookups): void {
                        if (str_starts_with(strtolower($query->sql), 'select')
                            && str_contains($query->sql, 'workflow_service_calls')
                            && str_contains($query->sql, 'idempotency_key')) {
                            // The first lookup is the whole-turn preflight;
                            // the second is the last authority check before
                            // the control plane selects its effecting row.
                            if (++$lookups === 2) {
                                $pause();
                            }
                        }
                    });
                } else {
                    WorkflowServiceCall::creating(static function () use ($pause): void {
                        $pause();
                    });
                }
                $result = $this->bridge->complete($this->task->id, [
                    [
                        'type' => 'start_timer',
                        'delay_seconds' => 60,
                    ],
                    $this->command() + [
                        'idempotency_key' => 'shared-idempotency-key',
                    ],
                ]);
                fwrite($sockets[1], json_encode([
                    'result' => $result,
                ], JSON_THROW_ON_ERROR) . "\n");
                $ok = true;
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => $error::class . ': ' . $error->getMessage(),
                ], JSON_THROW_ON_ERROR) . "\n");
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 20);
        try {
            $this->assertSame(
                "absent\n",
                fgets($sockets[0]),
                'Actor must observe the absent key before the competing commit.'
            );
            DB::reconnect();
            DB::transaction(static function () use ($attributes, $pauseAt, $scope): void {
                $insert = $attributes;
                // The lookup case also covers an old writer without a digest.
                if ($pauseAt === 'insert') {
                    $insert['idempotency_scope_hash'] = ServiceCallIdempotency::digest($scope);
                }
                DB::table('workflow_service_calls')->insert($insert);
            });
            $before = $this->records();
            fwrite($sockets[0], "committed\n");
            $message = json_decode((string) fgets($sockets[0]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('error', $message, json_encode($message, JSON_THROW_ON_ERROR));
            $this->assertSame(! $foreign, $message['result']['completed'], json_encode($message, JSON_THROW_ON_ERROR));
            if ($foreign) {
                $this->assertSame('service_call_not_owned', $message['result']['reason']);
                $this->assertSame($before, $this->records());
            } else {
                $this->assertSame(1, WorkflowServiceCall::query()->count());
                $this->assertSame($attributes['id'], WorkflowServiceCall::query()->sole()->id);
                $this->assertSame(
                    ServiceCallIdempotency::digest($scope),
                    WorkflowServiceCall::query()->sole()->idempotency_scope_hash
                );
            }
            pcntl_waitpid($pid, $status);
            $pid = 0;
            $this->assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge();
            DB::reconnect();
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function concurrentInsertions(): iterable
    {
        yield 'legacy foreign insert after absent preflight' => ['lookup', true];
        yield 'legacy same-owner insert after absent preflight' => ['lookup', false];
        yield 'canonical foreign insert wins creation' => ['insert', true];
        yield 'canonical same-owner insert wins creation' => ['insert', false];
    }

    #[DataProvider('canonicalScopes')]
    public function testCanonicalKeyAdoptsLegacyRowsAndSurvivesMigrationRetry(?string $namespace, string $key): void
    {
        $call = $this->serviceCall(ServiceCallStatus::Completed->value);
        $call->forceFill([
            'namespace' => $namespace,
            'target_namespace' => $namespace,
            'idempotency_key' => $key,
        ])->save();
        $scope = [$namespace, 'billing', 'invoices', 'create', $key];
        $this->assertNull($call->idempotency_scope_hash);
        $this->assertSame($call->id, ServiceCallIdempotency::find($scope)->id);
        DB::transaction(static fn () => ServiceCallIdempotency::save($call, $scope));
        $before = $call->fresh()
            ->getRawOriginal();
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_25_000100_add_service_call_idempotency_scope.php';
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertSame($call->id, ServiceCallIdempotency::find($scope)->id);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $other = $scope;
        $other[0] = $namespace === null ? '' : null;
        $this->assertNotSame(ServiceCallIdempotency::digest($scope), ServiceCallIdempotency::digest($other));
        $this->assertNull(ServiceCallIdempotency::find($other));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function canonicalScopes(): iterable
    {
        yield 'null namespace' => [null, 'same-key'];
        yield 'empty namespace' => ['', 'same-key'];
        yield 'maximum ASCII key' => ['caller', str_repeat('a', 191)];
        yield 'maximum Unicode key' => ['caller', str_repeat('界', 191)];
    }

    public function testSqliteUniqueInsertLoserRefetchesTheCanonicalCallInAUsableTransaction(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite has database-wide writer admission; row-lock races are covered separately.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Process control is required.');
        }
        $call = $this->serviceCall(ServiceCallStatus::Completed->value);
        $scope = ['caller', 'billing', 'invoices', 'create', $call->idempotency_key];
        $candidate = $call->replicate();
        $candidate->id = (string) Str::ulid();
        $call->delete();
        DB::purge();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            try {
                DB::reconnect();
                WorkflowServiceCall::creating(static function () use ($sockets): void {
                    fwrite($sockets[1], "insert-ready\n");
                    if (fgets($sockets[1]) !== "committed\n") {
                        throw new RuntimeException('Canonical writer was not committed.');
                    }
                });
                $winner = DB::transaction(static fn () => ServiceCallIdempotency::save($candidate, $scope));
                fwrite($sockets[1], json_encode([
                    'id' => $winner->id,
                    'level' => DB::transactionLevel(),
                ], JSON_THROW_ON_ERROR) . "\n");
                DB::disconnect();
                exit(0);
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => $error::class . ': ' . $error->getMessage(),
                ], JSON_THROW_ON_ERROR) . "\n");
                exit(1);
            }
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 20);
        try {
            $this->assertSame("insert-ready\n", fgets($sockets[0]));
            DB::reconnect();
            $call->exists = false;
            DB::transaction(static fn () => ServiceCallIdempotency::save($call, $scope));
            $before = $this->records();
            fwrite($sockets[0], "committed\n");
            $message = json_decode((string) fgets($sockets[0]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([
                'id' => $call->id,
                'level' => 0,
            ], $message);
            $this->assertSame($before, $this->records());
            pcntl_waitpid($pid, $status);
            $pid = 0;
            $this->assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge();
            DB::reconnect();
        }
        // A canonical row still needs caller authorization after locked refetch.
        $call->caller_workflow_run_id = 'foreign-run';
        $call->save();
        $before = $this->records();
        try {
            DB::transaction(function () use ($candidate, $scope): void {
                $winner = ServiceCallIdempotency::save($candidate, $scope);
                WorkflowServiceOperationAuthority::authorizeExistingCall(
                    $winner,
                    new ServiceCallPrincipal('workflow:' . $this->run->id, 'workflow'),
                    [
                        'namespace' => 'caller',
                        'caller_namespace' => 'caller',
                        'caller_workflow_run_id' => $this->run->id,
                        'caller_workflow_instance_id' => $this->run->workflow_instance_id,
                        'endpoint_name' => 'billing',
                        'service_name' => 'invoices',
                        'operation_name' => 'create',
                    ]
                );
            });
            $this->fail('Foreign canonical winner must be refused.');
        } catch (ServiceOperationAuthorizationException $error) {
            $this->assertSame('service_call_not_owned', $error->getMessage());
        }
        $this->assertSame($before, $this->records());
    }

    public function testSeparateCallConnectionRefusesBeforeSiblingWrites(): void
    {
        config([
            'database.connections.authority_other' => config('database.connections.' . DB::getDefaultConnection()),
            'workflows.v2.service_call_model' => SeparateAuthorityServiceCall::class,
        ]);
        $before = $this->records();
        $result = $this->bridge->complete($this->task->id, [
            [
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ],
            $this->command(),
        ]);
        $this->assertFalse($result['completed']);
        $this->assertSame('service_operation_storage_connection_mismatch', $result['reason']);
        $this->assertSame($before, $this->records());
    }

    private function replaceOperation(WorkflowServiceCall $call): void
    {
        $replacement = $call->operation->replicate();
        $call->operation->delete();
        $replacement->save();
        $this->assertNotSame($call->workflow_service_operation_id, $replacement->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function command(): array
    {
        return [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
        ];
    }

    private function serviceCall(string $status): WorkflowServiceCall
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'caller',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'caller',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);
        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'caller',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'create',
            'operation_mode' => 'async',
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'handler_target_reference' => 'service-authority-workflow',
        ]);
        return WorkflowServiceCall::query()->create([
            'namespace' => 'caller',
            'target_namespace' => 'caller',
            'caller_namespace' => 'caller',
            'caller_workflow_instance_id' => $this->run->workflow_instance_id,
            'caller_workflow_run_id' => $this->run->id,
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => 'billing',
            'service_name' => 'invoices',
            'operation_name' => 'create',
            'operation_mode' => 'async',
            'status' => $status,
            'outcome' => $status === 'completed' ? ServiceCallOutcome::Completed->value
                            : ServiceCallOutcome::Accepted->value,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
            'idempotency_key' => 'shared-idempotency-key',
            'accepted_at' => $status === ServiceCallStatus::Pending->value ? null : now(),
            'started_at' => $status === ServiceCallStatus::Started->value ? now() : null,
            'completed_at' => $status === ServiceCallStatus::Completed->value ? now() : null,
        ]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class, WorkflowCommand::class,
            WorkflowHistoryEvent::class, WorkflowRunSummary::class, WorkflowServiceCall::class,
            WorkflowServiceEndpoint::class, WorkflowService::class, WorkflowServiceOperation::class,
            WorkflowTimer::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal(),
            )->all();
        }
        return $records;
    }
}

#[Type('service-authority-workflow')]
final class ServiceAuthorityWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'done';
    }
}

final class SeparateAuthorityServiceCall extends WorkflowServiceCall
{
    public function getConnectionName(): ?string
    {
        return 'authority_other';
    }
}
