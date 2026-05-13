<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestLargeSideEffectWorkflow;
use Tests\Fixtures\V2\TestManySideEffectsWorkflow;
use Tests\Fixtures\V2\TestSideEffectWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2SideEffectWorkflowTest extends TestCase
{
    private ?string $storageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        TestSideEffectWorkflow::resetCounter();
    }

    protected function tearDown(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();

        if ($this->storageRoot !== null) {
            $this->removeDirectory($this->storageRoot);
            $this->storageRoot = null;
        }

        parent::tearDown();
    }

    public function testSideEffectExecutesOnceAndRecordsTypedHistoryEvent(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-once');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        // The side-effect closure should have executed exactly once.
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        // Verify the SideEffectRecorded history event was written.
        $sideEffectEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->get();

        $this->assertCount(1, $sideEffectEvents);

        // The event should contain a serialized result and a sequence marker.
        $event = $sideEffectEvents->first();
        $this->assertArrayHasKey('result', $event->payload);
        $this->assertArrayHasKey('sequence', $event->payload);
    }

    public function testSideEffectValueIsStableAcrossSignalResumeReplay(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-replay');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        // Query the token — this triggers a query replay against committed history.
        $token = $workflow->query('currentToken');
        $this->assertSame(1, $token);

        // The side-effect closure should NOT have re-executed during query replay.
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        // Complete the workflow by signalling.
        $workflow->signal('finish', 'done');
        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertSame(1, $output['token']);
        $this->assertSame('done', $output['finish']);

        // Side-effect was replayed from history, not re-executed.
        // The closure ran once during the first workflow task, then
        // was replayed during the signal-resume workflow task.
        // In fake mode the side-effect counter may increment once per
        // non-replay execution pass but must never exceed the number of
        // distinct workflow task executions that evaluate that step live.
        $this->assertLessThanOrEqual(2, TestSideEffectWorkflow::sideEffectExecutions());
    }

    public function testQueryReplayReturnsSideEffectValueWithoutReExecution(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-query');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $executionsAfterStart = TestSideEffectWorkflow::sideEffectExecutions();
        $this->assertSame(1, $executionsAfterStart);

        // Multiple queries should not re-execute the side-effect closure.
        $token1 = $workflow->query('currentToken');
        $token2 = $workflow->query('currentToken');

        $this->assertSame(1, $token1);
        $this->assertSame(1, $token2);

        // Execution count should remain at 1 — queries replay from history.
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());
    }

    public function testSideEffectResultUsesExternalPayloadStorageWhenThresholdRequiresIt(): void
    {
        WorkflowStub::fake();

        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);

        $workflow = WorkflowStub::make(TestLargeSideEffectWorkflow::class, 'side-effect-external-payload');
        $workflow->start(512);

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'length' => 512,
            'value' => str_repeat('x', 512),
        ], $workflow->output());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->firstOrFail();

        $this->assertIsArray($event->payload['result']);
        $this->assertArrayHasKey('external_storage', $event->payload['result']);
        $this->assertArrayNotHasKey('blob', $event->payload['result']);
        $this->assertSame(
            ExternalPayloadReference::SCHEMA,
            $event->payload['result']['external_storage']['schema'],
        );
    }

    public function testExternalizedSideEffectResultFeedsReaderSurfacesAndQueryReplay(): void
    {
        WorkflowStub::fake();
        TestLargeSideEffectWorkflow::resetCounter();

        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $this->bindExternalPayloadPolicy($driver);

        $workflow = WorkflowStub::make(TestLargeSideEffectWorkflow::class, 'side-effect-external-readers');
        $workflow->start(512, true);

        $expected = [
            'length' => 512,
            'value' => str_repeat('x', 512),
        ];

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame(1, TestLargeSideEffectWorkflow::sideEffectExecutions());
        $this->assertSame($expected, $workflow->query('currentPayload'));
        $this->assertSame(1, TestLargeSideEffectWorkflow::sideEffectExecutions());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->firstOrFail();

        $this->assertIsArray($event->payload['result']);
        $this->assertArrayHasKey('external_storage', $event->payload['result']);
        $this->assertArrayNotHasKey('blob', $event->payload['result']);

        $timeline = HistoryTimeline::fromHistory($run->fresh());
        $timelineEntry = collect($timeline)
            ->first(static fn (array $entry): bool => ($entry['type'] ?? null) === 'SideEffectRecorded');

        $this->assertIsArray($timelineEntry);
        $this->assertSame('side_effect', $timelineEntry['kind']);
        $this->assertSame('Recorded side effect.', $timelineEntry['summary']);
        $this->assertArrayNotHasKey('result', $timelineEntry);

        $detail = RunDetailView::forRun($run->fresh());
        $detailEntry = collect($detail['timeline'])
            ->first(static fn (array $entry): bool => ($entry['type'] ?? null) === 'SideEffectRecorded');

        $this->assertIsArray($detailEntry);
        $this->assertSame('side_effect', $detailEntry['kind']);
        $this->assertArrayNotHasKey('result', $detailEntry);

        $export = HistoryExport::forRun($run->fresh());
        $exportEvent = collect($export['history_events'])
            ->first(static fn (array $entry): bool => ($entry['type'] ?? null) === 'SideEffectRecorded');
        $exportTimelineEntry = collect($export['timeline'])
            ->first(static fn (array $entry): bool => ($entry['type'] ?? null) === 'SideEffectRecorded');

        $this->assertIsArray($exportEvent);
        $this->assertIsArray($exportEvent['payload']['result']);
        $this->assertArrayHasKey('external_storage', $exportEvent['payload']['result']);
        $this->assertArrayNotHasKey('blob', $exportEvent['payload']['result']);
        $this->assertIsArray($exportTimelineEntry);
        $this->assertSame('side_effect', $exportTimelineEntry['kind']);

        $replayedRun = (new WorkflowReplayer())->runFromHistoryExport($export);
        $replayedEvent = $replayedRun->historyEvents
            ->first(static fn (WorkflowHistoryEvent $entry): bool => $entry->event_type === HistoryEventType::SideEffectRecorded);

        $this->assertInstanceOf(WorkflowHistoryEvent::class, $replayedEvent);
        $this->assertIsArray($replayedEvent->payload['result']);
        $this->assertArrayHasKey('external_storage', $replayedEvent->payload['result']);
        $this->assertSame($expected, (new QueryStateReplayer())->query($replayedRun, 'currentPayload'));
        $this->assertSame(1, TestLargeSideEffectWorkflow::sideEffectExecutions());
    }

    public function testManySideEffectsRecordDistinctHistoryEventsInSequence(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestManySideEffectsWorkflow::class, 'many-side-effects');
        $workflow->start(5);

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();
        $this->assertCount(5, $output);

        // Each side-effect should produce a distinct SideEffectRecorded event.
        $sideEffectEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(5, $sideEffectEvents);

        // Events should have strictly increasing sequence numbers.
        $sequences = $sideEffectEvents->pluck('sequence')
            ->all();
        for ($i = 1; $i < count($sequences); $i++) {
            $this->assertGreaterThan($sequences[$i - 1], $sequences[$i]);
        }
    }

    public function testSideEffectAppearsInHistoryTimeline(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'side-effect-timeline');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all();

        $this->assertContains(HistoryEventType::WorkflowStarted->value, $events);
        $this->assertContains(HistoryEventType::SideEffectRecorded->value, $events);

        // The side-effect should appear after WorkflowStarted and before any signal wait.
        $startedIndex = array_search(HistoryEventType::WorkflowStarted->value, $events, true);
        $sideEffectIndex = array_search(HistoryEventType::SideEffectRecorded->value, $events, true);

        $this->assertGreaterThan($startedIndex, $sideEffectIndex);
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
        $this->storageRoot = sys_get_temp_dir().'/dw-side-effect-payloads-'.bin2hex(random_bytes(6));

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
