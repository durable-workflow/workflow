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
}
