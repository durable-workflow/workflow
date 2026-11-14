<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\Support\ChildStartHistory;

final class ChildAwaitReceiptOrderTest extends TestCase
{
    public function testDatabaseJsonMemberOrderDoesNotChangeTheRecordedAdmission(): void
    {
        $receipt = $this->receipt();
        $payload = ChildStartHistory::awaitPayload($receipt, 2);
        $payload['receipt'] = array_reverse($payload['receipt'], true);
        [$parent, $event] = $this->recorded($payload);
        try {
            $observed = ChildStartHistory::awaitedEvent($parent, $receipt, 2);
        } catch (HistoryEventShapeMismatchException $error) {
            $this->fail('JSON member order changed a valid admission: ' . $error->getMessage());
        }
        $this->assertSame($event, $observed);
        $this->assertSame(array_reverse($receipt->toArray(), true), $event->payload['receipt']);
    }

    public static function substitutions(): array
    {
        return [
            'future format' => ['version', 2],
            'boolean format' => ['version', true],
            'namespace' => ['namespace', 'other-namespace'],
            'parent instance' => ['parentInstanceId', 'other-parent'],
            'parent run' => ['parentRunId', 'other-parent-run'],
            'start sequence' => ['startSequence', 2],
            'string sequence' => ['startSequence', '1'],
            'child instance' => ['childInstanceId', 'other-child'],
            'child run' => ['childRunId', 'other-child-run'],
            'child call' => ['childCallId', 'other-call'],
            'workflow type' => ['workflowType', 'other-type'],
            'extra field' => ['unselected', 'extra'],
            'missing field' => ['childRunId', null],
        ];
    }

    #[DataProvider('substitutions')]
    public function testReorderingCannotHideSubstitutedOrMalformedIdentity(string $key, mixed $value): void
    {
        $receipt = $this->receipt();
        $payload = ChildStartHistory::awaitPayload($receipt, 2);
        $payload['receipt'] = array_reverse($payload['receipt'], true);
        if ($value === null) {
            unset($payload['receipt'][$key]);
        } else {
            $payload['receipt'][$key] = $value;
        }
        [$parent] = $this->recorded($payload);
        $this->expectException(HistoryEventShapeMismatchException::class);
        ChildStartHistory::awaitedEvent($parent, $receipt, 2);
    }

    public function testTheOuterRecordedChildIdentityMustStillMatch(): void
    {
        $receipt = $this->receipt();
        $payload = ChildStartHistory::awaitPayload($receipt, 2);
        $payload['receipt'] = array_reverse($payload['receipt'], true);
        $payload['child_workflow_run_id'] = 'other-child-run';
        [$parent] = $this->recorded($payload);
        $this->expectException(HistoryEventShapeMismatchException::class);
        ChildStartHistory::awaitedEvent($parent, $receipt, 2);
    }

    private function receipt(): StartedChildWorkflow
    {
        return new StartedChildWorkflow(
            'example',
            'parent',
            'parent-run',
            1,
            'child',
            'child-run',
            'call',
            'child-type'
        );
    }

    private function recorded(array $payload): array
    {
        $run = new WorkflowRun();
        $run->setRawAttributes([
            'id' => 'parent-run',
            'namespace' => 'example',
            'workflow_instance_id' => 'parent',
        ]);
        $event = new WorkflowHistoryEvent([
            'workflow_run_id' => 'parent-run',
            'event_type' => HistoryEventType::ChildWorkflowAwaited,
            'payload' => $payload,
        ]);
        $run->setRelation('historyEvents', collect([$event]));
        return [$run, $event];
    }
}
