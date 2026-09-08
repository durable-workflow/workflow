<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryTimeline;

final class ServiceCallHistoryTimelineTest extends TestCase
{
    #[DataProvider('serviceCallEvents')]
    public function testServiceCallEventsHaveSafeSummariesAndSourceIdentity(
        HistoryEventType $type,
        string $outcome,
        bool $sparse,
    ): void {
        $run = new WorkflowRun();
        foreach (['commands', 'tasks', 'activityExecutions', 'timers', 'failures'] as $relation) {
            $run->setRelation($relation, new Collection());
        }
        $event = new WorkflowHistoryEvent();
        $event->forceFill([
            'id' => 'event-1',
            'sequence' => 1,
            'event_type' => $type,
            'payload' => $sparse ? [] : [
                'service_call_id' => 'call-1',
                'operation_name' => 'createinvoice',
                'request_payload' => 'private-request',
                'result' => 'private-result',
                'message' => 'private-failure-detail',
                'principal_claims' => [
                    'credential' => 'private-credential',
                ],
            ],
        ]);
        $run->setRelation('historyEvents', new Collection([$event]));

        $entry = HistoryTimeline::fromHistory($run)[0];

        $this->assertSame($type->value, $entry['type']);
        $this->assertSame('service_call', $entry['kind']);
        $this->assertSame('workflow_service_call', $entry['source_kind']);
        $this->assertSame($sparse ? null : 'call-1', $entry['source_id']);
        $this->assertSame($entry['source_id'], $entry['service_call_id']);
        $this->assertSame(
            'Service operation ' . ($sparse ? 'unknown' : 'createinvoice') . ' ' . $outcome . '.',
            $entry['summary']
        );
        $this->assertStringNotContainsString('private-', $entry['summary']);
    }

    /**
     * @return iterable<string, array{HistoryEventType, string, bool}>
     */
    public static function serviceCallEvents(): iterable
    {
        foreach ([
            'started' => HistoryEventType::ServiceCallStarted,
            'completed' => HistoryEventType::ServiceCallCompleted,
            'failed' => HistoryEventType::ServiceCallFailed,
            'cancelled' => HistoryEventType::ServiceCallCancelled,
        ] as $outcome => $event) {
            yield $outcome => [$event, $outcome, false];
            yield $outcome . ' sparse' => [$event, $outcome, true];
        }
    }
}
