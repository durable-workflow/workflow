<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;

final class WorkflowRunVisibilityMetadataAccessorsTest extends TestCase
{
    use RefreshDatabase;

    public function testUnsavedRunRetainsInMemoryVisibilityMetadata(): void
    {
        $run = new WorkflowRun([
            'memo' => [
                'customer' => 'Taylor',
            ],
            'search_attributes' => [
                'status' => 'pending',
            ],
        ]);

        $this->assertSame([
            'customer' => 'Taylor',
        ], $run->memo);
        $this->assertSame([
            'status' => 'pending',
        ], $run->search_attributes);
    }

    public function testRunPropertiesPreferTypedVisibilityRows(): void
    {
        $run = $this->createRun([
            'memo' => [
                'legacy' => 'json',
            ],
            'search_attributes' => [
                'legacy' => 'json',
            ],
        ]);

        $this->createMemo($run, 'customer', 'Taylor');
        $this->createSearchAttribute($run, 'status', 'running');

        $run = WorkflowRun::query()->findOrFail($run->id);

        $this->assertSame([
            'customer' => 'Taylor',
        ], $run->memo);
        $this->assertSame([
            'status' => 'running',
        ], $run->search_attributes);
        $this->assertSame($run->memo, $run->typedMemos());
        $this->assertSame($run->search_attributes, $run->typedSearchAttributes());
    }

    public function testSummaryPropertiesPreferTypedVisibilityRows(): void
    {
        $run = $this->createRun();
        $statusBucket = $run->status->statusBucket();

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => $run->run_number,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'projection_schema_version' => 1,
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'memo' => [
                'legacy' => 'json',
            ],
            'search_attributes' => [
                'legacy' => 'json',
            ],
            'status' => $run->status->value,
            'status_bucket' => $statusBucket->value,
        ]);

        $this->createMemo($run, 'customer', 'Taylor');
        $this->createSearchAttribute($run, 'status', 'running');

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame([
            'customer' => 'Taylor',
        ], $summary->memo);
        $this->assertSame([
            'status' => 'running',
        ], $summary->search_attributes);
        $this->assertSame($summary->memo, $summary->getMemos());
        $this->assertSame($summary->search_attributes, $summary->getTypedSearchAttributes());
    }

    private function createRun(array $overrides = []): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'test-' . uniqid(),
            'workflow_type' => 'TestWorkflow',
            'workflow_class' => 'Tests\\TestWorkflow',
        ]);

        return WorkflowRun::query()->create(array_merge([
            'id' => 'run-' . uniqid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\TestWorkflow',
            'workflow_type' => 'TestWorkflow',
            'status' => 'running',
        ], $overrides));
    }

    private function createMemo(WorkflowRun $run, string $key, mixed $value): void
    {
        $memo = new WorkflowMemo([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => $key,
        ]);
        $memo->setValue($value);
        $memo->upserted_at_sequence = 1;
        $memo->inherited_from_parent = false;
        $memo->save();
    }

    private function createSearchAttribute(WorkflowRun $run, string $key, mixed $value): void
    {
        $attribute = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => $key,
        ]);
        $attribute->setTypedValueWithInference($value);
        $attribute->upserted_at_sequence = 1;
        $attribute->inherited_from_parent = false;
        $attribute->save();
    }
}
