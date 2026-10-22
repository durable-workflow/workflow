<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class LegacyOverlapOptionsBoundaryTest extends TestCase
{
    public static function entryPoints(): array
    {
        return [
            'start' => ['start'],
            'signal with start' => ['signal'],
            'child start' => ['child'],
        ];
    }

    #[DataProvider('entryPoints')]
    public function testLegacyDelayDoesNotChangeV2ArgumentsRoutingOrPortableShape(string $entry): void
    {
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);
        $seen = [];
        $snapshots = [];
        foreach ([0, 5] as $delay) {
            $workflow = WorkflowStub::make(
                $entry === 'child' ? LegacyOverlapBoundaryParent::class : LegacyOverlapBoundaryWorkflow::class,
            );
            $options = new WorkflowOptions('redis', 'legacy-boundary', $delay);
            if ($entry === 'signal') {
                $this->assertTrue(
                    $workflow->signalWithStart('poke', ['signal-value'], 'argument', $options)->accepted()
                );
            } elseif ($entry === 'child') {
                $workflow->start('argument', $delay, $options);
                $parentTask = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->sole();
                $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->execute($parentTask->id)['executed']);
            } else {
                $workflow->start('argument', $options);
            }
            $run = WorkflowRun::query()->where('workflow_class', LegacyOverlapBoundaryWorkflow::class)
                ->whereNotIn('id', $seen)
                ->sole();
            $seen[] = $run->id;
            $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
            $this->assertSame(['argument'], Serializer::unserializeWithCodec($run->payload_codec, $run->arguments));
            $this->assertSame('redis', $run->connection);
            $this->assertSame('legacy-boundary', $run->queue);
            $this->assertSame($run->connection, $task->connection);
            $this->assertSame($run->queue, $task->queue);
            $history = $run->historyEvents()
                ->orderBy('sequence')
                ->get();
            $portable = [$task->payload, ...$history->pluck('payload')->all()];
            $this->assertStringNotContainsString('overlapReleaseAfter', json_encode($portable, JSON_THROW_ON_ERROR));
            $snapshots[] = [
                'task' => $this->shape($task->getAttributes()),
                'payload' => $this->shape($task->payload),
                'history' => $history->map(fn ($event): array => [
                    $event->event_type->value,
                    $this->shape($event->payload),
                ])->all(),
            ];
            $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->execute($task->id)['executed']);
            $this->assertSame('argument', WorkflowStub::load($run->workflow_instance_id)->output());
        }
        $this->assertSame($snapshots[0], $snapshots[1]);
    }

    private function shape(mixed $value): mixed
    {
        return is_array($value) ? array_map($this->shape(...), $value) : get_debug_type($value);
    }
}

#[Type('legacy-overlap-boundary')]
#[Signal('poke')]
final class LegacyOverlapBoundaryWorkflow extends Workflow
{
    public function handle(string $argument): string
    {
        return $argument;
    }
}

#[Type('legacy-overlap-boundary-parent')]
final class LegacyOverlapBoundaryParent extends Workflow
{
    public function handle(string $argument, int $delay): mixed
    {
        return self::child(
            LegacyOverlapBoundaryWorkflow::class,
            $argument,
            new WorkflowOptions('redis', 'legacy-boundary', $delay),
        );
    }
}
