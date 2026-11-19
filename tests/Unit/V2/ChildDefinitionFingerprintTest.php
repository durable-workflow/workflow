<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\VersionResolver;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowDefinitionFingerprint;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ChildDefinitionFingerprintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'workflows.v2.namespaces' => ['client-a'],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.types.workflows' => [
                'mapped-child' => FingerprintInitialChild::class,
            ],
        ]);
    }

    public static function childTypes(): array
    {
        return [[FingerprintInitialChild::class, true], ['mapped-child', true], ['portable.unknown-child', false]];
    }

    #[DataProvider('childTypes')]
    public function testBridgeOnlyFingerprintsResolvedEmbeddedChildren(string $type, bool $embedded): void
    {
        $child = $this->admitChild($type);
        $recorded = WorkflowDefinitionFingerprint::recordedForRun($child);
        $this->assertSame(
            $embedded ? WorkflowDefinition::fingerprint(FingerprintInitialChild::class) : null,
            $recorded
        );
        $resolution = VersionResolver::resolve(
            $child,
            null,
            new VersionCall('child-version', WorkflowStub::DEFAULT_VERSION, 1),
            1
        );
        $this->assertSame($embedded ? 1 : WorkflowStub::DEFAULT_VERSION, $resolution->version);
        $this->assertSame($embedded, $resolution->shouldRecordMarker);
    }

    public static function sourceDefinitions(): array
    {
        return [['unchanged'], ['registration-drift'], ['legacy-absence']];
    }

    #[DataProvider('sourceDefinitions')]
    public function testRetryKeepsRecordedDefinitionWithoutAcquiringCurrentCode(string $source): void
    {
        $child = $this->admitChild('mapped-child');
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $child->id)
            ->where('event_type', HistoryEventType::WorkflowStarted)->sole();
        $payload = $started->payload;
        if ($source === 'legacy-absence') {
            unset($payload['workflow_definition_fingerprint']);
            $started->forceFill([
                'payload' => $payload,
            ])->save();
        }
        if ($source === 'registration-drift') {
            config()->set('workflows.v2.types.workflows.mapped-child', FingerprintReplacementChild::class);
            $this->assertNotSame(
                $payload['workflow_definition_fingerprint'],
                WorkflowDefinition::fingerprint(FingerprintReplacementChild::class)
            );
        }
        $task = WorkflowTask::query()->where('workflow_run_id', $child->id)->sole();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'child-worker')['claimed']);
        $result = $bridge->complete($task->id, [[
            'type' => 'fail_workflow',
            'message' => 'retry this child',
        ]]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $retry = WorkflowRun::query()->where('workflow_instance_id', $child->workflow_instance_id)
            ->where('run_number', 2)
            ->sole();
        $this->assertSame($payload, $started->refresh()->payload);
        $this->assertSame(
            $payload['workflow_definition_fingerprint'] ?? null,
            WorkflowDefinitionFingerprint::recordedForRun($retry)
        );
        $resolution = VersionResolver::resolve(
            $retry,
            null,
            new VersionCall('child-version', WorkflowStub::DEFAULT_VERSION, 1),
            1
        );
        $this->assertSame($source === 'unchanged' ? 1 : WorkflowStub::DEFAULT_VERSION, $resolution->version);
        $this->assertSame($source === 'unchanged', $resolution->shouldRecordMarker);
    }

    private function admitChild(string $type): WorkflowRun
    {
        $parent = app(WorkflowControlPlane::class)->start(FingerprintParent::class, 'fingerprint-parent', [
            'namespace' => 'client-a',
            'arguments' => Serializer::serializeWithCodec('avro', [$type]),
            'payload_codec' => 'avro',
        ]);
        $this->assertTrue($parent['started']);
        $task = WorkflowTask::query()->where('workflow_run_id', $parent['workflow_run_id'])->sole();
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $claimed = $bridge->claimStatus($task->id, 'parent-worker');
        $this->assertTrue($claimed['claimed'], json_encode($claimed, JSON_THROW_ON_ERROR));
        $result = $bridge->complete($task->id, [[
            'type' => 'start_child_workflow',
            'workflow_type' => $type,
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
            ],
        ]]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parent['workflow_run_id'])->sole();
        return WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
    }
}

final class FingerprintParent extends Workflow
{
    public function handle(string $type): mixed
    {
        return self::child($type);
    }
}

final class FingerprintInitialChild extends Workflow
{
    public function handle(): string
    {
        return 'initial';
    }
}

final class FingerprintReplacementChild extends Workflow
{
    public function handle(): string
    {
        return 'replacement';
    }
}
