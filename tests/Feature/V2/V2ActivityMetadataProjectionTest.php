<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RunSummaryProjector;

final class V2ActivityMetadataProjectionTest extends TestCase
{
    public function testColdMetadataProjectionDoesNotReadExternalActivityPayloads(): void
    {
        $bytes = Serializer::serializeWithCodec('avro', ['preserved value']);
        $driver = new class($bytes) implements ExternalPayloadStorageDriver {
            public int $reads = 0;

            public function __construct(
                private readonly string $bytes
            ) {
            }

            public function put(string $data, string $sha256, string $codec): string
            {
                return 'memory://activity-payload';
            }

            public function get(string $uri): string
            {
                $this->reads++;

                return $this->bytes;
            }

            public function delete(string $uri): void
            {
            }
        };
        $this->app->instance(ExternalPayloadStoragePolicy::class, new class(
            $driver
        ) implements ExternalPayloadStoragePolicy {
            public function __construct(
                private readonly ExternalPayloadStorageDriver $driver
            ) {
            }

            public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
            {
                return $this->driver;
            }

            public function thresholdBytesFor(?string $namespace): ?int
            {
                return 1;
            }
        });
        $stored = ExternalPayloads::externalize($bytes, 'avro', $driver, 1);
        $instance = WorkflowInstance::query()->create([
            'id' => 'metadata-projection',
            'workflow_class' => 'MetadataWorkflow',
            'workflow_type' => 'metadata.workflow',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'MetadataWorkflow',
            'workflow_type' => 'metadata.workflow',
            'status' => 'completed',
            'namespace' => 'default',
            'payload_codec' => 'avro',
            'started_at' => now(),
            'closed_at' => now(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $activity = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'MetadataActivity',
            'activity_type' => 'metadata.activity',
            'status' => 'completed',
            'attempt_count' => 1,
            'payload_codec' => 'avro',
            'arguments' => $stored,
            'result' => $stored,
            'closed_at' => now(),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => $activity->id,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ]);
        $runId = $run->id;
        unset($run, $activity);
        $run = WorkflowRun::query()->findOrFail($runId);

        $summary = RunSummaryProjector::project($run);
        $this->assertSame(0, $driver->reads, 'Summary projection must not download activity data.');
        $this->assertSame('completed', $summary->status);

        $metadata = RunActivityView::activitiesForRun($run->fresh(), decodePayloads: false)[0];
        $this->assertSame(0, $driver->reads);
        $this->assertSame(RunActivityView::HISTORY_AUTHORITY_TYPED, $metadata['history_authority']);
        $this->assertSame('completed', $metadata['status']);
        $this->assertArrayNotHasKey('arguments', $metadata);
        $this->assertArrayNotHasKey('result', $metadata);

        $decoded = RunActivityView::activitiesForRun($run->fresh())[0];
        $this->assertSame(['preserved value'], $decoded['arguments']);
        $this->assertSame(['preserved value'], $decoded['result']);
        $this->assertGreaterThan(0, $driver->reads);
        unset($decoded['arguments'], $decoded['result']);
        $this->assertSame($metadata, $decoded);
    }
}
