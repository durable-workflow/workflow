<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class WorkflowCommandNormalizerTest extends TestCase
{
    public function testCompleteWorkflowAcceptsRawStringResult(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => '"ok"',
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => '"ok"',
        ]], $out);
    }

    public function testCompleteWorkflowUnwrapsEnvelope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', 'ok'),
                ],
            ],
        ]);

        $blob = Serializer::serializeWithCodec('avro', 'ok');

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => $blob,
        ]], $out);
    }

    public function testFailWorkflowRequiresNonEmptyMessage(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_workflow',
                'message' => '   ',
            ],
        ]);
    }

    public function testFailWorkflowPreservesOptionalFields(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'exception_class' => 'App\\Exceptions\\Boom',
                'non_retryable' => true,
            ],
        ]);

        $this->assertSame([[
            'type' => 'fail_workflow',
            'message' => 'boom',
            'exception_class' => 'App\\Exceptions\\Boom',
            'non_retryable' => true,
        ]], $out);
    }

    public function testScheduleActivityRequiresActivityType(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => '',
            ],
        ]);
    }

    public function testScheduleActivityTrimsFieldsAndResolvesArgumentsEnvelope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => '  SendEmail ',
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', ['hi']),
                ],
                'connection' => ' redis ',
                'queue' => 'default',
            ],
        ]);

        $arguments = Serializer::serializeWithCodec('avro', ['hi']);

        $this->assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'SendEmail',
            'arguments' => $arguments,
            'connection' => 'redis',
            'queue' => 'default',
        ]], $out);
    }

    public function testScheduleActivityPreservesRetryPolicyAndTimeouts(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'retry_policy' => [
                    'max_attempts' => 4,
                    'backoff_seconds' => [1, 5, 30],
                    'non_retryable_error_types' => ['ValidationError', 'PaymentDeclined'],
                ],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => 10,
                'schedule_to_close_timeout' => 300,
                'heartbeat_timeout' => 15,
            ],
        ]);

        $this->assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'SendEmail',
            'retry_policy' => [
                'max_attempts' => 4,
                'backoff_seconds' => [1, 5, 30],
                'non_retryable_error_types' => ['ValidationError', 'PaymentDeclined'],
            ],
            'start_to_close_timeout' => 120,
            'schedule_to_start_timeout' => 10,
            'schedule_to_close_timeout' => 300,
            'heartbeat_timeout' => 15,
        ]], $out);
    }

    public function testScheduleActivityRejectsInvalidRetryPolicy(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'retry_policy' => [
                    'max_attempts' => 0,
                    'backoff_seconds' => [1, -1],
                ],
            ],
        ]);
    }

    public function testStartTimerRequiresNonNegativeDelay(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_timer',
                'delay_seconds' => -1,
            ],
        ]);
    }

    public function testStartTimerPassesThrough(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_timer',
                'delay_seconds' => 30,
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_timer',
            'delay_seconds' => 30,
        ]], $out);
    }

    public function testStartChildWorkflowValidatesParentClosePolicy(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'parent_close_policy' => 'orphan',
            ],
        ]);
    }

    public function testStartChildWorkflowAcceptsKnownPolicy(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'parent_close_policy' => 'abandon',
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_child_workflow',
            'workflow_type' => 'Child',
            'parent_close_policy' => 'abandon',
        ]], $out);
    }

    public function testStartChildWorkflowPreservesRetryPolicyAndTimeouts(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'retry_policy' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [2, 8],
                    'non_retryable_error_types' => ['ValidationError'],
                ],
                'execution_timeout_seconds' => 600,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_child_workflow',
            'workflow_type' => 'Child',
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [2, 8],
                'non_retryable_error_types' => ['ValidationError'],
            ],
            'execution_timeout_seconds' => 600,
            'run_timeout_seconds' => 120,
        ]], $out);
    }

    public function testStartChildWorkflowRejectsInvalidRetryPolicy(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'retry_policy' => [
                    'max_attempts' => 0,
                    'backoff_seconds' => [1, -1],
                ],
            ],
        ]);
    }

    public function testContinueAsNewPassesThroughOptionalWorkflowType(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'continue_as_new',
                'workflow_type' => 'NextWorkflow',
            ],
        ]);

        $this->assertSame([[
            'type' => 'continue_as_new',
            'workflow_type' => 'NextWorkflow',
        ]], $out);
    }

    public function testCompleteUpdateUnwrapsEnvelope(): void
    {
        $blob = Serializer::serializeWithCodec('avro', [
            'approved' => true,
        ]);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_update',
                'update_id' => '01UPDATE000000000000000001',
                'result' => [
                    'codec' => 'avro',
                    'blob' => $blob,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_update',
            'update_id' => '01UPDATE000000000000000001',
            'result' => $blob,
        ]], $out);
    }

    public function testCompleteUpdateRequiresUpdateId(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_update',
                'result' => '"ok"',
            ],
        ]);
    }

    public function testFailUpdatePreservesOptionalFields(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_update',
                'update_id' => '01UPDATE000000000000000002',
                'message' => 'boom',
                'exception_class' => 'App\\Exceptions\\UpdateBoom',
                'exception_type' => 'update_boom',
                'non_retryable' => true,
            ],
        ]);

        $this->assertSame([[
            'type' => 'fail_update',
            'update_id' => '01UPDATE000000000000000002',
            'message' => 'boom',
            'exception_class' => 'App\\Exceptions\\UpdateBoom',
            'exception_type' => 'update_boom',
            'non_retryable' => true,
        ]], $out);
    }

    public function testFailUpdateRequiresMessage(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_update',
                'update_id' => '01UPDATE000000000000000003',
                'message' => '',
            ],
        ]);
    }

    public function testRecordSideEffectRequiresStringResult(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_side_effect',
                'result' => 42,
            ],
        ]);
    }

    public function testRecordVersionMarkerRequiresAllIntFields(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_version_marker',
                'change_id' => 'c1',
                'version' => 1,
                'min_supported' => 1,
                // missing max_supported
            ],
        ]);
    }

    public function testRecordVersionMarkerCoercesIntegers(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_version_marker',
                'change_id' => ' feature-x ',
                'version' => 2,
                'min_supported' => 1,
                'max_supported' => 3,
            ],
        ]);

        $this->assertSame([[
            'type' => 'record_version_marker',
            'change_id' => 'feature-x',
            'version' => 2,
            'min_supported' => 1,
            'max_supported' => 3,
        ]], $out);
    }

    public function testUpsertSearchAttributesRequiresNonEmpty(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [],
            ],
        ]);
    }

    public function testOpenConditionWaitWithoutOptionalFieldsNormalizes(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_condition_wait',
        ]], $out);
    }

    public function testOpenConditionWaitTrimsKeyAndPreservesTimeout(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'condition_key' => '  order-ready ',
                'condition_definition_fingerprint' => ' fp-1 ',
                'timeout_seconds' => 30,
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_condition_wait',
            'condition_key' => 'order-ready',
            'condition_definition_fingerprint' => 'fp-1',
            'timeout_seconds' => 30,
        ]], $out);
    }

    public function testOpenConditionWaitRejectsNegativeTimeout(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'timeout_seconds' => -1,
            ],
        ]);
    }

    public function testOpenConditionWaitRejectsNonIntegerTimeout(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'timeout_seconds' => '30',
            ],
        ]);
    }

    public function testOpenConditionWaitAllowsZeroTimeout(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'k',
                'timeout_seconds' => 0,
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_condition_wait',
            'condition_key' => 'k',
            'timeout_seconds' => 0,
        ]], $out);
    }

    public function testUnknownCommandTypeRejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'do_a_barrel_roll',
            ],
        ]);
    }

    public function testMissingTypeFieldRejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'payload' => 'nope',
            ],
        ]);
    }

    public function testRetryPolicyRejectedOnCompleteWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'complete_workflow',
                'result' => '"ok"',
                'retry_policy' => [
                    'max_attempts' => 3,
                ],
            ],
        ]);

        $this->assertArrayHasKey('commands.0.retry_policy', $errors);
        $this->assertStringContainsString(
            'not valid on a complete_workflow command',
            $errors['commands.0.retry_policy'][0],
        );
        $this->assertStringContainsString('schedule_activity', $errors['commands.0.retry_policy'][0]);
        $this->assertStringContainsString('start_child_workflow', $errors['commands.0.retry_policy'][0]);
    }

    public function testRetryPolicyRejectedOnFailWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'retry_policy' => [
                    'max_attempts' => 3,
                ],
            ],
        ]);

        $this->assertArrayHasKey('commands.0.retry_policy', $errors);
        $this->assertStringContainsString(
            'Workflow failure itself is non-retryable',
            $errors['commands.0.retry_policy'][0],
        );
    }

    public function testStartToCloseTimeoutRejectedOnChildWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'start_to_close_timeout' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.start_to_close_timeout', $errors);
        $this->assertStringContainsString(
            'only applies to a schedule_activity command',
            $errors['commands.0.start_to_close_timeout'][0],
        );
    }

    public function testHeartbeatTimeoutRejectedOnFailWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'heartbeat_timeout' => 30,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.heartbeat_timeout', $errors);
    }

    public function testExecutionTimeoutSecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'execution_timeout_seconds' => 600,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.execution_timeout_seconds', $errors);
        $this->assertStringContainsString(
            'only applies to a start_child_workflow command',
            $errors['commands.0.execution_timeout_seconds'][0],
        );
    }

    public function testRunTimeoutSecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'run_timeout_seconds' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.run_timeout_seconds', $errors);
    }

    public function testNonRetryableRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'non_retryable' => true,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.non_retryable', $errors);
        $this->assertStringContainsString(
            'retry_policy.non_retryable_error_types',
            $errors['commands.0.non_retryable'][0],
        );
    }

    public function testParentClosePolicyRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'parent_close_policy' => 'abandon',
            ],
        ]);

        $this->assertArrayHasKey('commands.0.parent_close_policy', $errors);
    }

    public function testTimeoutSecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'timeout_seconds' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.timeout_seconds', $errors);
        $this->assertStringContainsString(
            'For activities use start_to_close_timeout',
            $errors['commands.0.timeout_seconds'][0],
        );
    }

    public function testDelaySecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'delay_seconds' => 30,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.delay_seconds', $errors);
    }

    public function testNullScopeFieldsAreIgnored(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => '"ok"',
                'retry_policy' => null,
                'start_to_close_timeout' => null,
                'non_retryable' => null,
                'parent_close_policy' => null,
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => '"ok"',
        ]], $out);
    }

    public function testActivityScheduleToCloseSmallerThanStartToCloseRejected(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 120,
                'schedule_to_close_timeout' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.schedule_to_close_timeout', $errors);
        $this->assertStringContainsString(
            'must be greater than or equal to start_to_close_timeout',
            $errors['commands.0.schedule_to_close_timeout'][0],
        );
    }

    public function testActivityScheduleToCloseEqualToStartToCloseAccepted(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 120,
                'schedule_to_close_timeout' => 120,
            ],
        ]);

        $this->assertSame(120, $out[0]['start_to_close_timeout']);
        $this->assertSame(120, $out[0]['schedule_to_close_timeout']);
    }

    public function testActivityHeartbeatLargerThanStartToCloseRejected(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 60,
                'heartbeat_timeout' => 120,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.heartbeat_timeout', $errors);
        $this->assertStringContainsString(
            'must be less than or equal to start_to_close_timeout',
            $errors['commands.0.heartbeat_timeout'][0],
        );
    }

    public function testActivityHeartbeatEqualToStartToCloseAccepted(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 60,
                'heartbeat_timeout' => 60,
            ],
        ]);

        $this->assertSame(60, $out[0]['heartbeat_timeout']);
    }

    public function testActivityTimeoutOrderingNotEnforcedWhenFieldsMissing(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'heartbeat_timeout' => 600,
            ],
        ]);

        $this->assertSame(600, $out[0]['heartbeat_timeout']);
    }

    public function testChildWorkflowExecutionTimeoutSmallerThanRunTimeoutRejected(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'execution_timeout_seconds' => 60,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.execution_timeout_seconds', $errors);
        $this->assertStringContainsString(
            'must be greater than or equal to run_timeout_seconds',
            $errors['commands.0.execution_timeout_seconds'][0],
        );
    }

    public function testChildWorkflowExecutionTimeoutEqualToRunTimeoutAccepted(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'execution_timeout_seconds' => 60,
                'run_timeout_seconds' => 60,
            ],
        ]);

        $this->assertSame(60, $out[0]['execution_timeout_seconds']);
        $this->assertSame(60, $out[0]['run_timeout_seconds']);
    }

    public function testFailWorkflowAcceptsNonRetryableScope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'non_retryable' => false,
            ],
        ]);

        $this->assertSame(false, $out[0]['non_retryable']);
    }

    public function testFailUpdateAcceptsNonRetryableScope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_update',
                'update_id' => 'upd-1',
                'message' => 'boom',
                'non_retryable' => true,
            ],
        ]);

        $this->assertSame(true, $out[0]['non_retryable']);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return array<string, list<string>>
     */
    private function normalizeAndCaptureErrors(array $commands): array
    {
        try {
            WorkflowCommandNormalizer::normalize($commands);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected ValidationException was not thrown.');
    }
}
