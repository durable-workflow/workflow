<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

final class DefaultServiceControlPlaneRetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T04:30:00Z');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $policy
     */
    #[DataProvider('attemptLimits')]
    public function testAttemptLimitsBoundDispatchAndPersistOnlyTheFinalFailure(array $policy, int $attempts): void
    {
        $this->executeRetryScenario($policy, array_fill(0, $attempts - 1, 0.0), false);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function attemptLimits(): iterable
    {
        yield 'omitted policy permits one attempt' => [[], 1];
        yield 'zero integer does not disable dispatch' => [[
            'max_attempts' => 0,
        ], 1];
        yield 'negative integer falls back to one' => [[
            'max_attempts' => -2,
        ], 1];
        yield 'zero digit string falls back to one' => [[
            'max_attempts' => ' 0 ',
        ], 1];
        yield 'non integral values are not attempt counts' => [[
            'max_attempts' => true,
            'maximum_attempts' => '2.5',
            'maximumAttempts' => 2.5,
        ], 1];
        yield 'trimmed digit count' => [[
            'max_attempts' => ' 03 ',
        ], 3];
        yield 'snake case maximum alias' => [[
            'maximum_attempts' => 2,
        ], 2];
        yield 'camel case maximum alias' => [[
            'maximumAttempts' => ' 2 ',
        ], 2];
        yield 'invalid primary count permits valid later alias' => [[
            'max_attempts' => 'invalid',
            'maximum_attempts' => -1,
            'maximumAttempts' => '2',
        ], 2];
        yield 'first valid attempt count wins' => [[
            'max_attempts' => 2,
            'maximum_attempts' => 5,
            'maximumAttempts' => 7,
        ], 2];
        yield 'integer count capped at ten' => [[
            'max_attempts' => 50,
        ], 10];
        yield 'digit count capped at ten' => [[
            'maximumAttempts' => '100',
        ], 10];
    }

    /**
     * @param array<string, mixed> $policy
     * @param list<float> $delays
     */
    #[DataProvider('backoffPolicies')]
    public function testBackoffPolicyRecordsBoundedDelaysBeforeSuccessfulCompletion(array $policy, array $delays): void
    {
        $this->executeRetryScenario([
            'max_attempts' => 4,
        ] + $policy, $delays, true);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<float>}>
     */
    public static function backoffPolicies(): iterable
    {
        yield 'omitted delay is immediate' => [[], [0.0, 0.0, 0.0]];
        yield 'scalar numeric string applies to every retry' => [[
            'backoff_seconds' => ' 0.000001 ',
        ], [0.000001, 0.000001, 0.000001]];
        yield 'camel scalar float applies to every retry' => [[
            'backoffSeconds' => 0.000002,
        ], [0.000002, 0.000002, 0.000002]];
        yield 'scalar negative integer is clamped' => [[
            'backoff_seconds' => -1,
        ], [0.0, 0.0, 0.0]];
        yield 'non numeric scalar is immediate' => [[
            'backoff_seconds' => 'invalid',
        ], [0.0, 0.0, 0.0]];
        yield 'boolean scalar is not a delay' => [[
            'backoffSeconds' => true,
        ], [0.0, 0.0, 0.0]];
        yield 'null explicit delay overrides exponential fallback' => [[
            'backoff_seconds' => null,
            'initial_interval_seconds' => 0.000001,
        ], [0.0, 0.0, 0.0]];
        yield 'primary explicit delay overrides later alias' => [[
            'backoff_seconds' => 'invalid',
            'backoffSeconds' => 0.000001,
        ], [0.0, 0.0, 0.0]];
        yield 'associative schedule uses value order and clamps invalid entries' => [[
            'backoff_seconds' => [
                'first' => ' 1e-6 ',
                'second' => -1,
                'third' => ['invalid'],
            ],
        ], [0.000001, 0.0, 0.0]];
        yield 'exhausted explicit schedule is immediate' => [[
            'backoffSeconds' => [0.000001],
        ], [0.000001, 0.0, 0.0]];
        yield 'empty explicit schedule overrides initial interval' => [[
            'backoff_seconds' => [],
            'initial_interval_seconds' => 0.000001,
        ], [0.0, 0.0, 0.0]];
        yield 'default exponential coefficient doubles the interval' => [[
            'initial_interval_seconds' => ' 1e-6 ',
        ], [0.000001, 0.000002, 0.000004]];
        yield 'exponential delay respects maximum' => [[
            'initial_interval_seconds' => 0.000001,
            'backoff_coefficient' => 3,
            'maximum_interval_seconds' => 0.000005,
        ], [0.000001, 0.000003, 0.000005]];

        $initialAliases = ['initial_interval_seconds', 'initialIntervalSeconds', 'initial_interval', 'initialInterval'];
        $maximumAliases = [
            'maximum_interval_seconds',
            'max_interval_seconds',
            'maximumIntervalSeconds',
            'maxIntervalSeconds',
        ];
        foreach ($initialAliases as $index => $initialAlias) {
            yield 'exponential alias ' . $initialAlias => [[
                $initialAlias => ' 0.000001 ',
                $index % 2 === 0 ? 'backoff_coefficient' : 'backoffCoefficient' => ' 2 ',
                $maximumAliases[$index] => ' 0.000003 ',
            ], [0.000001, 0.000002, 0.000003]];
        }

        yield 'invalid numeric aliases permit later valid values' => [[
            'initial_interval_seconds' => false,
            'initialIntervalSeconds' => 0.000001,
            'backoff_coefficient' => 'invalid',
            'backoffCoefficient' => 3,
            'maximum_interval_seconds' => [],
            'maximumIntervalSeconds' => 0.000005,
        ], [0.000001, 0.000003, 0.000005]];
        yield 'first numeric aliases win' => [[
            'initial_interval_seconds' => 0.000001,
            'initialIntervalSeconds' => 0.000009,
            'backoff_coefficient' => 2,
            'backoffCoefficient' => 3,
            'maximum_interval_seconds' => 0.000003,
            'maximumIntervalSeconds' => 0.000009,
        ], [0.000001, 0.000002, 0.000003]];
        yield 'zero coefficient makes later retries immediate' => [[
            'initial_interval_seconds' => 0.000001,
            'backoff_coefficient' => 0,
        ], [0.000001, 0.0, 0.0]];
        yield 'zero maximum makes every retry immediate' => [[
            'initial_interval_seconds' => 0.000001,
            'maximum_interval_seconds' => 0,
        ], [0.0, 0.0, 0.0]];
        yield 'negative maximum does not impose a limit' => [[
            'initial_interval_seconds' => 0.000001,
            'maximum_interval_seconds' => -1,
        ], [0.000001, 0.000002, 0.000004]];
        yield 'negative initial interval is clamped' => [[
            'initial_interval_seconds' => -1,
        ], [0.0, 0.0, 0.0]];
    }

    /**
     * @param array<string, mixed> $policy
     * @param list<float> $delays
     */
    private function executeRetryScenario(array $policy, array $delays, bool $completed): void
    {
        $operation = $this->catalog($policy);
        $catalogBefore = $operation->fresh()
            ->getRawOriginal();
        $boundary = new RetryPolicyBoundaryObserver();
        /** @var WorkflowControlPlane&MockInterface $workflows */
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $attemptCount = count($delays) + 1;
        $dispatches = 0;
        $arguments = [
            'amount' => 0,
            'approved' => false,
            'labels' => ['', 'invoice'],
        ];
        $workflows->shouldReceive('query')
            ->times($attemptCount)
            ->andReturnUsing(
                function (string $instanceId, string $name, array $options) use (
                    &$dispatches,
                    $attemptCount,
                    $completed,
                    $arguments,
                    $boundary,
                ): array {
                    ++$dispatches;
                    $call = WorkflowServiceCall::query()->sole();
                    $this->assertSame('invoice-owner', $instanceId);
                    $this->assertSame('approval', $name);
                    $this->assertSameJsonObject($arguments, $options['arguments']);
                    $this->assertSame('billing', $options['namespace']);
                    $this->assertSame($call->id, $options['service_call_id']);
                    $this->assertSame($dispatches, $options['service_call_attempt']);
                    $this->assertSame($attemptCount, $options['service_call_max_attempts']);
                    $this->assertSame(
                        $dispatches === 1 ? ServiceCallStatus::Accepted->value : ServiceCallStatus::Started->value,
                        $call->status
                    );
                    $this->assertSame(ServiceCallOutcome::Accepted, $call->outcome);
                    $this->assertNull($call->failed_at);
                    $this->assertNull($call->failure_message);
                    $this->assertCount($dispatches - 1, $call->metadata['service_call_attempts'] ?? []);
                    $this->assertSame([], $boundary->releasedStatuses);

                    return $completed && $dispatches === $attemptCount ? [
                        'success' => true,
                        'workflow_instance_id' => 'invoice-owner',
                        'run_id' => 'approved-run',
                        'result' => [
                            'private' => 'approval material',
                        ],
                    ] : [
                        'success' => false,
                        'reason' => 'approval_temporarily_unavailable',
                        'message' => 'Transient approval failure.',
                        'error_type' => 'TransientApprovalFailure',
                        'payload_blob' => 'private failure payload',
                    ];
                }
            );
        $control = new DefaultServiceControlPlane($workflows, $boundary);
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'finance',
            'idempotency_key' => 'invoice-retry-42',
            'arguments' => $arguments,
        ];
        $result = $control->execute('billing', 'invoices', 'approve', $options);
        $call = WorkflowServiceCall::query()->sole();
        $status = $completed ? ServiceCallStatus::Completed : ServiceCallStatus::Failed;
        $outcome = $completed ? ServiceCallOutcome::Completed : ServiceCallOutcome::HandlerFailed;
        $this->assertSame($completed, $result['accepted']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame($status->value, $result['status']);
        $this->assertSame($status->value, $call->status);
        $this->assertSame($outcome->value, $result['outcome']);
        $this->assertSame($outcome, $call->outcome);
        $this->assertSame($call->id, $result['service_call_id']);
        $this->assertSame($attemptCount, $dispatches);
        $this->assertSame($attemptCount, $result['retry_attempt_count']);
        $this->assertSame($attemptCount, $call->metadata['retry_attempt_count']);
        $this->assertCount($attemptCount, $result['service_call_attempts']);
        $this->assertSameJsonObject($call->metadata['service_call_attempts'], $result['service_call_attempts']);
        $this->assertSameJsonObject($policy, $call->retry_policy);
        $this->assertSame(1, $boundary->evaluations);
        $this->assertSame([$status->value], $boundary->releasedStatuses);
        $this->assertNotNull($call->accepted_at);
        $this->assertSame($completed, $call->completed_at !== null);
        $this->assertSame(! $completed, $call->failed_at !== null);
        $this->assertNull($call->cancelled_at);

        foreach ($result['service_call_attempts'] as $index => $entry) {
            $retry = $index < $attemptCount - 1;
            $this->assertSame($index + 1, $entry['attempt']);
            $this->assertSame($retry, $entry['retry_scheduled']);
            $this->assertSame($retry ? ServiceCallStatus::Started->value : $status->value, $entry['status']);
            $this->assertSame($retry ? ServiceCallOutcome::Accepted->value : $outcome->value, $entry['outcome']);
            $this->assertSame('2026-10-08T04:30:00+00:00', $entry['started_at']);
            if ($retry) {
                $this->assertSame($delays[$index], (float) $entry['scheduled_backoff_seconds']);
                $this->assertSame('TransientApprovalFailure', $entry['failure_type']);
                $this->assertSame('Transient approval failure.', $entry['failure_message']);
                $this->assertArrayNotHasKey('failed_at', $entry);
                $this->assertArrayNotHasKey('completed_at', $entry);
            } else {
                $this->assertArrayNotHasKey('scheduled_backoff_seconds', $entry);
                $this->assertArrayNotHasKey($completed ? 'failed_at' : 'completed_at', $entry);
                $this->assertSame('2026-10-08T04:30:00+00:00', $entry[$completed ? 'completed_at' : 'failed_at']);
            }
        }
        if ($completed) {
            $this->assertSame('approved-run', $call->resolved_target_reference);
            $this->assertNull($call->failure_message);
            $this->assertArrayNotHasKey('result', $call->metadata['control_plane']);
        } else {
            $this->assertSame('approval_temporarily_unavailable', $result['reason']);
            $this->assertSame('TransientApprovalFailure', $result['error_type']);
            $this->assertSame('Transient approval failure.', $call->failure_message);
            $this->assertSame($attemptCount, $call->outcome_metadata['retry_attempt_count']);
            $this->assertSameJsonObject(
                $result['service_call_attempts'],
                $call->outcome_metadata['service_call_attempts']
            );
            $this->assertArrayNotHasKey('payload_blob', $call->outcome_metadata['control_plane']);
        }

        $before = $call->fresh()
            ->getRawOriginal();
        $connection = $call->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        foreach ([
            $options, $options + [
                'service_call_id' => $call->id,
            ]] as $index => $replayOptions) {
            $replay = $control->execute('billing', 'invoices', 'approve', $replayOptions);
            $this->assertSame($completed, $replay['accepted']);
            $this->assertSame($index === 0, $replay['idempotent_replay']);
            $this->assertSame($status->value, $replay['status']);
            $this->assertSameJsonObject($result['service_call_attempts'], $replay['service_call_attempts']);
            $this->assertSame($before, $call->fresh()->getRawOriginal());
            $this->assertSame($attemptCount, $dispatches);
            $this->assertSame(1, $boundary->evaluations);
            $this->assertSame([$status->value], $boundary->releasedStatuses);
        }
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertSame($catalogBefore, $operation->fresh()->getRawOriginal());
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $writes = array_filter($queries, static fn (array $query): bool => preg_match(
            '/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i',
            $query['query']
        ) === 1);
        $this->assertSame([], array_values($writes));
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function catalog(array $policy): WorkflowServiceOperation
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
            'handler_binding_kind' => ServiceCallBindingKind::WorkflowQuery->value,
            'handler_binding' => [
                'workflow_instance_id' => 'invoice-owner',
                'query_name' => 'approval',
            ],
            'retry_policy' => $policy,
            'boundary_policy' => [
                'concurrency' => [
                    'max_in_flight' => 1,
                ],
            ],
        ]);
    }
}

final class RetryPolicyBoundaryObserver implements ServiceBoundaryPolicy
{
    public int $evaluations = 0;

    /**
     * @var list<string>
     */
    public array $releasedStatuses = [];

    private readonly DefaultServiceBoundaryPolicy $policy;

    public function __construct()
    {
        $this->policy = new DefaultServiceBoundaryPolicy();
    }

    public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
    {
        ++$this->evaluations;

        return $this->policy->evaluate($request);
    }

    public function release(ServiceBoundaryRequest $request): void
    {
        $this->releasedStatuses[] = WorkflowServiceCall::query()->sole()->status;
        $this->policy->release($request);
    }
}
