<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\V2\HistoryPayloadDecodeCounter;
use Workflow\V2\Models\WorkflowHistoryEvent;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkflowHistoryPayloadCastTest extends TestCase
{
    private const RAW = '{ "maximum":9223372036854775807, "null":null, "text":"\\u96ea", "nested":{"value":"original"} }';

    protected function setUp(): void
    {
        parent::setUp();
        HistoryPayloadDecodeCounter::$decodes = 0;
        if (class_exists(Json::class)) {
            Json::decodeUsing(null);
        }
    }

    protected function tearDown(): void
    {
        if (class_exists(Json::class)) {
            Json::decodeUsing(null);
        }
        parent::tearDown();
    }

    public function testRepeatedReadsDecodeOnceWithoutChangingRawBytesOrDirtyState(): void
    {
        $event = $this->event();
        $expected = json_decode(self::RAW, true, 512, JSON_THROW_ON_ERROR);
        for ($read = 0; $read < 100; ++$read) {
            $this->assertSame($expected, $event->payload);
        }

        $this->assertSame(1, HistoryPayloadDecodeCounter::$decodes);
        $this->assertSame(self::RAW, $event->getRawOriginal('payload'));
        $this->assertSame([
            'payload' => self::RAW,
        ], $event->getAttributes());
        $this->assertFalse($event->isDirty());
        $this->assertSame([
            'payload' => $expected,
        ], $event->toArray());
        $this->assertSame([
            'payload' => $expected,
        ], json_decode($event->toJson(), true));
        $this->assertSame(1, HistoryPayloadDecodeCounter::$decodes);
    }

    public function testAssignedPayloadAndReturnedArrayMutationStayIndependent(): void
    {
        $event = $this->event();
        $original = $event->payload;
        $original['nested']['value'] = 'caller mutation';
        $this->assertSame('original', $event->payload['nested']['value']);
        $this->assertSame(1, HistoryPayloadDecodeCounter::$decodes);

        $event->payload = [
            'nested' => [
                'value' => 'replacement',
            ],
            'null' => null,
        ];
        $this->assertSame('replacement', $event->payload['nested']['value']);
        $this->assertNull($event->payload['null']);
        $this->assertSame(2, HistoryPayloadDecodeCounter::$decodes);
        $this->assertTrue($event->isDirty('payload'));
        $this->assertSame(self::RAW, $event->getRawOriginal('payload'));
    }

    public function testRawReplacementAndRestorationEachUseTheCurrentValue(): void
    {
        $event = $this->event();
        $this->assertSame('original', $event->payload['nested']['value']);
        $event->setRawAttributes([
            'payload' => '{"value":"replacement"}',
        ], true);
        $this->assertSame([
            'value' => 'replacement',
        ], $event->payload);
        $this->assertSame([
            'value' => 'replacement',
        ], $event->payload);
        $event->setRawAttributes([
            'payload' => self::RAW,
        ], true);
        $this->assertSame('original', $event->payload['nested']['value']);
        $this->assertSame(3, HistoryPayloadDecodeCounter::$decodes);
        $this->assertFalse($event->isDirty());
    }

    public function testDecodedStateDoesNotChangeSerializationOrOtherInstances(): void
    {
        $event = $this->event();
        $serialized = serialize($event);
        $expected = $event->payload;
        $this->assertSame($serialized, serialize($event));
        $restored = unserialize($serialized);
        $this->assertInstanceOf(WorkflowHistoryEvent::class, $restored);
        $this->assertSame($expected, $restored->payload);
        $other = $this->event();
        $this->assertSame($expected, $other->payload);
        $this->assertSame(3, HistoryPayloadDecodeCounter::$decodes);

        $copy = clone $event;
        $copy->payload = [
            'copy' => true,
        ];
        $this->assertSame([
            'copy' => true,
        ], $copy->payload);
        $this->assertSame($expected, $event->payload);
    }

    public function testChangingThePayloadCastUsesItsNormalBehavior(): void
    {
        $event = $this->event();
        $expected = $event->payload;
        $event->mergeCasts([
            'payload' => 'string',
        ]);
        $this->assertSame(self::RAW, $event->payload);
        $event->mergeCasts([
            'payload' => 'array',
        ]);
        $this->assertSame($expected, $event->payload);
        $this->assertSame($expected, $event->payload);
        $this->assertSame(2, HistoryPayloadDecodeCounter::$decodes);

        $event->mergeCasts([
            'payload' => FreshHistoryPayloadCast::class,
        ]);
        FreshHistoryPayloadCast::$reads = 0;
        $this->assertSame([
            'read' => 1,
        ], $event->payload);
        $this->assertSame([
            'read' => 2,
        ], $event->payload);
        $event->mergeCasts([
            'payload' => 'array',
        ]);
        $this->assertSame($expected, $event->payload);
        $this->assertSame(3, HistoryPayloadDecodeCounter::$decodes);
    }

    public function testCachedPayloadDoesNotKeepTheModelAlive(): void
    {
        $event = $this->event();
        $reference = \WeakReference::create($event);
        $this->assertIsArray($event->payload);
        unset($event);
        gc_collect_cycles();
        $this->assertNull($reference->get());
    }

    #[DataProvider('largePayloads')]
    public function testLargePayloadReadsDoNotRetainDecodedCopies(array $payload): void
    {
        $warmup = $this->event();
        $this->assertIsArray($warmup->payload);
        unset($warmup);
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $models = [];
        for ($row = 0; $row < 16; ++$row) {
            $model = new WorkflowHistoryEvent();
            $model->setRawAttributes([
                'payload' => $raw,
            ], true);
            $models[] = $model;
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        foreach ($models as $model) {
            $decoded = $model->payload;
            $this->assertSame($payload, $decoded);
            unset($decoded);
        }
        gc_collect_cycles();
        $this->assertLessThan(512 * 1024, memory_get_usage() - $before);
        $this->assertCount(16, $models);
    }

    public static function largePayloads(): iterable
    {
        yield 'large string' => [[
            'value' => str_repeat('x', 128 * 1024),
        ]];
        yield 'wide graph with small JSON' => [[
            'values' => array_fill(0, 256, ['x']),
        ]];
    }

    public function testManyIndividuallySmallPayloadsHaveABoundedRetainedCache(): void
    {
        $payload = [
            'values' => array_fill(0, 63, ['x']),
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $models = [];
        for ($index = 0; $index < 768; ++$index) {
            $model = new WorkflowHistoryEvent();
            $model->setRawAttributes([
                'payload' => $raw,
            ], true);
            $models[] = $model;
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        foreach ($models as $model) {
            $this->assertSame($payload, $model->payload);
        }
        gc_collect_cycles();
        $this->assertLessThan(6 * 1024 * 1024, memory_get_usage() - $before);
        $decoded = HistoryPayloadDecodeCounter::$decodes;
        $this->assertSame($payload, $models[767]->payload);
        $this->assertSame($decoded, HistoryPayloadDecodeCounter::$decodes, 'A current entry still decodes once.');
        $this->assertSame($payload, $models[0]->payload);
        $this->assertSame($decoded + 1, HistoryPayloadDecodeCounter::$decodes, 'An evicted entry decodes normally.');
    }

    public function testConfiguredSubclassKeepsItsCustomCastPath(): void
    {
        $event = new ConfiguredPayloadHistoryEvent();
        $event->setRawAttributes([
            'payload' => self::RAW,
        ], true);
        $this->assertTrue($event->payload['configured']);
        $this->assertTrue($event->payload['configured']);
        $this->assertSame(2, HistoryPayloadDecodeCounter::$decodes);
        $this->assertSame(2, $event->payloadReads);
    }

    public function testCustomGlobalDecoderIsStillInvokedForEveryRead(): void
    {
        if (! class_exists(Json::class)) {
            $this->markTestSkipped('Laravel 9 has no configurable JSON decoder API.');
        }

        $event = $this->event();
        $expected = $event->payload;
        $calls = 0;
        Json::decodeUsing(static function () use (&$calls): array {
            return [
                'call' => ++$calls,
            ];
        });
        $this->assertSame([
            'call' => 1,
        ], $event->payload);
        $this->assertSame([
            'call' => 2,
        ], $event->payload);
        Json::decodeUsing(null);
        $this->assertSame($expected, $event->payload);
        $this->assertSame(2, HistoryPayloadDecodeCounter::$decodes);
    }

    public function testMissingPayloadKeepsTheFrameworkStrictAttributeBehavior(): void
    {
        $event = $this->event();
        $this->assertIsArray($event->payload);
        $event->setRawAttributes([
            'id' => 'history-without-payload',
        ], true);
        $event->exists = true;
        $uncached = new class() extends WorkflowHistoryEvent {
        };
        $uncached->setRawAttributes($event->getAttributes(), true);
        $uncached->exists = true;
        $previous = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        try {
            $read = static function (WorkflowHistoryEvent $model): array {
                try {
                    return [
                        'value' => $model->getAttribute('payload'),
                    ];
                } catch (MissingAttributeException $exception) {
                    return [
                        'exception' => $exception::class,
                    ];
                }
            };
            // Laravel 9 permits missing primitive casts; newer majors throw.
            $this->assertTrue(Model::preventsAccessingMissingAttributes());
            $this->assertSame($read($uncached), $read($event));
        } finally {
            Model::preventAccessingMissingAttributes($previous);
        }
    }

    #[DataProvider('nonArrayPayloads')]
    public function testSparseAndMalformedPayloadsKeepTheNativeCastResult(?string $raw, mixed $expected): void
    {
        $event = new WorkflowHistoryEvent();
        $event->setRawAttributes([
            'payload' => $raw,
        ], true);
        $this->assertSame($expected, $event->payload);
        $this->assertSame($expected, $event->payload);
        $this->assertSame($raw, $event->getRawOriginal('payload'));
    }

    public static function nonArrayPayloads(): iterable
    {
        yield 'SQL null' => [null, null];
        yield 'empty bytes' => ['', null];
        yield 'JSON null' => ['null', null];
        yield 'invalid JSON' => ['{broken', null];
        yield 'scalar' => ['17', 17];
        yield 'empty list' => ['[]', []];
    }

    public function testInvalidJsonStillUpdatesTheNativeDecoderError(): void
    {
        $event = $this->event();
        $this->assertIsArray($event->payload);
        $event->setRawAttributes([
            'payload' => '{broken',
        ], true);
        $this->assertNull($event->payload);
        json_decode('{}', true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertNull($event->payload);
        $this->assertSame(JSON_ERROR_SYNTAX, json_last_error());
    }

    public function testFailedCustomDecodesNeverPublishAReusableValue(): void
    {
        if (! class_exists(Json::class)) {
            $this->markTestSkipped('Laravel 9 has no configurable JSON decoder API.');
        }

        $event = $this->event();
        Json::decodeUsing(static function (): never {
            ++HistoryPayloadDecodeCounter::$decodes;
            throw new RuntimeException('decoder rejected payload');
        });
        for ($read = 0; $read < 2; ++$read) {
            try {
                $event->getAttribute('payload');
                $this->fail('The original decoder refusal must be preserved.');
            } catch (RuntimeException $exception) {
                $this->assertSame('decoder rejected payload', $exception->getMessage());
            }
        }
        $this->assertSame(2, HistoryPayloadDecodeCounter::$decodes);
    }

    private function event(): WorkflowHistoryEvent
    {
        $event = new WorkflowHistoryEvent();
        $event->setRawAttributes([
            'payload' => self::RAW,
        ], true);

        return $event;
    }
}

final class ConfiguredPayloadHistoryEvent extends WorkflowHistoryEvent
{
    public int $payloadReads = 0;

    protected function castAttribute($key, $value)
    {
        $payload = parent::castAttribute($key, $value);
        if ($key === 'payload') {
            ++$this->payloadReads;
            $payload['configured'] = true;
        }

        return $payload;
    }
}

final class FreshHistoryPayloadCast implements CastsAttributes
{
    public static int $reads = 0;

    public function get($model, string $key, $value, array $attributes): array
    {
        return [
            'read' => ++self::$reads,
        ];
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
