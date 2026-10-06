<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestCancellationContextWorkflow;
use Tests\TestCase;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\WorkflowStub;

final class CancellationRequestContextTest extends TestCase
{
    public function testCanonicalContextAndDuplicatesKeepTheOriginalRequesterIdentityAndBudget(): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        $workflow = WorkflowStub::make(TestCancellationContextWorkflow::class)
            ->withCommandContext(CommandContext::controlPlane()->withPrincipal('operator', 'operator-1', 'Maintainer')
                ->with([
                    'principal' => [
                        'unrelated' => 'omit',
                    ],
                    'request' => [
                        'unrelated' => 'omit',
                    ],
                ]));
        $workflow->start();
        $first = $workflow->requestCancellation('maintenance', 30);
        $context = $first->cancellationContext();
        $this->assertNotNull($context);
        $this->assertSame($first->commandId(), $context->requestId);
        $this->assertSame($context->requestId, $context->rootRequestId);
        $this->assertSame($workflow->id(), $context->rootWorkflowInstanceId);
        $this->assertSame($workflow->runId(), $context->rootWorkflowRunId);
        $this->assertNull($context->parentRequestId);
        $this->assertSame('maintenance', $context->reason);
        $this->assertSame('control_plane', $context->source);
        $this->assertSame([
            'type' => 'operator',
            'id' => 'operator-1',
            'label' => 'Maintainer',
        ], $context->requester);
        $this->assertSame(30.0, (float) $context->requestedAt()->diffInSeconds($context->deadline()));
        $this->assertCount(1, $context->lineage);

        $run = $workflow->run()
            ->fresh('historyEvents');
        $requested = $run->historyEvents->firstWhere('event_type', HistoryEventType::CooperativeCancellationRequested);
        $this->assertSame($context->toArray(), $requested->payload['cancellation']);
        $this->assertSame($context->toArray(), CooperativeCancellationDelivery::context($run)->toArray());

        Carbon::setTestNow($context->deadline()->addMinute());
        try {
            $duplicate = $workflow->withCommandContext(
                CommandContext::phpApi()->withPrincipal('operator', 'operator-2')
            )
                ->requestCancellation('different reason', 3600);
            $this->assertSame($first->commandId(), $duplicate->commandId());
            $this->assertSame($context->toArray(), $duplicate->cancellationContext()->toArray());
            $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
