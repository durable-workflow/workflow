<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestSearchAttributeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\SearchAttributeUpsertService;
use Workflow\V2\Support\SearchAttributeValueFilter;
use Workflow\V2\Support\UpsertSearchAttributesCall;
use Workflow\V2\Support\VisibilityFilters;
use Workflow\V2\WorkflowStub;

final class V2SearchAttributeTest extends TestCase
{
    #[DataProvider('datetimeInstants')]
    public function testDatetimeUpsertsPreserveTheInstantThroughStorageAndFiltering(string $value): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');
        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-datetime');
        $workflow->start('Taylor');
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        $this->app->make(SearchAttributeUpsertService::class)->upsert(
            $run,
            new UpsertSearchAttributesCall(['happened_at' => $value]),
            0,
            attributeTypes: ['happened_at' => WorkflowSearchAttribute::TYPE_DATETIME],
        );

        $attribute = WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $run->id)
            ->where('key', 'happened_at')
            ->firstOrFail();
        $this->assertSame('2026-10-08 10:34:56.123456', $attribute->getRawOriginal('value_datetime'));

        $previous = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');
            $this->assertSame('2026-10-08T10:34:56.123456Z', $attribute->fresh()?->getValue()?->toISOString());
        } finally {
            date_default_timezone_set($previous);
        }

        $query = static fn () => WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $run->id)
            ->where('key', 'happened_at');
        $this->assertSame(1, SearchAttributeValueFilter::apply(
            $query(),
            new DateTimeImmutable('2026-10-08T10:34:56.123456Z'),
        )->count());
        $this->assertSame(0, SearchAttributeValueFilter::apply(
            $query(),
            new DateTimeImmutable('2026-10-08T10:34:56.123455Z'),
        )->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function datetimeInstants(): iterable
    {
        yield 'positive offset' => ['2026-10-08T12:34:56.123456+02:00'];
        yield 'negative offset' => ['2026-10-08T06:34:56.123456-04:00'];
        yield 'UTC control' => ['2026-10-08T10:34:56.123456Z'];
    }

    public function testWorkflowCanUpsertSearchAttributes(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-test-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'sa-test-1',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        $searchAttributes = $workflow->searchAttributes();

        $this->assertSame('completed', $searchAttributes['status']);
        $this->assertSame('Taylor', $searchAttributes['customer']);
        $this->assertSame('success', $searchAttributes['result']);
    }

    public function testSearchAttributeUpsertRecordsDurableHistoryEvents(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-test-2');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get();

        $upsertEvents = $events->filter(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::SearchAttributesUpserted
        );

        $this->assertSame(2, $upsertEvents->count());

        $firstUpsert = $upsertEvents->first();
        $this->assertSameJsonObject([
            'customer' => 'Taylor',
            'status' => 'processing',
        ], $firstUpsert->payload['attributes']);

        $secondUpsert = $upsertEvents->last();
        $this->assertSameJsonObject([
            'result' => 'success',
            'status' => 'completed',
        ], $secondUpsert->payload['attributes']);
        $this->assertSameJsonObject([
            'customer' => 'Taylor',
            'result' => 'success',
            'status' => 'completed',
        ], $secondUpsert->payload['merged']);
    }

    public function testSearchAttributesMergeAcrossMultipleUpserts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-test-3');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();

        $this->assertSameJsonObject([
            'customer' => 'Taylor',
            'result' => 'success',
            'status' => 'completed',
        ], $run->typedSearchAttributes());
    }

    public function testSearchAttributesAppearInRunSummary(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-test-4');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $summary = WorkflowRunSummary::query()->where('id', $workflow->runId())->firstOrFail();

        $this->assertSameJsonObject([
            'customer' => 'Taylor',
            'result' => 'success',
            'status' => 'completed',
        ], $summary->getTypedSearchAttributes());
    }

    public function testHistoryEventSequenceIncludesSearchAttributeUpserts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-test-5');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $eventTypes = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::SearchAttributesUpserted->value,
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::SearchAttributesUpserted->value,
            HistoryEventType::WorkflowCompleted->value,
        ], $eventTypes);
    }

    public function testNullValueRemovesSearchAttribute(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestSearchAttributeWorkflow::class, 'sa-test-6');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();

        $runAttrs = $run->typedSearchAttributes();
        $this->assertArrayHasKey('customer', $runAttrs);
        $this->assertArrayHasKey('status', $runAttrs);
        $this->assertArrayHasKey('result', $runAttrs);
    }

    public function testVisibilityFiltersDefinitionIncludesSearchAttributes(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertArrayHasKey('search_attributes', $definition);
        $this->assertSame('Search Attributes', $definition['search_attributes']['label']);
        $this->assertTrue($definition['search_attributes']['filterable']);
        $this->assertTrue($definition['search_attributes']['saved_view_compatible']);
    }

    public function testVisibilityFilterVersionIsUpdated(): void
    {
        $this->assertSame(6, VisibilityFilters::VERSION);
        $this->assertSame([6], VisibilityFilters::supportedVersions());
    }
}
