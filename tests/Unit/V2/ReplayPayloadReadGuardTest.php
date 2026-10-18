<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityReplayIdentity;
use Workflow\V2\Support\ParallelChildGroup;

final class ReplayPayloadReadGuardTest extends TestCase
{
    public function testActivityIdentityDoesNotDecodeUnrelatedEventTypes(): void
    {
        $unrelated = self::event(HistoryEventType::WorkflowStarted, 1, 7);
        $scheduled = self::event(HistoryEventType::ActivityScheduled, 2, 7);
        $after = self::event(HistoryEventType::ActivityCompleted, 3, 7);
        $events = new Collection([$unrelated, $scheduled, $after]);
        $before = $events->map->getAttributes()
            ->all();
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', $events);

        ActivityReplayIdentity::assertCall($run, new ActivityCall('ExampleActivity', [1]), 7);

        self::assertSame(0, $unrelated->payloadReads);
        self::assertGreaterThan(0, $scheduled->payloadReads);
        self::assertSame(0, $after->payloadReads);
        self::assertSame($before, $events->map->getAttributes()->all());
    }

    public function testFailureLookupDoesNotDecodeUnrelatedEventTypes(): void
    {
        $events = new Collection();
        foreach ([
            HistoryEventType::WorkflowStarted,
            HistoryEventType::ActivityScheduled,
            HistoryEventType::ActivityStarted,
            HistoryEventType::ActivityCompleted,
            HistoryEventType::TimerScheduled,
            HistoryEventType::TimerFired,
            HistoryEventType::WorkflowCompleted,
        ] as $index => $type) {
            $events->push(self::event($type, $index + 1, 1));
        }
        $before = $events->map->getAttributes()
            ->all();
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', $events);

        self::assertNull(ParallelChildGroup::memberFailureResolution($run, 1, 3));

        foreach ($events as $event) {
            self::assertSame(0, $event->payloadReads);
        }
        self::assertSame($before, $events->map->getAttributes()->all());
    }

    public function testFailureLookupKeepsOriginalMemberAndHistoryOrdering(): void
    {
        $unrelated = self::event(HistoryEventType::ActivityCompleted, 1, 1);
        $otherGroup = self::event(HistoryEventType::ActivityFailed, 2, 9);
        $later = self::event(HistoryEventType::ActivityFailed, 40, 2);
        $earlier = self::event(HistoryEventType::ActivityTimedOut, 30, 3);
        $events = new Collection([$unrelated, $otherGroup, $later, $earlier]);
        $before = $events->map->getAttributes()
            ->all();
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', $events);

        self::assertSame($earlier, ParallelChildGroup::memberFailureResolution($run, 1, 3));
        self::assertGreaterThan(0, $earlier->payloadReads);
        self::assertSame($before, $events->map->getAttributes()->all());
    }

    private static function event(
        HistoryEventType $type,
        int $historySequence,
        int $commandSequence
    ): ReadCountHistoryEvent {
        return new ReadCountHistoryEvent([
            'id' => 'event-' . $historySequence,
            'sequence' => $historySequence,
            'event_type' => $type,
            'payload' => [
                'sequence' => $commandSequence,
            ],
        ]);
    }
}

final class ReadCountHistoryEvent extends WorkflowHistoryEvent
{
    public int $payloadReads = 0;

    public function getAttribute($key): mixed
    {
        if ($key === 'payload') {
            ++$this->payloadReads;
        }

        return parent::getAttribute($key);
    }
}
