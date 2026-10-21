<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use JsonSerializable;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Support\TaskTraceCarrier;

final class TaskTraceCarrierTest extends TestCase
{
    private const PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function testCaptureUsesTheActivePropagatorButNeverQueuesBaggage(): void
    {
        $initial = Context::getCurrent();
        $configuration = Configurator::create()->withPropagator(new MultiTextMapPropagator([
            TraceContextPropagator::getInstance(), BaggagePropagator::getInstance(),
        ]))->activate();
        $parent = TraceContextPropagator::getInstance()->extract([
            'traceparent' => self::PARENT,
            'tracestate' => 'vendor=opaque',
        ], context: Context::getCurrent());
        $scope = Baggage::getBuilder()->set('private', 'must-not-queue')->build()->storeInContext($parent)->activate();
        try {
            $carrier = TaskTraceCarrier::capture('task-1');
            self::assertSame(self::envelope(), $carrier);
            self::assertSame(self::envelope()['context'], TaskTraceCarrier::fromJob($carrier, 'task-1'));
            self::assertLessThanOrEqual(4096, strlen(json_encode($carrier, JSON_THROW_ON_ERROR)));
            self::assertSame('must-not-queue', Baggage::getCurrent()->getValue('private'));
        } finally {
            $scope->detach();
            $configuration->detach();
        }
        self::assertSame($initial, Context::getCurrent());
    }

    public static function invalidCarriers(): iterable
    {
        yield 'missing' => [null, 'task-1'];
        yield 'scalar' => ['invalid', 'task-1'];
        yield 'empty' => [[], 'task-1'];
        foreach ([
            'schema' => 'other',
            'version' => '1',
            'task_id' => 'other-task',
            'context' => null,
        ] as $key => $value) {
            yield $key => [array_replace(self::envelope(), [
                $key => $value,
            ]), 'task-1'];
        }
        yield 'extra field' => [self::envelope() + [
            'extra' => 'private',
        ], 'task-1'];
        yield 'wrong task type' => [array_replace(self::envelope(), [
            'task_id' => 1,
        ]), 'task-1'];
        yield 'invalid expected task' => [self::envelope(), ''];
        yield 'oversized task' => [array_replace(self::envelope(), [
            'task_id' => str_repeat('x', 4097),
        ]), str_repeat('x', 4097)];
        foreach ([
            'invalid parent' => [
                'traceparent' => 'invalid',
            ],
            'oversized parent' => [
                'traceparent' => str_repeat('x', 257),
            ],
            'oversized state' => [
                'traceparent' => self::PARENT,
                'tracestate' => str_repeat('x', 513),
            ],
            'baggage' => [
                'traceparent' => self::PARENT,
                'baggage' => 'private=discard',
            ],
        ] as $name => $headers) {
            $envelope = self::envelope();
            $envelope['context']['carrier'] = $headers;
            yield $name => [$envelope, 'task-1'];
        }
    }

    #[DataProvider('invalidCarriers')]
    public function testInvalidMetadataSuppliesNoContext(mixed $value, string $taskId): void
    {
        $diagnostic = 'stale';
        self::assertNull(TaskTraceCarrier::fromJob($value, $taskId, $diagnostic));
        self::assertSame($value === null ? null : 'discarded', $diagnostic);
    }

    public function testMetadataInspectionDoesNotExecuteUserObjectSerialization(): void
    {
        $object = new class() implements JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;

                return 'must-not-execute';
            }
        };
        self::assertNull(TaskTraceCarrier::fromJob($object, 'task-1'));
        $envelope = self::envelope();
        $envelope['context']['carrier']['traceparent'] = $object;
        self::assertNull(TaskTraceCarrier::fromJob($envelope, 'task-1'));
        self::assertSame(0, $object->calls);
    }

    public function testPublishedSerializedJobsLoadAndDefaultNullAddsNoWireBytes(): void
    {
        // Constructor-only captures from the released image; these are not hand-authored objects.
        $fixture = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/V2/tracing-legacy-queue-jobs.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $allowed = [RunWorkflowTask::class, RunActivityTask::class, RunTimerTask::class];
        foreach ($fixture['jobs'] as $index => $original) {
            $job = unserialize($original['serialized'], [
                'allowed_classes' => $allowed,
            ]);
            self::assertInstanceOf($original['class'], $job);
            self::assertSame('legacy-task-' . $index, $job->taskId);
            self::assertTrue($job->afterCommit);
            self::assertTrue(property_exists($job, 'dispatchObservabilityContext'));
            self::assertNull($job->dispatchObservabilityContext);
            self::assertSame($original['serialized'], serialize($job));
            self::assertSame($original['serialized'], serialize(new ($original['class'])($job->taskId)));
            $job->dispatchObservabilityContext = 'malformed';
            $restored = unserialize(serialize($job), [
                'allowed_classes' => $allowed,
            ]);
            self::assertSame($job->taskId, $restored->taskId);
            self::assertTrue($restored->afterCommit);
            self::assertSame('malformed', $restored->dispatchObservabilityContext);
            self::assertNull(TaskTraceCarrier::fromJob($restored->dispatchObservabilityContext, $restored->taskId));
        }
    }

    private static function envelope(): array
    {
        return [
            'schema' => 'durable-workflow.queue-trace',
            'version' => 1,
            'task_id' => 'task-1',
            'context' => [
                'schema' => 'durable-workflow.trace-context',
                'version' => 1,
                'carrier' => [
                    'traceparent' => self::PARENT,
                    'tracestate' => 'vendor=opaque',
                ],
            ],
        ];
    }
}
