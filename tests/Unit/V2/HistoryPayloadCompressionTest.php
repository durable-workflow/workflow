<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Support\HistoryPayloadCompression;
use Workflow\V2\Support\WorkerProtocolVersion;

final class HistoryPayloadCompressionTest extends TestCase
{
    private function makePayload(int $eventCount): array
    {
        $events = [];

        for ($i = 1; $i <= $eventCount; $i++) {
            $events[] = [
                'id' => "evt-{$i}",
                'sequence' => $i,
                'event_type' => 'WorkflowStarted',
                'payload' => ['key' => str_repeat('x', 100)],
                'workflow_task_id' => null,
                'workflow_command_id' => null,
                'recorded_at' => '2026-04-12T00:00:00.000000Z',
            ];
        }

        return [
            'task_id' => 'task-1',
            'workflow_run_id' => 'run-1',
            'workflow_instance_id' => 'inst-1',
            'workflow_type' => 'TestWorkflow',
            'workflow_class' => null,
            'payload_codec' => 'json',
            'arguments' => null,
            'run_status' => 'Running',
            'last_history_sequence' => $eventCount,
            'history_events' => $events,
        ];
    }

    public function testCompressReturnsUnchangedWhenNoEncoding(): void
    {
        $payload = $this->makePayload(100);

        $result = HistoryPayloadCompression::compress($payload, null);

        $this->assertSame($payload, $result);
    }

    public function testCompressReturnsUnchangedWhenUnsupportedEncoding(): void
    {
        $payload = $this->makePayload(100);

        $result = HistoryPayloadCompression::compress($payload, 'br');

        $this->assertSame($payload, $result);
    }

    public function testCompressReturnsUnchangedBelowThreshold(): void
    {
        $payload = $this->makePayload(WorkerProtocolVersion::COMPRESSION_THRESHOLD - 1);

        $result = HistoryPayloadCompression::compress($payload, 'gzip');

        $this->assertSame($payload, $result);
    }

    public function testCompressGzipAboveThreshold(): void
    {
        $payload = $this->makePayload(WorkerProtocolVersion::COMPRESSION_THRESHOLD);

        $result = HistoryPayloadCompression::compress($payload, 'gzip');

        $this->assertSame([], $result['history_events']);
        $this->assertArrayHasKey('history_events_compressed', $result);
        $this->assertSame('gzip', $result['history_events_encoding']);
        $this->assertIsString($result['history_events_compressed']);
    }

    public function testCompressDeflateAboveThreshold(): void
    {
        $payload = $this->makePayload(WorkerProtocolVersion::COMPRESSION_THRESHOLD);

        $result = HistoryPayloadCompression::compress($payload, 'deflate');

        $this->assertSame([], $result['history_events']);
        $this->assertArrayHasKey('history_events_compressed', $result);
        $this->assertSame('deflate', $result['history_events_encoding']);
    }

    public function testCompressDecompressRoundTripGzip(): void
    {
        $payload = $this->makePayload(100);

        $compressed = HistoryPayloadCompression::compress($payload, 'gzip');
        $decompressed = HistoryPayloadCompression::decompress($compressed);

        $this->assertSame($payload['history_events'], $decompressed['history_events']);
        $this->assertArrayNotHasKey('history_events_compressed', $decompressed);
        $this->assertArrayNotHasKey('history_events_encoding', $decompressed);
        $this->assertSame($payload['task_id'], $decompressed['task_id']);
    }

    public function testCompressDecompressRoundTripDeflate(): void
    {
        $payload = $this->makePayload(100);

        $compressed = HistoryPayloadCompression::compress($payload, 'deflate');
        $decompressed = HistoryPayloadCompression::decompress($compressed);

        $this->assertSame($payload['history_events'], $decompressed['history_events']);
    }

    public function testDecompressReturnsUnchangedWithoutCompressedKey(): void
    {
        $payload = $this->makePayload(10);

        $result = HistoryPayloadCompression::decompress($payload);

        $this->assertSame($payload, $result);
    }

    public function testDecompressThrowsOnUnsupportedEncoding(): void
    {
        $payload = [
            'history_events' => [],
            'history_events_compressed' => base64_encode('data'),
            'history_events_encoding' => 'brotli',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported history payload encoding');

        HistoryPayloadCompression::decompress($payload);
    }

    public function testDecompressThrowsOnInvalidBase64(): void
    {
        $payload = [
            'history_events' => [],
            'history_events_compressed' => '!!!invalid-base64!!!',
            'history_events_encoding' => 'gzip',
        ];

        $this->expectException(RuntimeException::class);

        HistoryPayloadCompression::decompress($payload);
    }

    public function testIsCompressedDetectsCompressedPayload(): void
    {
        $this->assertFalse(HistoryPayloadCompression::isCompressed(['history_events' => []]));
        $this->assertTrue(HistoryPayloadCompression::isCompressed([
            'history_events' => [],
            'history_events_compressed' => 'data',
        ]));
    }

    public function testResolveEncodingPicksFirstSupportedFromList(): void
    {
        $this->assertSame('gzip', HistoryPayloadCompression::resolveEncoding('gzip, deflate'));
        $this->assertSame('deflate', HistoryPayloadCompression::resolveEncoding('br, deflate'));
        $this->assertNull(HistoryPayloadCompression::resolveEncoding('br, zstd'));
    }

    public function testResolveEncodingIsCaseInsensitive(): void
    {
        $this->assertSame('gzip', HistoryPayloadCompression::resolveEncoding('GZIP'));
        $this->assertSame('deflate', HistoryPayloadCompression::resolveEncoding('Deflate'));
    }

    public function testCompressedPayloadIsSmallerThanOriginal(): void
    {
        $payload = $this->makePayload(200);
        $originalSize = strlen(json_encode($payload['history_events']));

        $compressed = HistoryPayloadCompression::compress($payload, 'gzip');
        $compressedSize = strlen(base64_decode($compressed['history_events_compressed']));

        $this->assertLessThan($originalSize, $compressedSize);
    }

    public function testCompressPreservesNonEventFields(): void
    {
        $payload = $this->makePayload(100);

        $compressed = HistoryPayloadCompression::compress($payload, 'gzip');

        $this->assertSame($payload['task_id'], $compressed['task_id']);
        $this->assertSame($payload['workflow_run_id'], $compressed['workflow_run_id']);
        $this->assertSame($payload['run_status'], $compressed['run_status']);
        $this->assertSame($payload['last_history_sequence'], $compressed['last_history_sequence']);
    }
}
