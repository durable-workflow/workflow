<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\UpdateMethod;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use function Workflow\V2\signal;
use Workflow\V2\Support\CommandIntent;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class UpdateRequestIdentityTest extends TestCase
{
    public static function keys(): iterable
    {
        yield 'same caller, changing proxy' => ['same', 'same', 'proxy-a', 'proxy-b', true];
        yield 'different callers, one proxy' => ['first', 'second', 'proxy', 'proxy', false];
        yield 'omitted callers, one proxy' => [null, null, 'proxy', 'proxy', false];
        yield 'literal zero' => ['0', '0', 'proxy-a', 'proxy-b', true];
        yield 'maximum HTTP key' => [str_repeat('é', 255), str_repeat('é', 255), 'a', 'b', true];
        yield 'opaque case' => ['Caller', 'caller', 'a', 'b', false];
    }

    #[DataProvider('keys')]
    public function testCallerKeyControlsTheReceiptIndependentlyOfCorrelation(
        ?string $firstKey,
        ?string $secondKey,
        string $firstHeader,
        string $secondHeader,
        bool $sameReceipt
    ): void {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->context($firstKey, $firstHeader))
            ->submitUpdate('change', 'original');
        $before = $this->rows();
        $second = $workflow->withCommandContext($this->context($secondKey, $secondHeader))
            ->submitUpdate('change', 'original');

        $this->assertTrue($first->accepted());
        $this->assertTrue($second->accepted());
        $this->assertFalse($first->retained());
        $this->assertSame($firstKey, $first->requestId());
        if ($sameReceipt) {
            $this->assertSame($first->updateId(), $second->updateId());
            $this->assertSame($first->commandId(), $second->commandId());
            $this->assertTrue($second->retained());
            $this->assertTrue($second->intentVerified());
            $this->assertSame($before, $this->rows());
        } else {
            $this->assertNotSame($first->updateId(), $second->updateId());
            $this->assertFalse($second->retained());
            $this->assertSame(2, WorkflowUpdate::query()->count());
        }
        $command = WorkflowCommand::query()->findOrFail($first->commandId());
        $this->assertSame($firstKey, $command->requestId());
        $this->assertLessThanOrEqual(191, strlen($command->request_id));
        $this->assertTrue(CommandIntent::isDigest($command->intentDigest()));
        $this->assertSame($firstHeader, $command->context['request']['headers']['x_request_id']);
    }

    public function testReusingAKeyWithDifferentArgumentsIsRefusedWithoutConsumingTheKey(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->context('caller', 'proxy-a'))
            ->submitUpdate('change', 'original');
        $accepted = WorkflowHistoryEvent::query()->where('event_type', 'UpdateAccepted')->count();

        $conflict = $workflow->withCommandContext($this->context('caller', 'proxy-b'))
            ->submitUpdate('change', 'changed');

        $this->assertTrue($conflict->rejected());
        $this->assertFalse($conflict->accepted());
        $this->assertTrue($conflict->requestIdConflict());
        $this->assertSame('request_id_conflict', $conflict->rejectionReason());
        $this->assertSame(CommandOutcome::RejectedRequestIdConflict->value, $conflict->outcome());
        $this->assertSame('caller', $conflict->requestId());
        $this->assertNotSame($first->commandId(), $conflict->commandId());
        $this->assertSame([
            'retained_command_id' => $first->commandId(),
            'retained_command_type' => 'update',
            'retained_outcome' => null,
            'conflict' => ['arguments'],
        ], $conflict->conflict());
        $this->assertSame($accepted, WorkflowHistoryEvent::query()->where('event_type', 'UpdateAccepted')->count());
        $this->assertSame('rejected', WorkflowUpdate::query()->findOrFail($conflict->updateId())->status->value);

        $retry = $workflow->withCommandContext($this->context('caller', 'proxy-c'))
            ->submitUpdate('change', 'original');
        $this->assertTrue($retry->retained());
        $this->assertSame($first->updateId(), $retry->updateId());

        $renamed = $workflow->withCommandContext($this->context('caller', 'proxy-d'))
            ->submitUpdate('rename', 'original');
        $this->assertTrue($renamed->requestIdConflict());
        $this->assertSame(['name'], $renamed->conflict()['conflict']);
    }

    public function testTypedArgumentsAndNamedFormsCompareByValue(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->context('typed', 'a'))
            ->submitUpdate('change', 'same');
        $named = $workflow->withCommandContext($this->context('typed', 'b'))
            ->submitUpdateWithArguments('change', [
                'value' => 'same',
            ]);

        $this->assertTrue($named->retained());
        $this->assertSame($first->updateId(), $named->updateId());
    }

    public function testLegacyProxyColumnsNeitherHideReceiptsNorReserveAnotherCallersKey(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->context('original-caller', 'proxy'))
            ->submitUpdate('change', 'original');
        $command = WorkflowCommand::query()->findOrFail($first->commandId());
        $command->forceFill([
            'request_id' => 'next-caller',
        ])->save();
        $before = $this->rows();

        $duplicate = $workflow->withCommandContext($this->context('original-caller', 'other-proxy'))
            ->submitUpdate('change', 'original');
        $this->assertSame($first->updateId(), $duplicate->updateId());
        $this->assertTrue($duplicate->retained());
        $this->assertSame($before, $this->rows());
        $next = $workflow->withCommandContext($this->context('next-caller', 'proxy'))
            ->submitUpdate('change', 'next');
        $this->assertNotSame($first->updateId(), $next->updateId());
        $this->assertSame('next-caller', $command->fresh()->request_id);
    }

    public function testAnOccupiedLookupTokenDoesNotAliasAnotherLogicalKey(): void
    {
        $workflow = $this->waitingWorkflow();
        $old = $workflow->withCommandContext($this->context('new-caller', 'proxy'))
            ->submitUpdate('change', 'old');
        $command = WorkflowCommand::query()->findOrFail($old->commandId());
        $context = $command->context;
        $context['server']['metadata']['request_id'] = 'old-caller';
        $context['request']['request_id'] = 'old-caller';
        $command->forceFill([
            'context' => $context,
        ])->save();
        $original = $command->fresh()
            ->getRawOriginal();

        $new = $workflow->withCommandContext($this->context('new-caller', 'proxy'))
            ->submitUpdate('change', 'new');
        $this->assertNotSame($old->updateId(), $new->updateId());
        $this->assertSame($original, $command->fresh()->getRawOriginal());
        $before = $this->rows();
        $repeat = $workflow->withCommandContext($this->context('new-caller', 'other-proxy'))
            ->submitUpdate('change', 'new');
        $this->assertSame($new->updateId(), $repeat->updateId());
        $this->assertSame($before, $this->rows());
    }

    public function testLegacyEmbeddedColumnKeyRemainsReadableWithoutContextRepair(): void
    {
        $workflow = $this->waitingWorkflow();
        $context = CommandContext::phpApi()->with([
            'request' => [
                'request_id' => 'embedded-key',
            ],
        ]);
        $first = $workflow->withCommandContext($context)
            ->submitUpdate('change', 'first');
        $command = WorkflowCommand::query()->findOrFail($first->commandId());
        $command->forceFill([
            'request_id' => 'embedded-key',
            'context' => CommandContext::phpApi()->attributes()['context'],
        ])->save();
        $before = $this->rows();

        $this->assertSame('embedded-key', $command->requestId());
        $duplicate = $workflow->withCommandContext($context)
            ->submitUpdate('change', 'first');
        $this->assertSame($first->updateId(), $duplicate->updateId());
        $this->assertSame($before, $this->rows());
    }

    public function testLegacyAcceptedReceiptWithoutDigestIsComparedFromItsRetainedArguments(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->context('legacy', 'a'))
            ->submitUpdate('change', 'first');
        WorkflowCommand::query()->whereKey($first->commandId())->update([
            'intent_digest' => null,
        ]);
        $before = $this->rows();

        $same = $workflow->withCommandContext($this->context('legacy', 'b'))
            ->submitUpdate('change', 'first');
        $this->assertTrue($same->retained());
        $this->assertTrue($same->intentVerified());
        $this->assertSame($first->updateId(), $same->updateId());
        $this->assertSame($before, $this->rows());

        $different = $workflow->withCommandContext($this->context('legacy', 'c'))
            ->submitUpdate('change', 'second');
        $this->assertTrue($different->requestIdConflict());
        $this->assertSame($first->commandId(), $different->conflict()['retained_command_id']);
    }

    public function testLegacyRejectedReceiptWithoutDigestIsReturnedUnverified(): void
    {
        $workflow = $this->waitingWorkflow();
        $rejection = $workflow->withCommandContext($this->context('legacy-rejected', 'a'))
            ->submitUpdate('missing', 'first');
        $this->assertTrue($rejection->rejected());
        $this->assertSame('unknown_update', $rejection->rejectionReason());
        WorkflowCommand::query()->whereKey($rejection->commandId())->update([
            'intent_digest' => null,
        ]);
        $before = $this->rows();

        $retry = $workflow->withCommandContext($this->context('legacy-rejected', 'b'))
            ->submitUpdate('change', 'anything');
        $this->assertTrue($retry->retained());
        $this->assertFalse($retry->intentVerified());
        $this->assertTrue($retry->rejected());
        $this->assertSame($rejection->commandId(), $retry->commandId());
        $this->assertSame($before, $this->rows());
    }

    public function testTheEarliestRetainedReceiptWinsAfterLegacyDuplicateAdmission(): void
    {
        $workflow = $this->waitingWorkflow();
        $first = $workflow->withCommandContext($this->context('caller', 'a'))
            ->submitUpdate('change', 'first');
        $later = $workflow->withCommandContext($this->context('different', 'b'))
            ->submitUpdate('change', 'later');
        $command = WorkflowCommand::query()->findOrFail($later->commandId());
        $context = $command->context;
        $context['server']['metadata']['request_id'] = 'caller';
        $context['request']['request_id'] = 'caller';
        $command->forceFill([
            'context' => $context,
        ])->save();
        $before = $this->rows();

        $repeat = $workflow->withCommandContext($this->context('caller', 'c'))
            ->submitUpdate('change', 'first');
        $this->assertSame($first->updateId(), $repeat->updateId());
        $this->assertSame($before, $this->rows());
    }

    public static function intakeLimits(): iterable
    {
        yield 'pending updates' => ['pending_update_count'];
        yield 'payload bytes' => ['payload_size'];
    }

    #[DataProvider('intakeLimits')]
    public function testIntakeLimitsPrecedeTheMissingDefinitionExecutionGate(string $kind): void
    {
        $workflow = $this->waitingWorkflow();
        if ($kind === 'pending_update_count') {
            $workflow->submitUpdate('change', 'first');
            config()
                ->set('workflows.v2.structural_limits.pending_update_count', 1);
        } else {
            config()->set('workflows.v2.structural_limits.payload_size_bytes', 1);
        }
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $run->forceFill([
            'workflow_class' => 'Removed\\Workflow',
            'workflow_type' => 'removed.update-type',
        ])->save();
        $deadline = $run->run_deadline_at;
        $rejection = $workflow->withCommandContext($this->context('over-limit', 'proxy'))
            ->submitUpdate('change', 'over-limit');
        $this->assertTrue($rejection->rejected());
        $this->assertSame('structural_limit_exceeded', $rejection->rejectionReason());
        $metadata = $rejection->payloadValues(['structural_limit_kind', 'structural_limit_configured']);
        $this->assertSame([
            'structural_limit_kind' => $kind,
            'structural_limit_configured' => 1,
        ], $metadata);
        $this->assertSame('waiting', $run->fresh()->status->value);
        $this->assertEquals($deadline, $run->fresh()->run_deadline_at);
        $before = $this->rows();
        $duplicate = $workflow->withCommandContext($this->context('over-limit', 'different-proxy'))
            ->submitUpdate('change', 'over-limit');
        $this->assertSame($rejection->commandId(), $duplicate->commandId());
        $this->assertTrue($duplicate->retained());
        $this->assertTrue($duplicate->intentVerified());
        $this->assertSame($before, $this->rows());

        $conflict = $workflow->withCommandContext($this->context('over-limit', 'another-proxy'))
            ->submitUpdate('change', 'changed');
        $this->assertTrue($conflict->requestIdConflict());
        $this->assertSame($rejection->commandId(), $conflict->conflict()['retained_command_id']);
    }

    private function waitingWorkflow(): WorkflowStub
    {
        config()->set('queue.default', 'redis');
        Queue::fake();
        $workflow = WorkflowStub::make(UpdateRequestIdentityWorkflow::class, 'update-request-key');
        $workflow->start();
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
        $result = $this->app->make(DefaultWorkflowTaskBridge::class)->execute($task->id);
        $this->assertSame('waiting', $result['run_status']);
        return $workflow;
    }

    private function context(?string $key, string $header): CommandContext
    {
        return CommandContext::controlPlane()->with([
            'request' => [
                'request_id' => $header,
                'headers' => [
                    'x_request_id' => $header,
                ],
            ],
            'server' => [
                'command' => 'update',
                'metadata' => array_filter([
                    'request_id' => $key,
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ]);
    }

    private function rows(): array
    {
        $rows = [];
        foreach ([
            WorkflowCommand::class,
            WorkflowUpdate::class,
            WorkflowTask::class,
            WorkflowHistoryEvent::class,
        ] as $model) {
            $rows[$model] = $model::query()->orderBy('id')->get()->map(
                static fn ($row): array => $row->getRawOriginal()
            )->all();
        }
        return $rows;
    }
}

final class UpdateRequestIdentityWorkflow extends Workflow
{
    public function handle(): void
    {
        signal('finish');
    }

    #[UpdateMethod]
    public function change(string $value): string
    {
        return $value;
    }

    #[UpdateMethod]
    public function rename(string $value): string
    {
        return strtoupper($value);
    }
}
