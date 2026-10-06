<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\WorkerSessionOptions;

final class ActivitySessionHistoryTest extends TestCase
{
    public function testHistoryProjectionPreservesTheOriginalSessionContract(): void
    {
        $session = (new WorkerSessionOptions(
            sessionId: 'gpu-render',
            queue: 'gpu-activities',
            requirements: ['gpu:nvidia-l4'],
            leaseSeconds: 10,
            ttlSeconds: 30,
            createIfMissing: false,
            allowReacquireAfterFailure: false,
        ))->toSnapshot();
        $event = new WorkflowHistoryEvent([
            'event_type' => HistoryEventType::ActivityScheduled,
            'payload' => [
                'sequence' => 1,
                'activity_type' => 'render',
                'activity' => [
                    'id' => 'activity-1',
                    'worker_session' => $session,
                ],
            ],
        ]);

        $this->assertSame($session, ActivitySnapshot::fromEvent($event)['worker_session']);
        $event->payload = [
            'activity_type' => 'render',
            'worker_session' => $session,
        ];
        $this->assertSame($session, ActivitySnapshot::fromEvent($event)['worker_session']);
        $event->payload = [
            'activity_type' => 'render',
        ];
        $this->assertArrayNotHasKey('worker_session', ActivitySnapshot::fromEvent($event));
        foreach ([null, 'not-a-session'] as $invalidSession) {
            $event->payload = [
                'activity_type' => 'render',
                'worker_session' => $invalidSession,
            ];
            $this->assertArrayNotHasKey('worker_session', ActivitySnapshot::fromEvent($event));
        }
    }
}
