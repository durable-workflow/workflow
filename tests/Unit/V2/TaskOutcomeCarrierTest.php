<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use JsonSerializable;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Support\TaskOutcomeCarrier;

final class TaskOutcomeCarrierTest extends TestCase
{
    public function testDuplicateKeepsTheFirstStoredBytesAndContext(): void
    {
        $task = self::task();
        TaskOutcomeCarrier::append($task, [self::entry()]);
        $stored = json_encode(TaskOutcomeCarrier::fromTask($task), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $task->setRawAttributes(array_replace($task->getAttributes(), [
            'observability_context' => $stored,
        ]), true);
        $other = self::entry();
        $other['context']['carrier']['traceparent'] = '00-11111111111111111111111111111111-2222222222222222-01';
        TaskOutcomeCarrier::append($task, [$other]);
        self::assertSame($stored, $task->getAttributes()['observability_context']);
        self::assertSame(self::entry(), TaskOutcomeCarrier::fromTask($task)['outcomes'][0]);
        self::assertArrayNotHasKey('observability_context', $task->toArray());
    }

    public function testBoundedAppendRetainsCompleteEarlyEntries(): void
    {
        $task = self::task();
        $entries = [];
        for ($sequence = 1; $sequence <= 60; ++$sequence) {
            $entries[] = array_replace(self::entry(), [
                'event_id' => 'event-' . $sequence,
                'sequence' => $sequence,
            ]);
        }
        TaskOutcomeCarrier::append($task, $entries);
        $stored = TaskOutcomeCarrier::fromTask($task);
        self::assertNotEmpty($stored['outcomes']);
        self::assertLessThanOrEqual(32, count($stored['outcomes']));
        self::assertLessThanOrEqual(4096, strlen($task->getAttributes()['observability_context']));
        self::assertSame(array_slice($entries, 0, count($stored['outcomes'])), $stored['outcomes']);
    }

    public function testLegacyAndMalformedStorageRemainUnchanged(): void
    {
        $legacy = self::task();
        $attributes = $legacy->getAttributes();
        unset($attributes['observability_context']);
        $legacy->setRawAttributes($attributes, true);
        TaskOutcomeCarrier::append($legacy, [self::entry()]);
        self::assertSame($attributes, $legacy->getAttributes());
        $bad = self::task('broken{');
        self::assertNull(TaskOutcomeCarrier::fromTask($bad));
        TaskOutcomeCarrier::append($bad, [self::entry()]);
        self::assertSame('broken{', $bad->getAttributes()['observability_context']);
    }

    public static function invalidEntries(): iterable
    {
        yield 'missing' => [null];
        foreach ([
            'event_id' => '',
            'sequence' => '1',
            'event_type' => 'Other',
            'activity_attempt_id' => null,
        ] as $key => $value) {
            yield $key => [
                array_replace(self::entry(), [
                    $key => $value,
                ])];
        }
        yield 'payload' => [
            self::entry() + [
                'payload' => 'must-not-store',
            ]];
        $baggage = self::entry();
        $baggage['context']['carrier']['baggage'] = 'private=discard';
        yield 'baggage' => [$baggage];
        $invalid = self::entry();
        $invalid['context']['carrier']['traceparent'] = 'invalid';
        yield 'traceparent' => [$invalid];
        yield 'timer identity on activity' => [
            self::entry() + [
                'timer_id' => 'other',
            ]];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesNeverBecomeStoredOutcomes(mixed $entry): void
    {
        self::assertNull(TaskOutcomeCarrier::entry($entry));
        $task = self::task();
        TaskOutcomeCarrier::append($task, [$entry]);
        self::assertNull($task->getAttributes()['observability_context']);
    }

    public function testNamespaceRunAndTaskBindingsCannotBeReinterpreted(): void
    {
        $task = self::task();
        TaskOutcomeCarrier::append($task, [self::entry()]);
        $original = TaskOutcomeCarrier::fromTask($task);
        foreach (['namespace', 'run_id', 'task_id', 'schema', 'version'] as $field) {
            $wrong = array_replace($original, [
                $field => 'other',
            ]);
            $task->setRawAttributes(array_replace($task->getAttributes(), [
                'observability_context' => json_encode($wrong, JSON_THROW_ON_ERROR),
            ]), true);
            self::assertNull(TaskOutcomeCarrier::fromTask($task));
        }
        $event = new WorkflowHistoryEvent();
        $event->setRawAttributes([
            'id' => 'event-1',
            'sequence' => 1,
            'event_type' => 'ActivityCompleted',
            'payload' => json_encode([
                'activity_execution_id' => 'activity-1',
                'activity_attempt_id' => 'attempt-1',
            ]),
        ], true);
        self::assertTrue(TaskOutcomeCarrier::matchesEvent(self::entry(), $event));
        self::assertFalse(TaskOutcomeCarrier::matchesEvent(array_replace(self::entry(), [
            'sequence' => 2,
        ]), $event));
        self::assertFalse(TaskOutcomeCarrier::matchesEvent(array_replace(self::entry(), [
            'activity_attempt_id' => 'other',
        ]), $event));
    }

    public function testInspectionNeverExecutesObjectSerialization(): void
    {
        $object = new class() implements JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return 'must-not-execute';
            }
        };
        $entry = self::entry();
        $entry['context']['carrier']['traceparent'] = $object;
        self::assertNull(TaskOutcomeCarrier::entry($entry));
        self::assertNull(TaskOutcomeCarrier::entry(self::entry() + [
            'payload' => $object,
        ]));
        self::assertSame(0, $object->calls);
    }

    public static function childProofs(): iterable
    {
        yield 'canonical proof' => ['valid'];
        yield 'different parent run' => ['parent'];
        yield 'different original link' => ['link'];
        yield 'different physical task' => ['task'];
        yield 'different child namespace' => ['namespace'];
        yield 'missing child context' => ['missing'];
    }

    #[DataProvider('childProofs')]
    public function testChildLinkRequiresTheOriginalProofWithoutQueryingOrChangingTheOperation(string $proof): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $configuration = Configurator::create()->withTracerProvider($provider)->activate();
        try {
            $source = $provider->getTracer('fixture')
                ->spanBuilder('child completion')
                ->startSpan();
            $context = TaskOutcomeCarrier::capture($source);
            $source->end();
            $terminal = new WorkflowHistoryEvent();
            $terminal->setRawAttributes([
                'id' => 'terminal',
                'workflow_run_id' => 'child',
                'workflow_task_id' => 'child-task',
                'event_type' => 'WorkflowCompleted',
                'sequence' => 3,
                'payload' => '{}',
            ], true);
            $child = new WorkflowRun();
            $child->setConnection('unavailable-observation-source');
            $child->setRawAttributes([
                'id' => 'child',
                'workflow_instance_id' => 'child-instance',
                'namespace' => '0',
            ], true);
            $child->setRelation('historyEvents', new Collection([$terminal]));
            $resolution = new WorkflowHistoryEvent();
            $resolution->setRawAttributes([
                'id' => 'resolution',
                'workflow_run_id' => 'parent',
                'event_type' => 'ChildRunCompleted',
                'payload' => json_encode([
                    'workflow_link_id' => $proof === 'link' ? 'other' : 'link',
                    'sequence' => 1,
                    'child_workflow_run_id' => 'child',
                    'child_workflow_instance_id' => 'child-instance',
                ], JSON_THROW_ON_ERROR),
            ], true);
            $entry = [
                'event_id' => 'terminal',
                'sequence' => 3,
                'event_type' => 'WorkflowCompleted',
                'context' => $context,
                'task_id' => 'child-task',
                'child_run_id' => 'child',
                'child_instance_id' => 'child-instance',
                'child_namespace' => '0',
                'parent_run_id' => 'parent',
                'parent_namespace' => '0',
                'parent_sequence' => 1,
                'link_id' => 'link',
            ];
            if ($proof === 'parent') {
                $resolution->workflow_run_id = 'other';
            } elseif ($proof === 'task') {
                $terminal->workflow_task_id = 'other';
            } elseif ($proof === 'namespace') {
                $child->namespace = 'other';
            }
            $calls = 0;
            $result = ExecutionTracing::workflow([
                'workflow_run_id' => 'parent',
                'workflow_instance_id' => 'parent-instance',
                'namespace' => '0',
                'task_id' => 'parent-task',
            ], null, static function () use ($resolution, $child, &$calls): array {
                ++$calls;
                ExecutionTracing::linkChildOutcome($resolution, $child);
                return [9007199254740993, null, '雪'];
            }, enabled: true, loadOutcomes: static fn (): array => $proof === 'missing' ? [] : [
                'child:child' => $entry,
            ]);
            self::assertSame([9007199254740993, null, '雪'], $result);
            self::assertSame(1, $calls);
            self::assertSame([], ExecutionTracing::current());
            $spans = array_values(array_filter($exporter->getSpans(), static fn ($span): bool =>
                $span->getName() === 'durable.workflow.task'));
            self::assertCount(1, $spans);
            $links = $spans[0]->getLinks();
            self::assertCount($proof === 'valid' ? 1 : 0, $links);
            self::assertSame(
                ! in_array($proof, ['valid', 'parent'], true),
                $spans[0]->getAttributes()->toArray()['durable.links.outcome_context_unavailable'] ?? false
            );
            if ($proof === 'valid') {
                self::assertSame($source->getContext()->getTraceId(), $links[0]->getSpanContext()->getTraceId());
                self::assertSame($source->getContext()->getSpanId(), $links[0]->getSpanContext()->getSpanId());
            }
        } finally {
            $configuration->detach();
            $provider->shutdown();
        }
    }

    public static function outcomeAvailability(): array
    {
        return [
            'prior outcome' => ['prior'],
            'current task' => ['current'],
            'other run' => ['foreign'],
        ];
    }

    #[DataProvider('outcomeAvailability')]
    public function testMissingPriorContextMarksOnlyItsOwnRecordingRun(string $kind): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $configuration = Configurator::create()->withTracerProvider($provider)->activate();
        try {
            $run = new WorkflowRun();
            $run->setRawAttributes([
                'id' => 'parent',
                'namespace' => '0',
            ], true);
            $event = new WorkflowHistoryEvent();
            $event->setRawAttributes([
                'id' => 'outcome',
                'workflow_run_id' => $kind === 'foreign' ? 'other' : 'parent',
                'workflow_task_id' => $kind === 'current' ? 'processing' : 'prior',
                'event_type' => 'ActivityCompleted',
                'sequence' => 3,
                'payload' => '{}',
            ], true);
            $calls = 0;
            $result = ExecutionTracing::workflow([
                'workflow_run_id' => 'parent',
                'namespace' => '0',
                'task_id' => 'processing',
            ], null, static function () use ($run, $event, &$calls): array {
                ++$calls;
                ExecutionTracing::linkOutcome($run, $event);
                return [9007199254740993, null, '雪'];
            }, enabled: true);
            self::assertSame([9007199254740993, null, '雪'], $result);
            self::assertSame(1, $calls);
            self::assertSame([], ExecutionTracing::current());
            $spans = $exporter->getSpans();
            self::assertCount(1, $spans);
            self::assertSame(
                $kind === 'prior',
                $spans[0]->getAttributes()->toArray()['durable.links.outcome_context_unavailable'] ?? false
            );
            self::assertSame([], $spans[0]->getLinks());
        } finally {
            $configuration->detach();
            $provider->shutdown();
        }
    }

    private static function task(mixed $context = null): WorkflowTask
    {
        $task = new WorkflowTask();
        $task->setRawAttributes([
            'id' => 'task-1',
            'workflow_run_id' => 'run-1',
            'namespace' => '0',
            'observability_context' => $context,
        ], true);
        return $task;
    }

    private static function entry(): array
    {
        return [
            'event_id' => 'event-1',
            'sequence' => 1,
            'event_type' => 'ActivityCompleted',
            'activity_id' => 'activity-1',
            'activity_attempt_id' => 'attempt-1',
            'context' => [
                'schema' => 'durable-workflow.trace-context',
                'version' => 1,
                'carrier' => [
                    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                ],
            ],
        ];
    }
}
