<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Exceptions\WorkflowPayloadDecodeException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowExecutor;

/**
 * signalValue() must decode the serialized signal payload using the run's
 * pinned payload_codec and must not infer a codec from untagged bytes.
 */
final class SignalValueCodecTest extends TestCase
{
    public function testSignalValueDecodesAvroEncodedPayloadWithRunCodec(): void
    {
        $value = [
            'approved' => true,
            'source' => 'waterline',
        ];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => Serializer::serializeWithCodec('avro', $value),
        ];

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';

        $this->assertSame($value, $this->invokeSignalValue($event, $run));
    }

    public function testSignalValueRejectsJsonTaggedPayloadWithRunCodec(): void
    {
        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => '{"count":3}',
        ];

        $run = new WorkflowRun();
        $run->payload_codec = 'json';

        $this->expectException(WorkflowPayloadDecodeException::class);
        $this->expectExceptionMessage('unsupported_payload_codec');

        $this->invokeSignalValue($event, $run);
    }

    public function testSignalValueDefaultsMissingRunCodecToAvro(): void
    {
        $value = [
            'approved' => true,
        ];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => Serializer::serializeWithCodec('avro', $value),
        ];

        $this->assertSame($value, $this->invokeSignalValue($event, null));
    }

    public function testSignalValueDoesNotSniffUntaggedJsonWhenRunCodecUnavailable(): void
    {
        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => '{"legacy":"payload"}',
        ];

        $this->expectException(WorkflowPayloadDecodeException::class);

        $this->invokeSignalValue($event, null);
    }

    public function testSignalValueReturnsNullForMissingSerializedValue(): void
    {
        $event = new WorkflowHistoryEvent();
        $event->payload = [];

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';

        $this->assertNull($this->invokeSignalValue($event, $run));
    }

    protected function getPackageProviders($app)
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }

    private function invokeSignalValue(WorkflowHistoryEvent $event, ?WorkflowRun $run): mixed
    {
        $executor = new WorkflowExecutor();
        $method = new ReflectionMethod($executor, 'signalValue');
        $method->setAccessible(true);

        return $method->invoke($executor, $event, $run);
    }
}
