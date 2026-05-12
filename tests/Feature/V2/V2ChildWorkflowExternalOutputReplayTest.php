<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestChildGreetingWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedChildWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ChildRunHistory;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\QueryStateReplayer;

final class V2ChildWorkflowExternalOutputReplayTest extends TestCase
{
    private ?string $storageRoot = null;

    protected function tearDown(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();

        if ($this->storageRoot !== null) {
            $this->removeDirectory($this->storageRoot);
            $this->storageRoot = null;
        }

        parent::tearDown();
    }

    public function testParentReplayResolvesExternalizedChildWorkflowOutput(): void
    {
        $codec = CodecRegistry::defaultCodec();
        $namespace = 'external-child-output';
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);
        $childOutput = [
            'greeting' => str_repeat('hello Taylor ', 64),
            'workflow_id' => 'child-external-output',
            'run_id' => 'child-external-output-run',
        ];
        $storedOutput = ExternalPayloads::externalize(
            Serializer::serializeWithCodec($codec, $childOutput),
            $codec,
            $driver,
            1,
        );
        $historyOutput = ExternalPayloads::historyValue($storedOutput, $codec, $namespace);

        $this->assertIsArray($historyOutput);
        $this->assertSame($codec, $historyOutput['codec']);
        $this->assertArrayHasKey('external_storage', $historyOutput);
        $this->assertSame(ExternalPayloadReference::SCHEMA, $historyOutput['external_storage']['schema']);

        $parentRun = $this->createRun(
            TestHistoryReplayedChildWorkflow::class,
            'test-history-child-replay-workflow',
            $namespace,
            RunStatus::Completed,
            Serializer::serializeWithCodec($codec, ['Taylor']),
            $codec,
        );
        $childRun = $this->createRun(
            TestChildGreetingWorkflow::class,
            'test-child-greeting-workflow',
            $namespace,
            RunStatus::Completed,
            Serializer::serializeWithCodec($codec, ['Taylor']),
            $codec,
            $storedOutput,
        );

        WorkflowHistoryEvent::record($childRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestChildGreetingWorkflow::class,
            'workflow_type' => 'test-child-greeting-workflow',
            'workflow_instance_id' => $childRun->workflow_instance_id,
            'workflow_run_id' => $childRun->id,
        ]);
        WorkflowHistoryEvent::record($childRun, HistoryEventType::WorkflowCompleted, [
            'output' => $historyOutput,
        ]);

        WorkflowHistoryEvent::record($parentRun, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestHistoryReplayedChildWorkflow::class,
            'workflow_type' => 'test-history-child-replay-workflow',
            'workflow_instance_id' => $parentRun->workflow_instance_id,
            'workflow_run_id' => $parentRun->id,
        ]);
        WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildWorkflowScheduled, [
            'sequence' => 1,
            'child_call_id' => 'child-call-external-output',
            'child_workflow_instance_id' => $childRun->workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => TestChildGreetingWorkflow::class,
            'child_workflow_type' => 'test-child-greeting-workflow',
            'child_run_number' => 1,
        ]);
        WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildRunStarted, [
            'sequence' => 1,
            'child_call_id' => 'child-call-external-output',
            'child_workflow_instance_id' => $childRun->workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => TestChildGreetingWorkflow::class,
            'child_workflow_type' => 'test-child-greeting-workflow',
            'child_run_number' => 1,
            'child_status' => RunStatus::Running->value,
        ]);
        $resolutionEvent = WorkflowHistoryEvent::record($parentRun, HistoryEventType::ChildRunCompleted, [
            'sequence' => 1,
            'child_call_id' => 'child-call-external-output',
            'child_workflow_instance_id' => $childRun->workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'child_workflow_class' => TestChildGreetingWorkflow::class,
            'child_workflow_type' => 'test-child-greeting-workflow',
            'child_run_number' => 1,
            'child_status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'closed_at' => now()->toJSON(),
            'output' => $historyOutput,
        ]);

        $state = (new QueryStateReplayer())->query($parentRun->fresh(['historyEvents']), 'currentState');

        $this->assertSame([
            'stage' => 'completed',
            'child' => $childOutput,
        ], $state);
        $this->assertSame($childOutput, ChildRunHistory::outputForResolution($resolutionEvent, null));
        $this->assertSame($childOutput, ChildRunHistory::outputForChildRun($childRun->fresh(['historyEvents'])));
    }

    private function createRun(
        string $workflowClass,
        string $workflowType,
        string $namespace,
        RunStatus $status,
        string $arguments,
        string $codec,
        ?string $output = null,
    ): WorkflowRun {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'namespace' => $namespace,
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'namespace' => $namespace,
            'status' => $status->value,
            'closed_reason' => $status === RunStatus::Completed ? 'completed' : null,
            'payload_codec' => $codec,
            'arguments' => $arguments,
            'output' => $output,
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()->subMinute(),
            'closed_at' => $status === RunStatus::Completed ? now() : null,
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function bindExternalPayloadPolicy(ExternalPayloadStorageDriver $driver): void
    {
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new class($driver) implements ExternalPayloadStoragePolicy {
                public function __construct(
                    private readonly ExternalPayloadStorageDriver $driver,
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
            },
        );
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir().'/dw-child-external-output-'.bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
