<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestSearchAttributeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class V2StartSearchAttributeTest extends TestCase
{
    public function testStartOptionsCanSetSearchAttributes(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-start-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()->withSearchAttributes([
                'env' => 'production',
                'priority' => 'high',
            ]),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $searchAttributes = $workflow->searchAttributes();

        $this->assertSame('production', $searchAttributes['env']);
        $this->assertSame('high', $searchAttributes['priority']);
        $this->assertSame('completed', $searchAttributes['status']);
        $this->assertSame('Taylor', $searchAttributes['customer']);
    }

    public function testStartTimeSearchAttributesAppearOnRunModel(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-start-2');
        $workflow->start('Taylor', StartOptions::rejectDuplicate()->withSearchAttributes([
                'tenant' => 'acme',
            ]),);

        $this->assertTrue($workflow->refresh()->completed());

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();

        $this->assertArrayHasKey('tenant', $run->search_attributes);
        $this->assertSame('acme', $run->search_attributes['tenant']);
    }

    public function testStartTimeSearchAttributesAppearInRunSummary(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-start-3');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()->withSearchAttributes([
                'region' => 'us-east',
            ]),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $summary = WorkflowRunSummary::query()->where('id', $workflow->runId())->firstOrFail();

        $this->assertArrayHasKey('region', $summary->search_attributes);
        $this->assertSame('us-east', $summary->search_attributes['region']);
    }

    public function testStartTimeSearchAttributesRecordedInStartHistory(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-start-4');
        $workflow->start('Taylor', StartOptions::rejectDuplicate()->withSearchAttributes([
                'env' => 'staging',
            ]),);

        $this->assertTrue($workflow->refresh()->completed());

        $startAccepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::StartAccepted)
            ->firstOrFail();

        $this->assertSame([
            'env' => 'staging',
        ], $startAccepted->payload['search_attributes']);

        $workflowStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();

        $this->assertSame([
            'env' => 'staging',
        ], $workflowStarted->payload['search_attributes']);
    }

    public function testStartTimeSearchAttributesMergeWithWorkflowUpserts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-start-5');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()->withSearchAttributes([
                'env' => 'production',
                'initial_attr' => 'set-at-start',
            ]),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $searchAttributes = $workflow->searchAttributes();

        $this->assertSame('production', $searchAttributes['env']);
        $this->assertSame('set-at-start', $searchAttributes['initial_attr']);
        $this->assertSame('Taylor', $searchAttributes['customer']);
        $this->assertSame('completed', $searchAttributes['status']);
        $this->assertSame('success', $searchAttributes['result']);
    }

    public function testStartOptionsBuilderChainsSearchAttributes(): void
    {
        $options = StartOptions::rejectDuplicate()
            ->withBusinessKey('order-123')
            ->withMemo([
                'note' => 'test',
            ])
            ->withSearchAttributes([
                'env' => 'staging',
                'region' => 'eu-west',
            ]);

        $this->assertSame('order-123', $options->businessKey);
        $this->assertSame([
            'note' => 'test',
        ], $options->memo);
        $this->assertSame([
            'env' => 'staging',
            'region' => 'eu-west',
        ], $options->searchAttributes);
    }

    public function testStartOptionsSearchAttributeValidation(): void
    {
        $this->expectException(\LogicException::class);

        StartOptions::rejectDuplicate()->withSearchAttributes([
            'valid' => 'ok',
            '' => 'empty key',
        ]);
    }

    public function testStartOptionsSearchAttributeNullValuesAreDropped(): void
    {
        $options = StartOptions::rejectDuplicate()->withSearchAttributes([
            'keep' => 'yes',
            'drop' => null,
        ]);

        $this->assertSame([
            'keep' => 'yes',
        ], $options->searchAttributes);
    }

    public function testStartWithoutSearchAttributesLeavesFieldNull(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-start-none');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $startAccepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::StartAccepted)
            ->firstOrFail();

        $this->assertNull($startAccepted->payload['search_attributes'] ?? null);
    }
}
