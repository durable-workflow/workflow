<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAvroParityWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\WorkflowStub;

/**
 * Release-gating Avro parity suite (#362).
 */
final class V2AvroParitySuiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('workflows.serializer', 'avro');
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();
    }

    public function testStartInputRoundTripsUnderAvro(): void
    {
        $workflow = WorkflowStub::make(TestAvroParityWorkflow::class, 'avro-start-input');
        $workflow->start('ORD-1', 99.5, 3);

        $run = WorkflowRun::query()->where('workflow_instance_id', 'avro-start-input')->first();

        $this->assertSame('avro', $run->payload_codec);

        $args = Serializer::unserializeWithCodec('avro', $run->arguments);
        $this->assertSame('ORD-1', $args[0]);
        $this->assertSame(99.5, $args[1]);
        $this->assertIsFloat($args[1], 'float must survive Avro round-trip');
        $this->assertSame(3, $args[2]);
        $this->assertIsInt($args[2], 'int must survive Avro round-trip');
    }

    public function testFloatIntFidelityThroughFullWorkflowLifecycle(): void
    {
        $workflow = WorkflowStub::make(TestAvroParityWorkflow::class, 'avro-fidelity');
        $workflow->start('ORD-2', 3.14, 42);

        $this->drainReadyTasks();

        $run = WorkflowRun::query()->where('workflow_instance_id', 'avro-fidelity')->first();
        $this->assertSame('avro', $run->payload_codec);
        $this->assertSame('waiting', $workflow->refresh()->status());

        $workflow->signal('order-updated', [
            'priority' => true,
            'discount' => 0.15,
        ]);
        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();

        $this->assertSame('ORD-2', $output['order_id']);
        $this->assertSame(3.14, $output['input_amount']);
        $this->assertIsFloat($output['input_amount'], '3.14 must stay float');
        $this->assertSame(42, $output['input_items_count']);
        $this->assertIsInt($output['input_items_count'], '42 must stay int');
        $this->assertSame(3.0, $output['three_point_zero']);
        $this->assertIsFloat($output['three_point_zero']);

        $activityResult = $output['activity_result'];
        $this->assertSame('ORD-2', $activityResult['order_id']);
        $this->assertIsFloat($activityResult['amount'], 'activity result float must survive');
        $this->assertIsInt($activityResult['items_count'], 'activity result int must survive');
        $this->assertSame(3.0, $activityResult['three_point_zero']);
        $this->assertIsFloat($activityResult['three_point_zero']);

        $this->assertSame([
            'priority' => true,
            'discount' => 0.15,
        ], $output['signal_payload']);
    }

    public function testEveryPayloadRowIsTaggedAvro(): void
    {
        $workflow = WorkflowStub::make(TestAvroParityWorkflow::class, 'avro-tagging');
        $workflow->start('ORD-3', 10.0, 1);
        $this->drainReadyTasks();
        $workflow->signal('order-updated', [
            'tagged' => true,
        ]);
        $this->drainReadyTasks();

        $run = WorkflowRun::query()->where('workflow_instance_id', 'avro-tagging')->first();
        $this->assertSame('avro', $run->payload_codec);

        $commands = $run->commands()
            ->get();
        foreach ($commands as $command) {
            if ($command->payload_codec !== null) {
                $this->assertSame(
                    'avro',
                    $command->payload_codec,
                    "Command {$command->id} has codec {$command->payload_codec}, expected avro"
                );
            }
        }
    }

    public function testHistoryExportIncludesCodecMetadata(): void
    {
        $workflow = WorkflowStub::make(TestAvroParityWorkflow::class, 'avro-export');
        $workflow->start('ORD-4', 55.5, 7);
        $this->drainReadyTasks();
        $workflow->signal('order-updated', [
            'exported' => true,
        ]);
        $this->drainReadyTasks();

        $run = WorkflowRun::query()->where('workflow_instance_id', 'avro-export')->first();
        $export = HistoryExport::forRun($run);

        $this->assertIsArray($export);
        $this->assertArrayHasKey('payloads', $export);
        $this->assertSame('avro', $export['payloads']['codec'] ?? null, 'Export must tag the run codec as avro');
    }

    public function testSchemaEvolutionDecodesV1PayloadWithV2ReaderSchema(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro not installed');
        }

        $writerSchemaJson = json_encode([
            'type' => 'record',
            'name' => 'OrderPayload',
            'namespace' => 'durable_workflow.test',
            'fields' => [
                [
                    'name' => 'order_id',
                    'type' => 'string',
                ],
                [
                    'name' => 'amount',
                    'type' => 'double',
                ],
                [
                    'name' => 'items_count',
                    'type' => 'int',
                ],
            ],
        ]);

        $readerSchemaJson = json_encode([
            'type' => 'record',
            'name' => 'OrderPayload',
            'namespace' => 'durable_workflow.test',
            'fields' => [
                [
                    'name' => 'order_id',
                    'type' => 'string',
                ],
                [
                    'name' => 'amount',
                    'type' => 'double',
                ],
                [
                    'name' => 'items_count',
                    'type' => 'int',
                ],
                [
                    'name' => 'region',
                    'type' => 'string',
                    'default' => 'us-east',
                ],
            ],
        ]);

        $writerSchema = \Apache\Avro\Schema\AvroSchema::parse($writerSchemaJson);
        $readerSchema = \Apache\Avro\Schema\AvroSchema::parse($readerSchemaJson);

        $io = new \Apache\Avro\IO\AvroStringIO();
        $encoder = new \Apache\Avro\Datum\AvroIOBinaryEncoder($io);
        $writer = new \Apache\Avro\Datum\AvroIODatumWriter($writerSchema);
        $writer->write([
            'order_id' => 'ORD-EVOLVE',
            'amount' => 42.0,
            'items_count' => 3,
        ], $encoder);
        $v1Bytes = $io->string();

        $readIo = new \Apache\Avro\IO\AvroStringIO($v1Bytes);
        $decoder = new \Apache\Avro\Datum\AvroIOBinaryDecoder($readIo);
        $reader = new \Apache\Avro\Datum\AvroIODatumReader($writerSchema, $readerSchema);
        $decoded = $reader->read($decoder);

        $this->assertSame('ORD-EVOLVE', $decoded['order_id']);
        $this->assertSame(42.0, $decoded['amount']);
        $this->assertIsFloat($decoded['amount']);
        $this->assertSame(3, $decoded['items_count']);
        $this->assertIsInt($decoded['items_count']);
        $this->assertSame('us-east', $decoded['region'], 'Added field must get default value');
    }

    public function testNonAvroBytesUnderAvroCodecTagProducesTypedError(): void
    {
        $this->expectException(\Workflow\Serializers\CodecDecodeException::class);
        Serializer::unserializeWithCodec('avro', '{"this":"is json not avro"}');
    }

    public function testJsonBytesUnderAvroTagGetsDiagnosticMessage(): void
    {
        try {
            Serializer::unserializeWithCodec('avro', '{"x":1}');
            $this->fail('Should have thrown');
        } catch (\Workflow\Serializers\CodecDecodeException $e) {
            $this->assertStringContainsString(
                'json',
                strtolower($e->getMessage()),
                'Error should mention the bytes look like JSON'
            );
        }
    }

    public function testAvroDecodeFailureNamesCodec(): void
    {
        try {
            Serializer::unserializeWithCodec('avro', base64_encode("\x07garbage"));
            $this->fail('Should have thrown');
        } catch (\Workflow\Serializers\CodecDecodeException $e) {
            $this->assertStringContainsString('avro', strtolower($e->getMessage()));
        }
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
