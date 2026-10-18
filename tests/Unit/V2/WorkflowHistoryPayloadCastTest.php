<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Models\WorkflowHistoryEvent;

final class WorkflowHistoryPayloadCastTest extends TestCase
{
    private const RAW = '{ "maximum":9223372036854775807, "null":null, "text":"\\u96ea", "nested":{"value":"original"} }';

    private int $decodes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Json::decodeUsing(function (mixed $value, bool $associative = true): mixed {
            ++$this->decodes;

            return json_decode($value, $associative);
        });
    }

    protected function tearDown(): void
    {
        Json::decodeUsing(null);
        parent::tearDown();
    }

    public function testRepeatedReadsDecodeOnceWithoutChangingRawBytesOrDirtyState(): void
    {
        $event = $this->event();
        $expected = json_decode(self::RAW, true, 512, JSON_THROW_ON_ERROR);
        for ($read = 0; $read < 100; ++$read) {
            $this->assertSame($expected, $event->payload);
        }

        $this->assertSame(1, $this->decodes);
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
        $this->assertSame(1, $this->decodes);
    }

    public function testAssignedPayloadAndReturnedArrayMutationStayIndependent(): void
    {
        $event = $this->event();
        $original = $event->payload;
        $original['nested']['value'] = 'caller mutation';
        $this->assertSame('original', $event->payload['nested']['value']);
        $this->assertSame(1, $this->decodes);

        $event->payload = [
            'nested' => [
                'value' => 'replacement',
            ],
            'null' => null,
        ];
        $this->assertSame('replacement', $event->payload['nested']['value']);
        $this->assertNull($event->payload['null']);
        $this->assertSame(2, $this->decodes);
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
        $this->assertSame(3, $this->decodes);
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
        $this->assertSame(3, $this->decodes);

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
        $this->assertSame(2, $this->decodes);

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
        $this->assertSame(3, $this->decodes);
    }

    public function testConfiguredSubclassKeepsItsCustomCastPath(): void
    {
        $event = new ConfiguredPayloadHistoryEvent();
        $event->setRawAttributes([
            'payload' => self::RAW,
        ], true);
        $this->assertTrue($event->payload['configured']);
        $this->assertTrue($event->payload['configured']);
        $this->assertSame(2, $this->decodes);
        $this->assertSame(2, $event->payloadReads);
    }

    public function testCustomGlobalDecoderIsStillInvokedForEveryRead(): void
    {
        $event = $this->event();
        $calls = 0;
        Json::decodeUsing(static function () use (&$calls): array {
            return ['call' => ++$calls];
        });
        $this->assertSame(['call' => 1], $event->payload);
        $this->assertSame(['call' => 2], $event->payload);
    }

    public function testMissingPayloadStillRefusesUnderStrictAttributeAccess(): void
    {
        $event = $this->event();
        $this->assertIsArray($event->payload);
        $event->setRawAttributes([
            'id' => 'history-without-payload',
        ], true);
        $event->exists = true;
        $previous = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        try {
            $this->expectException(MissingAttributeException::class);
            $event->getAttribute('payload');
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

    public function testFailedCustomDecodesNeverPublishAReusableValue(): void
    {
        $event = $this->event();
        Json::decodeUsing(function (): never {
            ++$this->decodes;
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
        $this->assertSame(2, $this->decodes);
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

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [
            'read' => ++self::$reads,
        ];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
