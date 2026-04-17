<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowExecutor;

/**
 * #331 regression: signalValue() must decode the serialized signal payload
 * using the run's pinned payload_codec. Previously it called the codec-blind
 * Serializer::unserialize(), which would silently mis-decode an Avro-encoded
 * signal as JSON (yielding a base64 blob or a RuntimeException) on Avro-pinned
 * runs. The fix is to thread the run through signalValue() and delegate to
 * unserializePayloadWithRun().
 */
final class SignalValueCodecTest extends TestCase
{
    public function testSignalValueDecodesAvroEncodedPayloadWithRunCodec(): void
    {
        $value = ['approved' => true, 'source' => 'waterline'];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => Serializer::serializeWithCodec('avro', $value),
        ];

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';

        $this->assertSame($value, $this->invokeSignalValue($event, $run));
    }

    public function testSignalValueDecodesJsonEncodedPayloadWithRunCodec(): void
    {
        $value = ['count' => 3];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => Serializer::serializeWithCodec('json', $value),
        ];

        $run = new WorkflowRun();
        $run->payload_codec = 'json';

        $this->assertSame($value, $this->invokeSignalValue($event, $run));
    }

    public function testSignalValueFallsBackToCodecBlindWhenRunCodecUnavailable(): void
    {
        $value = ['legacy' => 'payload'];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => Serializer::serializeWithCodec('json', $value),
        ];

        // Legacy rows written before payload_codec was populated must still
        // round-trip via the codec-blind sniffer path.
        $this->assertSame($value, $this->invokeSignalValue($event, null));
    }

    public function testSignalValueReturnsNullForMissingSerializedValue(): void
    {
        $event = new WorkflowHistoryEvent();
        $event->payload = [];

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';

        $this->assertNull($this->invokeSignalValue($event, $run));
    }

    private function invokeSignalValue(WorkflowHistoryEvent $event, ?WorkflowRun $run): mixed
    {
        $executor = new WorkflowExecutor();
        $method = new ReflectionMethod($executor, 'signalValue');
        $method->setAccessible(true);

        return $method->invoke($executor, $event, $run);
    }

    protected function getPackageProviders($app)
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }
}
