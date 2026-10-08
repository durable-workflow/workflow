<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Base64;
use Workflow\Serializers\Serializer;
use Workflow\Serializers\Y;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunTimelineProjector;

final class HistoryExportPayloadDiagnosticsTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool, string}>
     */
    public static function unavailablePayloads(): iterable
    {
        yield 'no stored payload' => [null, false, 'payload_unavailable'];
        yield 'stored empty payload' => ['', true, 'payload_missing'];
    }

    #[DataProvider('unavailablePayloads')]
    public function testManifestDistinguishesAnAbsentPayloadFromMissingStoredBytes(
        ?string $payload,
        bool $available,
        string $diagnostic,
    ): void {
        $run = $this->createRun($payload, $payload, 'avro');
        $before = $run->getRawOriginal();
        $exportedAt = Carbon::parse('2026-10-08T15:00:00Z');
        $bundle = HistoryExport::forRun($run, $exportedAt);
        $entries = array_column($bundle['payload_manifest']['entries'], null, 'path');

        foreach (['arguments', 'output'] as $field) {
            $this->assertSame($available, $bundle['payloads'][$field]['available']);
            $this->assertNull($bundle['payloads'][$field]['data']);
            $this->assertSame([
                'path' => "payloads.{$field}.data",
                'codec' => 'avro',
                'available' => $available,
                'redacted' => false,
                'encoding' => 'base64-avro-single-object',
                'avro_framing' => null,
                'avro_prefix_hex' => null,
                'writer_schema' => null,
                'writer_schema_fingerprint' => null,
                'diagnostic' => $diagnostic,
            ], $entries["payloads.{$field}.data"]);
        }
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($this->wire($bundle), $this->wire(HistoryExport::forRun($run->fresh(), $exportedAt)));
    }

    /**
     * @return iterable<string, array{string, class-string<Y|Base64>, string}>
     */
    public static function legacyCodecs(): iterable
    {
        yield 'escaped canonical name' => ['workflow-serializer-y', Y::class, 'php-serialized-escaped'];
        yield 'escaped class name' => [Y::class, Y::class, 'php-serialized-escaped'];
        yield 'base64 canonical name' => ['workflow-serializer-base64', Base64::class, 'php-serialized-base64'];
        yield 'base64 class name' => [Base64::class, Base64::class, 'php-serialized-base64'];
    }

    /**
     * @param class-string<Y|Base64> $serializer
     */
    #[DataProvider('legacyCodecs')]
    public function testLegacyPayloadExportsPreserveTheirBytesAndIdentifyTheirOfflineEncoding(
        string $codec,
        string $serializer,
        string $encoding,
    ): void {
        $arguments = $serializer::serialize([false, 0, '', [
            'order' => 'order-a',
        ]]);
        $output = $serializer::serialize([
            'accepted' => false,
            'count' => 0,
        ]);
        $run = $this->createRun($arguments, $output, $codec);
        $before = $run->getRawOriginal();
        $exportedAt = Carbon::parse('2026-10-08T15:00:00Z');
        $bundle = HistoryExport::forRun($run, $exportedAt);
        $entries = array_column($bundle['payload_manifest']['entries'], null, 'path');
        $this->assertSame($codec, $bundle['payloads']['codec']);
        $this->assertSame($codec, $bundle['payloads']['output']['codec']);

        foreach ([
            'arguments' => $arguments,
            'output' => $output,
        ] as $field => $bytes) {
            $this->assertTrue($bundle['payloads'][$field]['available']);
            $this->assertSame($bytes, $bundle['payloads'][$field]['data']);
            $this->assertSame([
                'path' => "payloads.{$field}.data",
                'codec' => $codec,
                'available' => true,
                'redacted' => false,
                'encoding' => $encoding,
                'avro_framing' => null,
                'avro_prefix_hex' => null,
                'writer_schema' => null,
                'writer_schema_fingerprint' => null,
                'diagnostic' => null,
            ], $entries["payloads.{$field}.data"]);
        }
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($this->wire($bundle), $this->wire(HistoryExport::forRun($run->fresh(), $exportedAt)));
    }

    public function testOutputAvroMetadataRemainsAvailableWhenTheStoredInputUsesALegacyCodec(): void
    {
        $arguments = Base64::serialize([false, 0]);
        $output = Serializer::serializeWithCodec('avro', [false, 0]);
        $run = $this->createRun($arguments, $output, 'workflow-serializer-base64');
        $run->forceFill([
            'output_payload_codec' => 'avro',
        ])->save();
        $before = $run->fresh()
            ->getRawOriginal();
        $bundle = HistoryExport::forRun($run->fresh(), Carbon::parse('2026-10-08T15:00:00Z'));
        $this->assertSame($arguments, $bundle['payloads']['arguments']['data']);
        $this->assertSame($output, $bundle['payloads']['output']['data']);
        $this->assertSame('avro', $bundle['payloads']['output']['codec']);
        $this->assertArrayHasKey('avro', $bundle['codec_schemas']);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
    }

    private function createRun(?string $arguments, ?string $output, string $codec): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'export.payload-diagnostics',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'export.payload-diagnostics',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => $codec,
            'output_payload_codec' => $codec,
            'arguments' => $arguments,
            'output' => $output,
            'started_at' => Carbon::parse('2026-10-08T14:58:00Z'),
            'closed_at' => Carbon::parse('2026-10-08T14:59:00Z'),
            'last_progress_at' => Carbon::parse('2026-10-08T14:59:00Z'),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_type' => 'export.payload-diagnostics',
        ]);
        RunTimelineProjector::project($run->fresh());

        return $run->fresh();
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function wire(array $bundle): string
    {
        return json_encode($bundle, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
