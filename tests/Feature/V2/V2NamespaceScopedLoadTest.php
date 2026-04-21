<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\SelectedRunLocator;
use Workflow\V2\WorkflowStub;

final class V2NamespaceScopedLoadTest extends TestCase
{
    public function testLoadWithMatchingNamespaceReturnsStub(): void
    {
        $this->createRun('ns-load-match', '01JNSLOADMATCH000000001', 'running', 'billing');

        $stub = WorkflowStub::load('ns-load-match', 'billing');

        $this->assertSame('ns-load-match', $stub->id());
    }

    public function testLoadWithWrongNamespaceThrowsNotFound(): void
    {
        $this->createRun('ns-load-wrong', '01JNSLOADWRONG000000001', 'running', 'billing');

        $this->expectException(ModelNotFoundException::class);

        WorkflowStub::load('ns-load-wrong', 'shipping');
    }

    public function testLoadWithNullNamespaceSkipsFiltering(): void
    {
        $this->createRun('ns-load-null', '01JNSLOADNULL0000000001', 'running', 'billing');

        $stub = WorkflowStub::load('ns-load-null', null);

        $this->assertSame('ns-load-null', $stub->id());
    }

    public function testLoadWithNullNamespaceLoadsInstanceWithNullNamespace(): void
    {
        $this->createRun('ns-load-no-ns', '01JNSLOADNONS000000001', 'running', null);

        $stub = WorkflowStub::load('ns-load-no-ns');

        $this->assertSame('ns-load-no-ns', $stub->id());
    }

    public function testLoadRunWithMatchingNamespaceReturnsStub(): void
    {
        $run = $this->createRun('ns-loadrun-match', '01JNSLOADRUNMATCH00001', 'running', 'billing');

        $stub = WorkflowStub::loadRun($run->id, 'billing');

        $this->assertSame('ns-loadrun-match', $stub->id());
        $this->assertSame($run->id, $stub->runId());
    }

    public function testLoadRunWithWrongNamespaceThrowsNotFound(): void
    {
        $run = $this->createRun('ns-loadrun-wrong', '01JNSLOADRUNWRONG00001', 'running', 'billing');

        $this->expectException(ModelNotFoundException::class);

        WorkflowStub::loadRun($run->id, 'shipping');
    }

    public function testLoadRunWithNullNamespaceSkipsFiltering(): void
    {
        $run = $this->createRun('ns-loadrun-null', '01JNSLOADRUNNULL000001', 'running', 'billing');

        $stub = WorkflowStub::loadRun($run->id, null);

        $this->assertSame($run->id, $stub->runId());
    }

    public function testLoadSelectionWithMatchingNamespaceReturnsStub(): void
    {
        $run = $this->createRun('ns-sel-match', '01JNSSELMATCH000000001', 'running', 'billing');

        $stub = WorkflowStub::loadSelection('ns-sel-match', $run->id, 'billing');

        $this->assertSame('ns-sel-match', $stub->id());
        $this->assertSame($run->id, $stub->runId());
    }

    public function testLoadSelectionWithWrongNamespaceThrowsNotFound(): void
    {
        $this->createRun('ns-sel-wrong', '01JNSSELWRONG000000001', 'running', 'billing');

        $this->expectException(ModelNotFoundException::class);

        WorkflowStub::loadSelection('ns-sel-wrong', null, 'shipping');
    }

    public function testLoadSelectionInstanceOnlyWithMatchingNamespace(): void
    {
        $this->createRun('ns-sel-inst', '01JNSSELINST0000000001', 'running', 'billing');

        $stub = WorkflowStub::loadSelection('ns-sel-inst', null, 'billing');

        $this->assertSame('ns-sel-inst', $stub->id());
    }

    public function testSelectedRunLocatorScopesRunIdLookupByNamespace(): void
    {
        $run = $this->createRun('ns-locator-run', '01JNSLOCATORRUN0000001', 'running', 'billing');

        $this->assertSame($run->id, SelectedRunLocator::forIdOrFail($run->id, [], 'billing')->id);

        $this->expectException(ModelNotFoundException::class);

        SelectedRunLocator::forIdOrFail($run->id, [], 'shipping');
    }

    public function testSelectedRunLocatorScopesInstanceIdLookupByNamespace(): void
    {
        $run = $this->createRun('ns-locator-instance', '01JNSLOCATORINST000001', 'running', 'billing');

        $this->assertSame($run->id, SelectedRunLocator::forIdOrFail('ns-locator-instance', [], 'billing')->id);

        $this->expectException(ModelNotFoundException::class);

        SelectedRunLocator::forIdOrFail('ns-locator-instance', [], 'shipping');
    }

    public function testSelectedRunLocatorScopesPinnedInstanceSelectionByNamespace(): void
    {
        $run = $this->createRun('ns-locator-selection', '01JNSLOCATORSEL0000001', 'running', 'billing');

        $this->assertSame(
            $run->id,
            SelectedRunLocator::forInstanceIdOrFail('ns-locator-selection', $run->id, [], 'billing')->id,
        );

        $this->expectException(ModelNotFoundException::class);

        SelectedRunLocator::forInstanceIdOrFail('ns-locator-selection', $run->id, [], 'shipping');
    }

    private function createRun(string $instanceId, string $runId, string $status, ?string $namespace): WorkflowRun
    {
        $isClosed = in_array($status, ['completed', 'failed', 'cancelled', 'terminated'], true);

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
            'namespace' => $namespace,
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => $status,
            'namespace' => $namespace,
            'arguments' => Serializer::serialize([]),
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => $isClosed ? now()
                ->subMinute() : null,
            'last_progress_at' => $isClosed ? now()
                ->subMinute() : now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
