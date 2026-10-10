<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2TerminalReasonDiagnosticsTest extends TestCase
{
    #[DataProvider('terminalReasons')]
    public function testEmbeddedTerminalCommandPreservesReasonAndPortableDiagnostics(
        string $operation,
        string $reason,
        string $expectedMessage,
    ): void {
        Queue::fake();
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('diagnostic regression');
        $runId = $workflow->runId();
        $this->assertIsString($runId);
        $selected = WorkflowStub::loadRun($runId);
        $result = $operation === 'cancel'
            ? $selected->attemptCancel($reason)
            : $selected->attemptTerminate($reason);

        $this->assertTrue($result->accepted());
        $run = $selected->run();
        $this->assertNotNull($run);
        $this->assertSame(
            $operation === 'cancel' ? RunStatus::Cancelled : RunStatus::Terminated,
            $run->fresh()
->status,
        );
        $terminalType = $operation === 'cancel'
            ? HistoryEventType::WorkflowCancelled
            : HistoryEventType::WorkflowTerminated;
        $event = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', $terminalType->value)
            ->sole();
        $failure = WorkflowFailure::query()->where('workflow_run_id', $runId)->sole();
        $physicalMessage = DB::connection($failure->getConnectionName())
            ->table($failure->getTable())
            ->where('id', $failure->id)
            ->value('message');

        $this->assertIsString($physicalMessage);
        $this->assertSame($expectedMessage, $physicalMessage);
        $this->assertSame($physicalMessage, $failure->fresh()->message);
        $this->assertSame($physicalMessage, $event->fresh()->payload['message']);
        $this->assertSame($reason, $event->fresh()->payload['reason']);
        $this->assertSame($failure->id, $event->payload['failure_id']);
        $this->assertSame($runId, $failure->workflow_run_id);
        $this->assertSame('workflow_run', $failure->source_kind);
        $this->assertSame($runId, $failure->source_id);
        $this->assertSame($operation === 'cancel' ? 'cancelled' : 'terminated', $failure->propagation_kind);
        $this->assertSame($event->payload['exception_class'], $failure->exception_class);
        $command = WorkflowCommand::query()->findOrFail($result->commandId());
        $this->assertSame(
            $reason,
            Serializer::unserializeWithCodec($command->payload_codec, $command->payload)['reason']
        );
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('status', '!=', TaskStatus::Cancelled->value)->count());

        if (str_contains($reason, "\0")) {
            $this->assertSame(
                'Workflow ' . $failure->propagation_kind . ': ' . $reason,
                json_decode($physicalMessage, true, 512, JSON_THROW_ON_ERROR)
            );
        }

        $duplicate = $operation === 'cancel'
            ? $selected->attemptCancel('later reason')
            : $selected->attemptTerminate('later reason');
        $this->assertTrue($duplicate->rejected());
        $this->assertSame($expectedMessage, $failure->fresh()->message);
        $this->assertSame($event->payload, $event->fresh()->payload);
        $this->assertSame(1, WorkflowFailure::query()->where('workflow_run_id', $runId)->count());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function terminalReasons(): iterable
    {
        yield 'cancellation internal NUL' => ['cancel', "left\0right", '"Workflow cancelled: left\u0000right"'];
        yield 'termination internal NUL' => ['terminate', "left\0right", '"Workflow terminated: left\u0000right"'];
        yield 'padded Unicode cancellation' => [
            'cancel', " \t\0\u{00a0}parity cancellation λ\u{200b}\u{1d173}\r\n",
            '"Workflow cancelled:  \t\u0000' . "\u{00a0}parity cancellation λ\u{200b}\u{1d173}" . '\r\n"',
        ];
        yield 'literal escape remains literal' => ['cancel', 'left\u0000right', 'Workflow cancelled: left\u0000right'];
        yield 'NUL and literal backslash zero with quote' => [
            'cancel', "left\\0\0\"right", '"Workflow cancelled: left\\\\0\u0000\"right"',
        ];
        yield 'NUL with Unicode line separators' => [
            'cancel', "left\0\u{2028}middle\u{2029}right",
            '"Workflow cancelled: left\u0000' . "\u{2028}middle\u{2029}right" . '"',
        ];
    }
}
