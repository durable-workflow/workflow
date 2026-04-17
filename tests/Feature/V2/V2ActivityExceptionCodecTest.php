<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOutcomeRecorder;

/**
 * TD-089 regression: activity exception rows must be encoded with the
 * run's pinned payload codec, not the package default. Any fallback
 * reader that decodes the blob must use the run codec too, so that
 * Avro-default deployments do not corrupt JSON-pinned runs (and vice
 * versa).
 */
final class V2ActivityExceptionCodecTest extends TestCase
{
    public function testRetryPathStoresExceptionUnderRunCodecWhenDefaultDiffers(): void
    {
        // Package default differs from the run's pinned codec: this is the
        // exact mismatch that the old codec-blind Serializer::serialize()
        // would silently mis-encode.
        config()->set('workflows.serializer', 'avro');

        [$run, $execution, $task, $attempt] = $this->scaffoldLeasedAttempt(
            pinnedCodec: 'json',
            maxAttempts: 2,
            instanceId: 'td089-retry-json',
        );

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('retry boom', 7),
            maxAttempts: 2,
            backoffSeconds: 1,
        );

        $this->assertTrue($outcome['recorded']);

        $execution->refresh();

        // Retry path must have flipped status back to Pending and stashed
        // the exception bytes for the reattempt.
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertIsString($execution->exception);

        $this->assertExceptionBytesDecodeAs(
            bytes: $execution->exception,
            runCodec: 'json',
            otherCodec: 'avro',
            expectedMessage: 'retry boom',
        );
    }

    public function testFinalFailurePathStoresExceptionUnderRunCodecWhenDefaultDiffers(): void
    {
        config()->set('workflows.serializer', 'avro');

        [$run, $execution, $task, $attempt] = $this->scaffoldLeasedAttempt(
            pinnedCodec: 'json',
            maxAttempts: 1,
            instanceId: 'td089-final-json',
        );

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('final boom', 9),
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);

        $execution->refresh();

        $this->assertSame(ActivityStatus::Failed, $execution->status);
        $this->assertIsString($execution->exception);

        $this->assertExceptionBytesDecodeAs(
            bytes: $execution->exception,
            runCodec: 'json',
            otherCodec: 'avro',
            expectedMessage: 'final boom',
        );
    }

    public function testFinalFailurePathStoresExceptionUnderAvroRunCodecWhenDefaultIsJson(): void
    {
        // Mirror case: Avro-pinned run under a JSON package default still
        // has to write Avro bytes, not JSON.
        config()->set('workflows.serializer', 'json');

        [$run, $execution, $task, $attempt] = $this->scaffoldLeasedAttempt(
            pinnedCodec: 'avro',
            maxAttempts: 1,
            instanceId: 'td089-final-avro',
        );

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('avro boom', 5),
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);

        $execution->refresh();

        $this->assertSame(ActivityStatus::Failed, $execution->status);
        $this->assertIsString($execution->exception);

        $decoded = Serializer::unserializeWithCodec('avro', $execution->exception);

        $this->assertIsArray($decoded);
        $this->assertSame(RuntimeException::class, $decoded['class']);
        $this->assertSame('avro boom', $decoded['message']);
    }

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask, 3: ActivityAttempt}
     */
    private function scaffoldLeasedAttempt(string $pinnedCodec, int $maxAttempts, string $instanceId): array
    {
        $now = now();

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $now,
            'started_at' => $now,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serializeWithCodec($pinnedCodec, ['Taylor']),
            'payload_codec' => $pinnedCodec,
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'last_progress_at' => $now,
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        $attemptId = (string) Str::ulid();

        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'status' => ActivityStatus::Running->value,
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'arguments' => Serializer::serializeWithCodec($pinnedCodec, ['Taylor']),
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => $maxAttempts,
                'backoff_seconds' => [1],
                'start_to_close_timeout' => 60,
                'schedule_to_start_timeout' => null,
            ],
        ]);

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $now,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => null,
            'queue' => null,
            'leased_at' => $now,
            'lease_expires_at' => $now->copy()->addMinutes(5),
            'attempt_count' => 1,
        ]);

        $attempt = ActivityAttempt::query()->create([
            'id' => $attemptId,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => ActivityAttemptStatus::Running->value,
            'lease_owner' => $task->id,
            'started_at' => $now,
            'lease_expires_at' => $now->copy()->addMinutes(5),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
            'attempt_number' => 1,
        ], $task);

        return [$run, $execution, $task, $attempt];
    }

    private function assertExceptionBytesDecodeAs(
        string $bytes,
        string $runCodec,
        string $otherCodec,
        string $expectedMessage,
    ): void {
        $decoded = Serializer::unserializeWithCodec($runCodec, $bytes);

        $this->assertIsArray($decoded);
        $this->assertSame(RuntimeException::class, $decoded['class']);
        $this->assertSame($expectedMessage, $decoded['message']);

        // Under the bug, the bytes would have been encoded with the package
        // default ($otherCodec), so decoding as $runCodec would have thrown
        // or produced garbage. Sanity-check that the bytes are NOT in the
        // other codec's shape to catch silent regressions if someone ever
        // re-wires serializeWithCodec to fall back to the package default.
        $otherDecoded = null;
        try {
            $otherDecoded = Serializer::unserializeWithCodec($otherCodec, $bytes);
        } catch (\Throwable $ignored) {
            // Expected: bytes in one codec should not decode as the other.
            return;
        }

        // If the other codec *did* decode, the payload must look different
        // from a faithful Throwable array (proving the bytes are still
        // canonically from the run codec, not the package default).
        $this->assertTrue(
            ! is_array($otherDecoded)
                || ($otherDecoded['class'] ?? null) !== RuntimeException::class
                || ($otherDecoded['message'] ?? null) !== $expectedMessage,
            'Exception bytes were ambiguously decodable as both codecs; '
                . 'encoder is likely ignoring the pinned run codec.',
        );
    }
}
