<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestUuidWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\WorkflowStub;

final class V2UuidWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testUuidHelpersRecordReplayStableUuid4AndUuid7Values(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestUuidWorkflow::class, 'uuid-helper-workflow');
        $workflow->start();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $queriedIds = $workflow->query('ids');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $queriedIds['uuid4'][0],
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $queriedIds['uuid7'][0],
        );
        $this->assertNotSame($queriedIds['uuid4'][0], $queriedIds['uuid4'][1]);
        $this->assertNotSame($queriedIds['uuid7'][0], $queriedIds['uuid7'][1]);
        $this->assertLessThan($queriedIds['uuid7'][1], $queriedIds['uuid7'][0]);

        $sideEffectEventsBeforeSignal = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SideEffectRecorded)
            ->count();

        $this->assertSame(4, $sideEffectEventsBeforeSignal);

        $workflow->signal('finish');
        $this->assertTrue($workflow->refresh()->completed());

        $this->assertSame($queriedIds, $workflow->output());
        $this->assertSame(
            $sideEffectEventsBeforeSignal,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->where('event_type', HistoryEventType::SideEffectRecorded)
                ->count(),
        );
    }
}
