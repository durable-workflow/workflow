<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Orchestra\Testbench\TestCase;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CacheLongPollWakeStore;

final class CacheLongPollWakeStoreTest extends TestCase
{
    public function testSnapshotsNormalizeChannelsAndChangeOnlyAfterTheirSignal(): void
    {
        $store = $this->store();
        $snapshot = $store->snapshot([' orders ', 'orders', '', null]);

        $this->assertSame([
            'orders' => null,
        ], $snapshot);
        $store->signal('', 'other');
        $this->assertFalse($store->changed($snapshot));

        $store->signal(' orders ', 'orders');
        $this->assertTrue($store->changed($snapshot));
        $this->assertFalse($store->changed($store->snapshot(['orders'])));
    }

    public function testWorkflowAndActivitySignalsWakeMatchingNamespaceAndSharedQueuePolls(): void
    {
        foreach ([TaskType::Workflow, TaskType::Activity] as $type) {
            $store = $this->store();
            $pollChannels = $type === TaskType::Workflow
                ? $store->workflowTaskPollChannels('alpha', 'redis', 'orders')
                : $store->activityTaskPollChannels('alpha', 'redis', 'orders');
            $otherChannels = $type === TaskType::Workflow
                ? $store->workflowTaskPollChannels('beta', 'redis', 'orders')
                : $store->activityTaskPollChannels('beta', 'redis', 'orders');
            $genericChannels = $type === TaskType::Workflow
                ? $store->workflowTaskPollChannels('alpha', null, 'orders')
                : $store->activityTaskPollChannels('alpha', null, 'orders');
            $unrelatedChannels = $type === TaskType::Workflow
                ? $store->activityTaskPollChannels('alpha', 'redis', 'orders')
                : $store->workflowTaskPollChannels('alpha', 'redis', 'orders');

            $matching = $store->snapshot($pollChannels);
            $matchingNamespace = $store->snapshot(array_values(array_filter(
                $pollChannels,
                static fn (string $channel): bool => str_contains($channel, ':namespace:alpha:'),
            )));
            $otherNamespace = $store->snapshot(array_values(array_filter(
                $otherChannels,
                static fn (string $channel): bool => str_contains($channel, ':namespace:beta:'),
            )));
            $generic = $store->snapshot($genericChannels);
            $unrelated = $store->snapshot($unrelatedChannels);

            $task = new WorkflowTask();
            $task->task_type = $type;
            $task->namespace = 'alpha';
            $task->connection = 'redis';
            $task->queue = 'orders';
            $store->signalTask($task);

            $this->assertTrue($store->changed($matching));
            $this->assertTrue($store->changed($matchingNamespace));
            $this->assertTrue($store->changed($generic));
            $this->assertFalse($store->changed($otherNamespace));
            $this->assertFalse($store->changed($unrelated));
        }
    }

    public function testHistorySignalRequiresRunIdAndTimerTaskDoesNotWakeQueuePolls(): void
    {
        $store = $this->store();
        $history = $store->snapshot([$store->historyRunChannel('run-1')]);
        $event = new WorkflowHistoryEvent();
        $store->signalHistoryEvent($event);
        $this->assertFalse($store->changed($history));

        $event->workflow_run_id = 'run-1';
        $store->signalHistoryEvent($event);
        $this->assertTrue($store->changed($history));

        $polls = $store->snapshot($store->workflowTaskPollChannels('alpha', 'redis', 'orders'));
        $task = new WorkflowTask();
        $task->task_type = TaskType::Timer;
        $task->namespace = 'alpha';
        $task->connection = 'redis';
        $task->queue = 'orders';
        $store->signalTask($task);
        $this->assertFalse($store->changed($polls));
    }

    private function store(): CacheLongPollWakeStore
    {
        return new CacheLongPollWakeStore(new Repository(new ArrayStore()));
    }
}
