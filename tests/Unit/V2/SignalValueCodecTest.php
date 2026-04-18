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
 * Serializer::unserialize(), which cannot decode binary Avro by sniffing and
 * can misread legacy PHP codec payloads. The fix is to thread the run through
 * signalValue() and delegate to unserializePayloadWithRun().
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

    public function testSignalValueDecodesLegacyPhpEncodedPayloadWithRunCodec(): void
    {
        $value = [
            'count' => 3,
        ];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => Serializer::serializeWithCodec('workflow-serializer-y', $value),
        ];

        $run = new WorkflowRun();
        $run->payload_codec = 'workflow-serializer-y';

        $this->assertSame($value, $this->invokeSignalValue($event, $run));
    }

    public function testSignalValueFallsBackToCodecBlindWhenRunCodecUnavailable(): void
    {
        $value = [
            'legacy' => 'payload',
        ];

        $event = new WorkflowHistoryEvent();
        $event->payload = [
            'value' => '{"legacy":"payload"}',
        ];

        // Legacy untagged JSON blobs written before payload_codec was
        // populated still round-trip through the codec-blind sniffer path.
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
