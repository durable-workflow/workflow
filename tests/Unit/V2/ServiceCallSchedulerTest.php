<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\ServiceBoundaryReplayPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Jobs\RunServiceCall;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallAttemptClaim;
use Workflow\V2\Support\ServiceCallEffectIdentity;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ServiceCallSchedulerTest extends TestCase
{
    private WorkflowRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('queue.default', 'redis');
        Carbon::setTestNow('2026-10-26T01:30:00Z');
        Queue::fake();
        $workflow = WorkflowStub::make(ServiceSchedulerCallerWorkflow::class, 'scheduler-caller', 'caller');
        $this->assertTrue($workflow->attemptStart()->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testCompletionTransactionOnlyPersistsScheduleAndEnqueuesAfterCommit(): void
    {
        $operation = $this->catalog([
            'max_attempts' => 3,
            'backoff_seconds' => 3_600,
        ]);
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        $control = new DefaultServiceControlPlane($workflows);

        $started = hrtime(true);
        DB::transaction(function () use ($control): void {
            $result = $control->execute('billing', 'invoices', 'approve', $this->callOptions());

            $this->assertTrue($result['accepted']);
            $this->assertSame('accepted', $result['status']);
            $this->assertSame('accepted', $result['wait_for']);
            $this->assertTrue($result['retry_scheduled']);
            $this->assertSame('accepted', $result['handler']['wait_for']);
            $this->assertTrue($result['handler']['retry_scheduled']);
            $this->assertSame(1, WorkflowServiceCallDispatch::query()->count());
            Queue::assertNothingPushed();
        });
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $call = WorkflowServiceCall::query()->sole();
        $dispatch = WorkflowServiceCallDispatch::query()->sole();
        $this->assertSame($operation->id, $call->workflow_service_operation_id);
        $this->assertSame(ServiceCallScheduler::STATE_SCHEDULED, $dispatch->state);
        $this->assertSame(1, $dispatch->attempt);
        $this->assertSame(3, $dispatch->max_attempts);
        $this->assertFalse(ServiceCallScheduler::hasAfterCommitAuthority($dispatch->dispatch_options));
        $this->assertLessThan(1_000, $elapsedMilliseconds);
        Queue::assertPushed(RunServiceCall::class, static fn (RunServiceCall $job): bool =>
            $job->serviceCallId === $call->id);
    }

    public function testCallerContextAndIdempotencyTransactionDoNotScheduleAnHttpExecution(): void
    {
        $this->catalog();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->withArgs(static function (string $instanceId, string $name, array $options): bool {
                return $instanceId === 'accepted-target'
                    && $name === 'approve'
                    && WorkflowServiceCall::query()->getModel()->getConnection()->transactionLevel() === 1
                    && $options['wait_for'] === 'completed'
                    && $options['wait_timeout_seconds'] === 60;
            })
            ->andReturn([
                'accepted' => true,
                'update_status' => 'completed',
                'workflow_instance_id' => 'accepted-target',
                'run_id' => 'target-run',
                'update_id' => 'target-update',
                'result' => [
                    'approved' => true,
                ],
            ]);
        $options = $this->callOptions(false);
        $control = new DefaultServiceControlPlane($workflows);
        $admitted = $control->execute('billing', 'invoices', 'approve', $options + [
            'dispatch_handler' => false,
        ]);
        $options['service_call_id'] = $admitted['service_call_id'];
        $options['boundary_policy_outcome'] = ServiceCallOutcome::Accepted->value;

        $result = $control->execute('billing', 'invoices', 'approve', $options);

        $this->assertTrue($result['accepted']);
        $this->assertSame(ServiceCallStatus::Completed->value, $result['status']);
        $this->assertSame(ServiceCallOutcome::Completed->value, $result['outcome']);
        $this->assertArrayNotHasKey('retry_scheduled', $result);
        $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function testCallerContextHttpExecutionPreservesInlineTypedFailure(): void
    {
        $this->catalog();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->andReturn([
                'accepted' => false,
                'reason' => 'approval_denied',
                'message' => 'The invoice approval was denied.',
                'service_error_type' => 'invoice_declined',
            ]);
        $options = $this->callOptions(false);
        $control = new DefaultServiceControlPlane($workflows);
        $admitted = $control->execute('billing', 'invoices', 'approve', $options + [
            'dispatch_handler' => false,
        ]);
        $options['service_call_id'] = $admitted['service_call_id'];
        $options['boundary_policy_outcome'] = ServiceCallOutcome::Accepted->value;

        $result = $control->execute('billing', 'invoices', 'approve', $options);

        $this->assertFalse($result['accepted']);
        $this->assertSame(ServiceCallStatus::Failed->value, $result['status']);
        $this->assertSame('approval_denied', $result['reason']);
        $this->assertSame('invoice_declined', $result['service_error_type']);
        $this->assertSame('The invoice approval was denied.', $result['message']);
        $this->assertArrayNotHasKey('retry_scheduled', $result);
        $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function testRollbackLeavesNoCallScheduleOrRunnableJob(): void
    {
        $this->catalog();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        $control = new DefaultServiceControlPlane($workflows);

        DB::beginTransaction();
        try {
            $control->execute('billing', 'invoices', 'approve', $this->callOptions());
            throw new RuntimeException('rollback control');
        } catch (RuntimeException $failure) {
            $this->assertSame('rollback control', $failure->getMessage());
            DB::rollBack();
        }

        $this->assertSame(0, WorkflowServiceCall::query()->count());
        $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function testExcessiveWaitIsRejectedBeforeCallOrSchedulePersistence(): void
    {
        $this->catalog();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        $control = new DefaultServiceControlPlane($workflows);

        try {
            DB::transaction(fn (): array => $control->execute(
                'billing',
                'invoices',
                'approve',
                array_replace($this->callOptions(), [
                    'wait_timeout_seconds' => 61,
                ]),
            ));
        } catch (ValidationException $exception) {
            $this->assertSame([
                'wait_timeout_seconds' => ['The wait_timeout_seconds field must not exceed 60 seconds.'],
            ], $exception->errors());
            $this->assertSame(0, WorkflowServiceCall::query()->count());
            $this->assertSame(0, WorkflowServiceCallDispatch::query()->count());
            Queue::assertNothingPushed();

            return;
        }

        $this->fail('An excessive wait must be rejected before scheduler persistence.');
    }

    public function testClaimUsesCurrentPolicyButExecutesTheImmutableAcceptedBindingOutsideTransactions(): void
    {
        $operation = $this->catalog();
        $this->schedule();
        $operation->forceFill([
            'handler_binding' => [
                'workflow_instance_id' => 'changed-target',
                'update_name' => 'changed-update',
            ],
            'handler_target_reference' => 'changed-update',
        ])->save();

        $claim = ServiceCallScheduler::claim(WorkflowServiceCall::query()->sole()->id);
        $this->assertNotNull($claim);
        $this->assertSame('accepted-target', $claim->operation->handler_binding['workflow_instance_id']);
        $this->assertSame('approve', $claim->operation->handler_binding['update_name']);
        $this->assertSame(1, $claim->attempt);
        $this->assertSame(1, $claim->generation);
        $this->assertSame(64, strlen($claim->catalogRevision));
        $this->assertSame(64, strlen($claim->policyRevision));
        $claimedDispatch = WorkflowServiceCallDispatch::query()->sole();
        $this->assertSame($claim->catalogRevision, $claimedDispatch->authorized_catalog_revision);
        $this->assertSame($claim->policyRevision, $claimedDispatch->authorized_policy_revision);
        $effectIdentity = ServiceCallEffectIdentity::forCurrentClaim($claim);
        $this->assertInstanceOf(ServiceCallEffectIdentity::class, $effectIdentity);
        $effectToken = $effectIdentity->storageToken();
        $this->assertStringStartsWith('service-call-v1:', $effectToken);
        $this->assertNotSame(
            'service-call-v1:' . hash('sha256', ServiceCallScheduler::attemptIdentity($claim->call->id, 1)),
            $effectToken,
        );

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->withArgs(static function (string $instanceId, string $name, array $options) use (
                $claim,
                $effectToken,
            ): bool {
                return DB::connection()->transactionLevel() === 0
                    && $instanceId === 'accepted-target'
                    && $name === 'approve'
                    && $options['wait_for'] === 'accepted'
                    && $options['service_call_id'] === $claim->call->id
                    && $options['service_call_attempt'] === 1
                    && $options['service_call_max_attempts'] === 1
                    && ! array_key_exists('service_call_idempotency_key', $options)
                    && ServiceCallEffectIdentity::fromOptions($options)?->storageToken() === $effectToken;
            })
            ->andReturn([
                'accepted' => true,
                'update_status' => 'accepted',
                'workflow_instance_id' => 'accepted-target',
                'run_id' => 'target-run',
                'update_id' => 'target-update',
            ]);

        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);

        $call = WorkflowServiceCall::query()->sole();
        $dispatch = WorkflowServiceCallDispatch::query()->sole();
        $this->assertSame(ServiceCallStatus::Started->value, $call->status);
        $this->assertSame('accepted-target', $call->linked_workflow_instance_id);
        $this->assertSame('target-update', $call->linked_workflow_update_id);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, $dispatch->state);
        $this->assertCount(1, $call->metadata['service_call_attempts']);
        $this->assertFalse($call->metadata['service_call_attempts'][0]['retry_scheduled']);
    }

    public function testRetryBackoffIsPersistedWithoutSleepingOrHoldingTheCompletionLock(): void
    {
        $this->catalog([
            'max_attempts' => 2,
            'backoff_seconds' => 3_600,
        ]);
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $claim = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($claim);
        $firstIdentity = ServiceCallEffectIdentity::forCurrentClaim($claim);
        $this->assertInstanceOf(ServiceCallEffectIdentity::class, $firstIdentity);
        Queue::fake();

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->andThrow(new RuntimeException('retryable update failure'));

        $started = hrtime(true);
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $dispatch = WorkflowServiceCallDispatch::query()->sole();
        $this->assertLessThan(1_000, $elapsedMilliseconds);
        $this->assertSame(ServiceCallScheduler::STATE_SCHEDULED, $dispatch->state);
        $this->assertSame(2, $dispatch->attempt);
        $this->assertTrue($dispatch->available_at->equalTo(now()->addHour()));
        $this->assertSame(ServiceCallStatus::Started->value, $call->refresh()->status);
        $this->assertTrue($call->metadata['service_call_attempts'][0]['retry_scheduled']);
        $this->assertSame(3_600, $call->metadata['service_call_attempts'][0]['scheduled_backoff_seconds']);
        Queue::assertPushed(RunServiceCall::class, static fn (RunServiceCall $job): bool =>
            $job->serviceCallId === $call->id);

        Carbon::setTestNow(now()->addHour());
        $finalClaim = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($finalClaim);
        $finalIdentity = ServiceCallEffectIdentity::forCurrentClaim($finalClaim);
        $this->assertInstanceOf(ServiceCallEffectIdentity::class, $finalIdentity);
        $this->assertNotSame($firstIdentity->storageToken(), $finalIdentity->storageToken());
        $workflows->shouldReceive('update')
            ->once()
            ->andThrow(new RuntimeException('final update failure'));
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($finalClaim);

        $dispatch->refresh();
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, $dispatch->state);
        $this->assertStringNotContainsString(
            $finalIdentity->storageToken(),
            json_encode($dispatch->dispatch_options, JSON_THROW_ON_ERROR),
        );
        $call->refresh();
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertCount(2, $call->metadata['service_call_attempts']);
        $this->assertFalse($call->metadata['service_call_attempts'][1]['retry_scheduled']);
    }

    public function testFinalScheduledFailureReleasesBoundaryAdmission(): void
    {
        $this->catalog();
        $policy = new DefaultServiceBoundaryPolicy([
            'concurrency' => [
                'max_in_flight' => 1,
                'sync_only' => false,
            ],
        ]);
        $this->schedule($policy);
        $claim = ServiceCallScheduler::claim(WorkflowServiceCall::query()->sole()->id);
        $this->assertNotNull($claim);

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->andReturn([
                'accepted' => false,
                'reason' => 'target_unavailable',
                'message' => 'Target is unavailable.',
            ]);
        (new DefaultServiceControlPlane($workflows, $policy))->executeScheduledAttempt($claim);

        Queue::fake();
        /** @var WorkflowControlPlane&MockInterface $nextWorkflows */
        $nextWorkflows = Mockery::mock(WorkflowControlPlane::class);
        $nextWorkflows->shouldNotReceive('update');
        $next = DB::transaction(fn (): array => (new DefaultServiceControlPlane($nextWorkflows, $policy))->execute(
            'billing',
            'invoices',
            'approve',
            array_replace($this->callOptions(), [
                'idempotency_key' => 'scheduler-call-after-failure',
            ]),
        ));

        $this->assertTrue($next['accepted']);
        $this->assertSame(2, WorkflowServiceCall::query()->count());
        $this->assertSame(2, WorkflowServiceCallDispatch::query()->count());
        Queue::assertPushed(RunServiceCall::class, 1);
    }

    public function testExpiredClaimCanBeRepairedAndAStaleWorkerCannotAdvanceIt(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $first = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($first);
        $firstIdentity = ServiceCallEffectIdentity::forCurrentClaim($first);
        $this->assertInstanceOf(ServiceCallEffectIdentity::class, $firstIdentity);
        WorkflowServiceCallDispatch::query()->whereKey($call->id)->update([
            'claim_expires_at' => now()
                ->subSecond(),
        ]);
        $second = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($second);
        $this->assertSame($first->generation + 1, $second->generation);
        $this->assertNotSame($first->token, $second->token);
        $this->assertSame(
            $first->options['service_call_idempotency_key'],
            $second->options['service_call_idempotency_key'],
        );
        $this->assertNull(ServiceCallEffectIdentity::forCurrentClaim($first));
        $secondIdentity = ServiceCallEffectIdentity::forCurrentClaim($second);
        $this->assertInstanceOf(ServiceCallEffectIdentity::class, $secondIdentity);
        $this->assertSame($firstIdentity->storageToken(), $secondIdentity->storageToken());
        $differentEffectToken = $secondIdentity->storageToken() === 'service-call-v1:' . str_repeat('f', 64)
            ? 'service-call-v1:' . str_repeat('e', 64)
            : 'service-call-v1:' . str_repeat('f', 64);
        $forged = new ServiceCallAttemptClaim(
            call: $second->call,
            operation: $second->operation,
            request: $second->request,
            options: $second->options,
            attempt: $second->attempt,
            maxAttempts: $second->maxAttempts,
            generation: $second->generation,
            token: $second->token,
            catalogRevision: $second->catalogRevision,
            policyRevision: $second->policyRevision,
            effectToken: $differentEffectToken,
        );
        $this->assertFalse(ServiceCallScheduler::ownsClaim($forged));
        $this->assertNull(ServiceCallEffectIdentity::forCurrentClaim($forged));

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($first);

        $dispatch = WorkflowServiceCallDispatch::query()->sole();
        $this->assertSame(ServiceCallScheduler::STATE_CLAIMED, $dispatch->state);
        $this->assertSame($second->generation, $dispatch->claim_generation);
        $this->assertSame($second->token, $dispatch->claim_token);
        $this->assertSame(ServiceCallStatus::Accepted->value, $call->fresh()->status);
    }

    public function testAnUnexpiredClaimCannotBeClaimedConcurrently(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $first = ServiceCallScheduler::claim($call->id);

        $this->assertNotNull($first);
        $this->assertNull(ServiceCallScheduler::claim($call->id));
        $this->assertSame($first->token, WorkflowServiceCallDispatch::query()->sole()->claim_token);
    }

    public function testRepairReenqueuesALostDueDispatch(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        Queue::fake();
        WorkflowServiceCallDispatch::query()->whereKey($call->id)->update([
            'available_at' => now()
                ->subSecond(),
            'dispatch_attempted_at' => now()
                ->subMinute(),
            'repair_available_at' => now()
                ->subSecond(),
        ]);

        $this->assertSame([], ServiceCallScheduler::dueIds(10, ['another-run']));
        $this->assertSame([$call->id], ServiceCallScheduler::dueIds(10, [$this->run->id]));
        $this->assertSame(
            [$call->id],
            ServiceCallScheduler::dueIds(
                10,
                instanceId: $this->run->workflow_instance_id,
                connection: $this->run->connection,
                queue: $this->run->queue,
            ),
        );

        $report = ServiceCallScheduler::repairDue(10);

        $this->assertSame(1, $report['candidates']);
        $this->assertSame(1, $report['enqueued']);
        $this->assertSame([], $report['failures']);
        Queue::assertPushed(RunServiceCall::class, static fn (RunServiceCall $job): bool =>
            $job->serviceCallId === $call->id);
    }

    public function testRepairReenqueuesAnExpiredCrashClaimAndTheNextWorkerAdvancesItsGeneration(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $crashed = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($crashed);
        Queue::fake();
        Carbon::setTestNow(now()->addSeconds(61));

        $report = ServiceCallScheduler::repairDue(10);

        $this->assertSame(1, $report['candidates']);
        $this->assertSame(1, $report['enqueued']);
        Queue::assertPushed(RunServiceCall::class, static fn (RunServiceCall $job): bool =>
            $job->serviceCallId === $call->id);
        $replacement = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($replacement);
        $this->assertSame($crashed->generation + 1, $replacement->generation);
        $this->assertNotSame($crashed->token, $replacement->token);
    }

    public function testCurrentPolicyRevocationFailsTheCallBeforeEffect(): void
    {
        $this->catalog();
        $policy = new DefaultServiceBoundaryPolicy();
        $this->app->instance(ServiceBoundaryPolicy::class, $policy);
        $this->schedule($policy);
        $policy->setRules([
            'default_action' => 'deny',
        ]);

        $claim = ServiceCallScheduler::claim(WorkflowServiceCall::query()->sole()->id);

        $this->assertNull($claim);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $call->outcome);
        $this->assertSame('default_action_denied', $call->outcome_reason);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testRemovedCatalogOperationFailsTheCallBeforeEffect(): void
    {
        $operation = $this->catalog();
        $this->schedule();
        $operation->delete();

        $this->assertNull(ServiceCallScheduler::claim(WorkflowServiceCall::query()->sole()->id));
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame(ServiceCallStatus::Failed->value, $call->status);
        $this->assertSame('service_call_not_owned', $call->outcome_reason);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testIncompleteAcceptedAuthorityFailsClosedBeforeEffect(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $call->forceFill([
            'caller_principal_method' => null,
        ])->save();

        $this->assertNull(ServiceCallScheduler::claim($call->id));
        $this->assertSame(ServiceCallStatus::Failed->value, $call->fresh()->status);
        $this->assertSame('service_operation_replay_authority_incomplete', $call->fresh()->outcome_reason);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testTransientReplayPolicyFailureRollsBackClaimForRepair(): void
    {
        $this->catalog();
        $this->schedule(new DefaultServiceBoundaryPolicy());
        /** @var ServiceBoundaryReplayPolicy&MockInterface $policy */
        $policy = Mockery::mock(ServiceBoundaryReplayPolicy::class);
        $policy->shouldReceive('authorizeReplay')
            ->once()
            ->andThrow(new RuntimeException('policy backend unavailable'));
        app()
            ->instance(ServiceBoundaryPolicy::class, $policy);

        try {
            ServiceCallScheduler::claim(WorkflowServiceCall::query()->sole()->id);
        } catch (RuntimeException $failure) {
            $this->assertSame('policy backend unavailable', $failure->getMessage());
            $this->assertSame(ServiceCallStatus::Accepted->value, WorkflowServiceCall::query()->sole()->status);
            $dispatch = WorkflowServiceCallDispatch::query()->sole();
            $this->assertSame(ServiceCallScheduler::STATE_SCHEDULED, $dispatch->state);
            $this->assertSame(0, $dispatch->claim_generation);
            $this->assertNull($dispatch->claim_token);

            return;
        }

        $this->fail('A transient replay-policy failure must escape so the durable repair path can retry it.');
    }

    public function testTerminalCallerFencesAttempts(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $this->run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_at' => now(),
        ])->save();

        $this->assertNull(ServiceCallScheduler::claim($call->id));
        $this->assertSame(ServiceCallStatus::Cancelled->value, $call->fresh()->status);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testCallerCancellationRequestedAfterCommitFencesTheEffect(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $request = WorkflowStub::loadRun($this->run->id)
            ->attemptRequestCancellation('cancel before scheduled effect', 30);

        $this->assertTrue($request->accepted());
        $this->assertNull(ServiceCallScheduler::claim($call->id));
        $call->refresh();
        $this->assertSame(ServiceCallStatus::Cancelled->value, $call->status);
        $this->assertSame(ServiceCallOutcome::Cancelled, $call->outcome);
        $this->assertSame('caller_cancellation_requested', $call->outcome_reason);
        $this->assertSame($request->commandId(), $call->outcome_metadata['cancellation_request_command_id']);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testExpiredCallDeadlineFencesAttempts(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $call->forceFill([
            'call_deadline_at' => now()
                ->subSecond(),
        ])->save();

        $this->assertNull(ServiceCallScheduler::claim($call->id));
        $this->assertSame(ServiceCallStatus::Failed->value, $call->fresh()->status);
        $this->assertSame(ServiceCallOutcome::TimedOut, $call->fresh()->outcome);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testCancellationAfterClaimStillFencesTheEffect(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $claim = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($claim);
        $request = WorkflowStub::loadRun($this->run->id)
            ->attemptRequestCancellation('cancel after claim', 30);
        $this->assertTrue($request->accepted());

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);

        $this->assertSame(ServiceCallStatus::Cancelled->value, $call->fresh()->status);
        $this->assertSame('caller_cancellation_requested', $call->fresh()->outcome_reason);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testTerminalCallerAfterClaimStillFencesTheEffect(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $claim = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($claim);
        $this->run->forceFill([
            'status' => RunStatus::Completed->value,
            'closed_at' => now(),
        ])->save();

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);

        $this->assertSame(ServiceCallStatus::Cancelled->value, $call->fresh()->status);
        $this->assertSame('caller_run_terminal', $call->fresh()->outcome_reason);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public function testDeadlineAfterClaimStillFencesTheEffect(): void
    {
        $this->catalog();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $claim = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($claim);
        $call->forceFill([
            'call_deadline_at' => now()
                ->subSecond(),
        ])->save();

        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        (new DefaultServiceControlPlane($workflows))->executeScheduledAttempt($claim);

        $this->assertSame(ServiceCallStatus::Failed->value, $call->fresh()->status);
        $this->assertSame(ServiceCallOutcome::TimedOut, $call->fresh()->outcome);
        $this->assertSame(ServiceCallScheduler::STATE_COMPLETED, WorkflowServiceCallDispatch::query()->sole()->state);
    }

    public static function generatedTargetReferences(): iterable
    {
        yield 'workflow start' => [ServiceCallBindingKind::WorkflowRun, 'workflow_instance_id', false];
        yield 'activity' => [ServiceCallBindingKind::ActivityExecution, 'activity_execution_id', true];
        yield 'carrier' => [ServiceCallBindingKind::InvocableCarrierRequest, 'carrier_request_id', true];
    }

    #[DataProvider('generatedTargetReferences')]
    public function testGeneratedEffectTargetIsFrozenAcrossCrashRedelivery(
        ServiceCallBindingKind $kind,
        string $option,
        bool $generatedAtClaim,
    ): void {
        $operation = $this->catalog();
        $operation->forceFill([
            'handler_binding_kind' => $kind->value,
            'handler_binding' => [],
            'handler_target_reference' => $kind === ServiceCallBindingKind::WorkflowRun
                ? ServiceSchedulerTargetWorkflow::class
                : null,
        ])->save();
        $this->schedule();
        $call = WorkflowServiceCall::query()->sole();
        $scheduled = WorkflowServiceCallDispatch::query()->sole();
        if ($generatedAtClaim) {
            $this->assertArrayNotHasKey($option, $scheduled->dispatch_options);
        } else {
            $this->assertIsString($scheduled->dispatch_options[$option] ?? null);
        }
        $first = ServiceCallScheduler::claim($call->id);
        $this->assertNotNull($first);
        $target = $first->options[$option] ?? null;
        $this->assertIsString($target);
        $this->assertNotSame('', $target);
        $this->assertSame($target, $scheduled->fresh()->dispatch_options[$option]);
        WorkflowServiceCallDispatch::query()->whereKey($call->id)->update([
            'claim_expires_at' => now()
                ->subSecond(),
        ]);

        $replacement = ServiceCallScheduler::claim($call->id);

        $this->assertNotNull($replacement);
        $this->assertSame($target, $replacement->options[$option]);
        $this->assertSame(
            ServiceCallScheduler::attemptIdentity($call->id, 1),
            $replacement->options['service_call_idempotency_key'],
        );
        $this->assertFalse(ServiceCallScheduler::completeAttempt($first, [], false, null));
        $this->assertSame($target, $scheduled->fresh()->dispatch_options[$option]);
    }

    public function testRepairUsesConfiguredCallRelationAndDeletesOnlyAnAgedBoundedBatchOfOrphans(): void
    {
        config()->set('workflows.v2.service_call_dispatch.orphan_grace_seconds', 0);
        $this->catalog();
        $this->schedule();
        $live = WorkflowServiceCallDispatch::query()->sole();
        $live->forceFill([
            'created_at' => now()
                ->subHours(2),
        ])->save();
        $orphanIds = [];
        $rows = [];
        $old = now()
            ->subHours(2);
        foreach (range(1, 101) as $sequence) {
            $id = '01JB' . str_pad((string) $sequence, 22, '0', STR_PAD_LEFT);
            $orphanIds[] = $id;
            $rows[] = [
                'service_call_id' => $id,
                'state' => ServiceCallScheduler::STATE_SCHEDULED,
                'dispatch_options' => '[]',
                'attempt' => 1,
                'max_attempts' => 1,
                'available_at' => now(),
                'claim_generation' => 0,
                'created_at' => $old,
                'updated_at' => $old,
            ];
        }
        $recentId = '01JC' . str_repeat('0', 22);
        $recent = now()
            ->subMinutes(30);
        $rows[] = [
            'service_call_id' => $recentId,
            'state' => ServiceCallScheduler::STATE_SCHEDULED,
            'dispatch_options' => '[]',
            'attempt' => 1,
            'max_attempts' => 1,
            'available_at' => now(),
            'claim_generation' => 0,
            'created_at' => $recent,
            'updated_at' => $recent,
        ];
        WorkflowServiceCallDispatch::query()->insert($rows);
        Schema::create('scheduler_configured_service_calls', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
        });
        DB::table('scheduler_configured_service_calls')
            ->insert([
                'id' => $live->service_call_id,
            ]);
        config()
            ->set('workflows.v2.service_call_model', SchedulerConfiguredServiceCall::class);

        $this->assertSame(100, ServiceCallScheduler::cleanupOrphans(PHP_INT_MAX));
        $this->assertFalse(WorkflowServiceCallDispatch::query()->whereKey($orphanIds[0])->exists());
        $this->assertTrue(WorkflowServiceCallDispatch::query()->whereKey($orphanIds[100])->exists());
        $this->assertSame(1, ServiceCallScheduler::cleanupOrphans(PHP_INT_MAX));
        $this->assertFalse(WorkflowServiceCallDispatch::query()->whereKey($orphanIds[100])->exists());
        $this->assertSame(0, ServiceCallScheduler::cleanupOrphans(PHP_INT_MAX));
        $this->assertTrue($live->fresh()->exists);
        $this->assertTrue(WorkflowServiceCallDispatch::query()->whereKey($recentId)->exists());
    }

    private function schedule(?DefaultServiceBoundaryPolicy $policy = null): array
    {
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldNotReceive('update');
        $control = new DefaultServiceControlPlane($workflows, $policy);

        return DB::transaction(fn (): array => $control->execute(
            'billing',
            'invoices',
            'approve',
            $this->callOptions(),
        ));
    }

    /**
     * @param array<string, mixed>|null $retryPolicy
     */
    private function catalog(?array $retryPolicy = null): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);

        return WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'approve',
            'operation_mode' => ServiceCallOperationMode::Sync->value,
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowUpdate->value,
            'handler_binding' => [
                'workflow_instance_id' => 'accepted-target',
                'update_name' => 'approve',
            ],
            'handler_target_reference' => 'approve',
            'retry_policy' => $retryPolicy,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callOptions(bool $scheduleAfterCommit = true): array
    {
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'caller',
            'caller_workflow_instance_id' => $this->run->workflow_instance_id,
            'caller_workflow_run_id' => $this->run->id,
            'principal' => new ServiceCallPrincipal(subject: 'workflow:' . $this->run->id, method: 'workflow'),
            'idempotency_key' => 'scheduler-call',
            'wait_for' => 'completed',
            'wait_timeout_seconds' => 60,
        ];

        return $scheduleAfterCommit
            ? ServiceCallScheduler::withAfterCommitAuthority($options)
            : $options;
    }
}

#[Type('service-scheduler-caller')]
final class ServiceSchedulerCallerWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'done';
    }
}

#[Type('service-scheduler-target')]
final class ServiceSchedulerTargetWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'target';
    }
}

final class SchedulerConfiguredServiceCall extends WorkflowServiceCall
{
    protected $table = 'scheduler_configured_service_calls';
}
