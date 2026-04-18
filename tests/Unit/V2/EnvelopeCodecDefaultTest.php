<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * Null-codec v2 envelopes must resolve through CodecRegistry::defaultCodec().
 * Final v2 treats missing payload_codec as Avro-only release data, not as a
 * development-era hook for changing new-run codecs through config.
 */
final class EnvelopeCodecDefaultTest extends TestCase
{
    public function testWorkflowRunArgumentsEnvelopeFallsBackToAvroWhenJsonIsConfigured(): void
    {
        config([
            'workflows.serializer' => 'json',
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        $envelope = $run->argumentsEnvelope();

        $this->assertSame([
            'codec' => 'avro',
            'blob' => 'blob-bytes',
        ], $envelope);
    }

    public function testWorkflowRunArgumentsEnvelopeFallsBackToAvroWhenYCodecIsConfigured(): void
    {
        config([
            'workflows.serializer' => 'workflow-serializer-y',
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';
        $expected = [
            'codec' => 'avro',
            'blob' => 'blob-bytes',
        ];

        $this->assertSame($expected, $run->argumentsEnvelope());
    }

    public function testWorkflowRunArgumentsEnvelopeFallsBackToAvroWhenLegacyFqcnIsConfigured(): void
    {
        config([
            'workflows.serializer' => \Workflow\Serializers\Y::class,
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        $this->assertSame('avro', $run->argumentsEnvelope()['codec']);
    }

    public function testWorkflowRunArgumentsEnvelopeFallsBackToAvroWhenConfigIsUnset(): void
    {
        config([
            'workflows.serializer' => null,
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        // Default codec for new deployments is avro.
        $this->assertSame('avro', $run->argumentsEnvelope()['codec']);
    }

    public function testWorkflowRunOutputEnvelopeFallsBackToAvroWhenJsonIsConfigured(): void
    {
        config([
            'workflows.serializer' => 'json',
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->output = 'output-bytes';
        $expected = [
            'codec' => 'avro',
            'blob' => 'output-bytes',
        ];

        $this->assertSame($expected, $run->outputEnvelope());
    }

    public function testWorkflowRunOutputEnvelopePrefersExplicitPayloadCodec(): void
    {
        config([
            'workflows.serializer' => 'json',
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';
        $run->output = 'avro-bytes';

        // When the row explicitly pins a codec, the row wins over config.
        $this->assertSame('avro', $run->outputEnvelope()['codec']);
    }

    public function testWorkflowUpdateResultEnvelopeFallsBackToAvroWhenJsonIsConfigured(): void
    {
        config([
            'workflows.serializer' => 'json',
        ]);

        $update = new WorkflowUpdate();
        $update->result = 'result-bytes';
        // Leave $update->run relationship unhydrated so the fallback path fires.

        $envelope = $update->resultEnvelope();

        $this->assertSame([
            'codec' => 'avro',
            'blob' => 'result-bytes',
        ], $envelope);
    }

    protected function getPackageProviders($app)
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }
}
