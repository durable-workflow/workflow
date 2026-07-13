<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use Workflow\V2\Exceptions\WorkflowOutputCodecUnavailableException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * Run input envelopes may use the final v2 default, but workflow output must
 * carry its own codec because a completion command can encode it differently.
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

    public function testWorkflowRunOutputEnvelopeFailsWhenOutputCodecIsUnavailable(): void
    {
        config([
            'workflows.serializer' => 'json',
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->output = 'output-bytes';

        $this->expectException(WorkflowOutputCodecUnavailableException::class);
        $this->expectExceptionMessage('Workflow output codec is unavailable');

        $run->outputEnvelope();
    }

    public function testWorkflowRunOutputEnvelopeDoesNotGuessFromInputPayloadCodec(): void
    {
        config([
            'workflows.serializer' => 'json',
        ]);

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';
        $run->output = 'avro-bytes';

        $this->expectException(WorkflowOutputCodecUnavailableException::class);

        $run->outputEnvelope();
    }

    public function testWorkflowRunOutputEnvelopePrefersDedicatedOutputPayloadCodec(): void
    {
        $run = new WorkflowRun();
        $run->payload_codec = 'avro';
        $run->output_payload_codec = 'workflow-serializer-y';
        $run->output = 'y-bytes';

        $this->assertSame([
            'codec' => 'workflow-serializer-y',
            'blob' => 'y-bytes',
        ], $run->outputEnvelope());
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
