<?php

declare(strict_types=1);

namespace Tests\Unit;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TestAwaitWorkflow;
use Tests\Fixtures\TestBadConnectionWorkflow;
use Tests\Fixtures\TestChatBotWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\Timer;
use Workflow\WorkflowStub;

final class WorkflowStubTest extends TestCase
{
    public function testMake(): void
    {
        Carbon::setTestNow('2022-01-01');

        $parentWorkflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedParentWorkflow = StoredWorkflow::findOrFail($parentWorkflow->id());
        $storedParentWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $workflow->start();
        $workflow->cancel();
        while (! $workflow->isCanceled());

        $workflow->fresh();
        $this->assertSame('2022-01-01 00:00:00', WorkflowStub::now()->toDateTimeString());
        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
        $this->assertNull($workflow->output());
        $this->assertSame(1, $workflow->logs()->count());

        $storedWorkflow->parents()
            ->attach($storedParentWorkflow, [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);
        $workflow->fail(new Exception('test'));
        $this->assertTrue($workflow->failed());

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->status = WorkflowCreatedStatus::class;
        $storedWorkflow->save();

        $workflow->cancel();
        while (! $workflow->isCanceled());

        $workflow->fresh();
        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
        $this->assertNull($workflow->output());
        $this->assertSame(2, $workflow->logs()->count());

        $this->assertSame('redis', WorkflowStub::connection());
        $this->assertSame('default', WorkflowStub::queue());
    }

    public function testComplete(): void
    {
        Carbon::setTestNow('2022-01-01');

        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);
        $workflow->start();
        $workflow->cancel();
        $workflow->fail(new Exception('resume'));

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->status = WorkflowCreatedStatus::class;
        $storedWorkflow->save();

        Cache::flush();

        $workflow->resume();
        $this->assertSame('2022-01-01 00:00:00', WorkflowStub::now()->toDateTimeString());
        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow', $workflow->output());
        $this->assertSame(1, $workflow->exceptions()->count());
        $this->assertSame(1, $workflow->logs()->count());
    }

    public function testAwait(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $workflow->start();
        $workflow->cancel();
        while (! $workflow->isCanceled());

        $workflow = WorkflowStub::load($workflow->id());

        $this->assertSame(0, WorkflowStub::getContext()->index);

        $promise = WorkflowStub::await(static fn () => false);

        $this->assertSame(1, $workflow->logs()->count());
        $this->assertSame(1, WorkflowStub::getContext()->index);

        $promise = WorkflowStub::await(static fn () => true);
        $workflow->fresh();

        $this->assertSame(2, $workflow->logs()->count());
        $this->assertSame(2, WorkflowStub::getContext()->index);
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 1,
            'class' => Signal::class,
        ]);
        $this->assertTrue(Serializer::unserialize($workflow->logs()->firstWhere('index', 1)->result));

        $workflow->fresh();
        $context = WorkflowStub::getContext();
        $context->index = 1;
        WorkflowStub::setContext($context);
        $promise = WorkflowStub::await(static fn () => true);

        $workflow->fresh();
        $this->assertSame(2, $workflow->logs()->count());
        $this->assertSame(2, WorkflowStub::getContext()->index);
    }

    public function testAwaitWithTimeout(): void
    {
        Cache::flush();

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $workflow->start();
        $workflow->cancel();
        while (! $workflow->isCanceled());

        Cache::flush();

        $workflow = WorkflowStub::load($workflow->id());

        $this->assertSame(0, WorkflowStub::getContext()->index);

        $promise = WorkflowStub::awaitWithTimeout(60, static fn () => false);

        $this->assertSame(1, $workflow->logs()->count());
        $this->assertSame(1, WorkflowStub::getContext()->index);

        $promise = WorkflowStub::awaitWithTimeout('1 minute', static fn () => true);
        $workflow->fresh();

        $this->assertSame(2, $workflow->logs()->count());
        $this->assertSame(2, WorkflowStub::getContext()->index);
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 1,
            'class' => Signal::class,
        ]);
        $this->assertTrue(Serializer::unserialize($workflow->logs()->firstWhere('index', 1)->result));

        $workflow->fresh();
        $context = WorkflowStub::getContext();
        $context->index = 1;
        WorkflowStub::setContext($context);
        $promise = WorkflowStub::awaitWithTimeout('1 minute', static fn () => true);

        $workflow->fresh();
        $this->assertSame(2, $workflow->logs()->count());
        $this->assertSame(2, WorkflowStub::getContext()->index);
    }

    public function testAwaitWithTimeoutTimedout(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $workflow->start();
        $workflow->cancel();
        while (! $workflow->isCanceled());

        Cache::flush();

        $this->assertSame(1, $workflow->logs()->count());
        $this->assertSame(1, WorkflowStub::getContext()->index);

        $workflow = WorkflowStub::load($workflow->id());
        $context = WorkflowStub::getContext();
        $context->index = 1;
        WorkflowStub::setContext($context);

        $promise = WorkflowStub::awaitWithTimeout('1 minute', static fn () => false);

        $this->assertSame(1, $workflow->logs()->count());
        $this->assertSame(2, WorkflowStub::getContext()->index);

        $workflow = WorkflowStub::load($workflow->id());
        $context = WorkflowStub::getContext();
        $context->index = 1;
        $context->now = $context->now->addMinute();
        WorkflowStub::setContext($context);

        $promise = WorkflowStub::awaitWithTimeout('1 minute', static fn () => false);

        $this->assertSame(2, $workflow->logs()->count());
        $this->assertSame(2, WorkflowStub::getContext()->index);
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 1,
            'class' => Timer::class,
        ]);
        $this->assertTrue(Serializer::unserialize($workflow->logs()->firstWhere('index', 1)->result));
    }

    public function testConnection(): void
    {
        Carbon::setTestNow('2022-01-01');

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $otherWorkflow = WorkflowStub::load(WorkflowStub::make(TestBadConnectionWorkflow::class)->id());

        $workflow->cancel();

        $this->assertSame('redis', WorkflowStub::connection());
        $this->assertSame('default', WorkflowStub::queue());
    }

    public function testHandlesDuplicateLogInsertionProperly(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowCreatedStatus::$name,
        ]);

        $storedWorkflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => now(),
            ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(true),
            ]);

        $workflow = $storedWorkflow->toWorkflow();

        Cache::flush();

        $workflow->next(0, now(), Signal::class, true);

        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
        $this->assertSame(1, $workflow->logs()->count());

        Queue::assertPushed(TestWorkflow::class, 1);
    }

    public function testResumeWhilePendingDoesNotThrowAndStillDispatches(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $workflow->resume();

        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
        Queue::assertPushed(TestWorkflow::class, 1);
    }

    public function testIsUpdateMethodReturnsTrueForUpdateMethods(): void
    {
        $this->assertTrue(WorkflowStub::isUpdateMethod(TestChatBotWorkflow::class, 'receive'));
    }

    public function testIsUpdateMethodReturnsFalseForNonUpdateMethods(): void
    {
        $this->assertFalse(WorkflowStub::isUpdateMethod(TestChatBotWorkflow::class, 'send'));
        $this->assertFalse(WorkflowStub::isUpdateMethod(TestChatBotWorkflow::class, 'execute'));
        $this->assertFalse(WorkflowStub::isUpdateMethod(TestChatBotWorkflow::class, 'nonexistent'));
    }

    public function testIsUpdateMethodReturnsFalseForClassWithoutUpdateMethods(): void
    {
        $this->assertFalse(WorkflowStub::isUpdateMethod(TestWorkflow::class, 'execute'));
        $this->assertFalse(WorkflowStub::isUpdateMethod(TestWorkflow::class, 'nonexistent'));
    }

    public function testUpdateMethodCreatesSignalAndReturnsQueryResult(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestChatBotWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowWaitingStatus::$name,
        ]);

        $storedWorkflow->signals()
            ->create([
                'method' => 'send',
                'arguments' => Serializer::serialize(['test']),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(null),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 1,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(true),
            ]);

        $result = $workflow->receive();

        $this->assertSame('You said: test', $result);
        $this->assertSame(2, $storedWorkflow->signals()->count());
        $this->assertSame(1, $storedWorkflow->signals()->where('method', 'receive')->count());
    }

    public function testUpdateMethodDispatchesWhenNotFaked(): void
    {
        Queue::fake();

        app()
            ->offsetUnset('workflow.mocks');

        $workflow = WorkflowStub::make(TestChatBotWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $storedWorkflow->signals()
            ->create([
                'method' => 'send',
                'arguments' => Serializer::serialize(['test']),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(null),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 1,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(true),
            ]);

        $result = $workflow->receive();

        $this->assertSame('You said: test', $result);
        $this->assertSame(2, $storedWorkflow->signals()->count());
        $this->assertSame(1, $storedWorkflow->signals()->where('method', 'receive')->count());

        Queue::assertPushed(Signal::class);
    }

    public function testUpdateMethodReplaysStoredSignals(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestChatBotWorkflow::class);
        $workflow->start();

        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowWaitingStatus::$name,
        ]);

        $storedWorkflow->signals()
            ->create([
                'method' => 'send',
                'arguments' => Serializer::serialize(['first']),
            ]);

        $storedWorkflow->signals()
            ->create([
                'method' => 'send',
                'arguments' => Serializer::serialize(['second']),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(null),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 1,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(true),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 2,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(false),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 3,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(null),
            ]);

        $storedWorkflow->logs()
            ->create([
                'index' => 4,
                'now' => now(),
                'class' => Signal::class,
                'result' => Serializer::serialize(true),
            ]);

        $result1 = $workflow->receive();
        $this->assertSame('You said: first', $result1);

        $result2 = $workflow->receive();
        $this->assertSame('You said: second', $result2);
    }

    public function testFailPropagatesFailToContinueParent(): void
    {
        $parentWorkflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedParentWorkflow = StoredWorkflow::findOrFail($parentWorkflow->id());
        $storedParentWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $childStub = WorkflowStub::make(TestWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($childStub->id());
        $storedWorkflow->update([
            'status' => WorkflowPendingStatus::$name,
        ]);

        $storedWorkflow->parents()
            ->attach($storedParentWorkflow, [
                'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                'parent_now' => now(),
            ]);

        $workflow = WorkflowStub::load($childStub->id());
        $workflow->fail(new Exception('continue parent fail'));

        $this->assertTrue($workflow->failed());
        $this->assertTrue($parentWorkflow->fresh()->failed());
    }

    public function testFailContinueParentHandlesTransitionNotFound(): void
    {
        $parentWorkflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedParentWorkflow = StoredWorkflow::findOrFail($parentWorkflow->id());
        $storedParentWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowFailedStatus::$name,
        ]);

        $childStub = WorkflowStub::make(TestWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($childStub->id());
        $storedWorkflow->update([
            'status' => WorkflowPendingStatus::$name,
        ]);

        $storedWorkflow->parents()
            ->attach($storedParentWorkflow, [
                'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                'parent_now' => now(),
            ]);

        $workflow = WorkflowStub::load($childStub->id());
        $workflow->fail(new Exception('continue parent already failed'));

        $this->assertTrue($workflow->failed());
        $this->assertTrue($parentWorkflow->fresh()->failed());
    }

    public function testFailDispatchesExceptionJobForNormalChildParent(): void
    {
        Queue::fake();

        $parentWorkflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedParentWorkflow = StoredWorkflow::findOrFail($parentWorkflow->id());
        $storedParentWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $childStub = WorkflowStub::make(TestWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($childStub->id());
        $storedWorkflow->update([
            'status' => WorkflowPendingStatus::$name,
        ]);

        $storedWorkflow->parents()
            ->attach($storedParentWorkflow, [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);

        $workflow = WorkflowStub::load($childStub->id());
        $workflow->fail(new Exception('child workflow failed'));

        $this->assertTrue($workflow->failed());

        Queue::assertPushed(\Workflow\Exception::class, static function ($job) use ($storedParentWorkflow) {
            return $job->index === 0
                && $job->storedWorkflow->id === $storedParentWorkflow->id
                && $job->exception['class'] === Exception::class
                && $job->exception['message'] === 'child workflow failed';
        });
    }
}
