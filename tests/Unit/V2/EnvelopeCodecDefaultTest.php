<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * TD-081: Null-codec v2 envelopes must resolve through CodecRegistry::defaultCodec()
 * (which reads `workflows.serializer`), not the removed `workflows.serializer_name`
 * config key. Regression coverage proves rows with a missing payload_codec resolve
 * consistently regardless of which canonical codec the deployment has configured.
 */
final class EnvelopeCodecDefaultTest extends TestCase
{
    public function testWorkflowRunArgumentsEnvelopeFallsBackToConfiguredSerializer(): void
    {
        config(['workflows.serializer' => 'json']);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        $envelope = $run->argumentsEnvelope();

        $this->assertSame(['codec' => 'json', 'blob' => 'blob-bytes'], $envelope);
    }

    public function testWorkflowRunArgumentsEnvelopeUsesYCodecWhenConfigured(): void
    {
        config(['workflows.serializer' => 'workflow-serializer-y']);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        $this->assertSame(
            ['codec' => 'workflow-serializer-y', 'blob' => 'blob-bytes'],
            $run->argumentsEnvelope(),
        );
    }

    public function testWorkflowRunArgumentsEnvelopeResolvesLegacyFqcnToCanonicalName(): void
    {
        config(['workflows.serializer' => \Workflow\Serializers\Y::class]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        // Legacy FQCN aliases must map to the canonical codec name so polyglot
        // consumers see a stable identifier on the wire.
        $this->assertSame('workflow-serializer-y', $run->argumentsEnvelope()['codec']);
    }

    public function testWorkflowRunArgumentsEnvelopeFallsBackToAvroWhenConfigIsUnset(): void
    {
        config(['workflows.serializer' => null]);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->arguments = 'blob-bytes';

        // Default codec for new deployments is avro.
        $this->assertSame('avro', $run->argumentsEnvelope()['codec']);
    }

    public function testWorkflowRunOutputEnvelopeFallsBackToConfiguredSerializer(): void
    {
        config(['workflows.serializer' => 'json']);

        $run = new WorkflowRun();
        $run->payload_codec = null;
        $run->output = 'output-bytes';

        $this->assertSame(
            ['codec' => 'json', 'blob' => 'output-bytes'],
            $run->outputEnvelope(),
        );
    }

    public function testWorkflowRunOutputEnvelopePrefersExplicitPayloadCodec(): void
    {
        config(['workflows.serializer' => 'json']);

        $run = new WorkflowRun();
        $run->payload_codec = 'avro';
        $run->output = 'avro-bytes';

        // When the row explicitly pins a codec, the row wins over config.
        $this->assertSame('avro', $run->outputEnvelope()['codec']);
    }

    public function testWorkflowUpdateResultEnvelopeFallsBackToConfiguredSerializer(): void
    {
        config(['workflows.serializer' => 'json']);

        $update = new WorkflowUpdate();
        $update->result = 'result-bytes';
        // Leave $update->run relationship unhydrated so the fallback path fires.

        $envelope = $update->resultEnvelope();

        $this->assertSame(['codec' => 'json', 'blob' => 'result-bytes'], $envelope);
    }

    protected function getPackageProviders($app)
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }
}
