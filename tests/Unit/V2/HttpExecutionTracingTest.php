<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Contrib\Instrumentation\Guzzle\GuzzleInstrumentation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class HttpExecutionTracingTest extends TestCase
{
    public function testAutomaticClientInstrumentationInAColdActivityPreservesContextAndHeaders(): void
    {
        if (! extension_loaded('opentelemetry') || ! class_exists(GuzzleInstrumentation::class)) {
            self::markTestSkipped('Requires the optional official Guzzle instrumentation and PHP extension.');
        }
        $directory = sys_get_temp_dir() . '/dw-tracing-http-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = new Process([
            PHP_BINARY, '-S', $address, __DIR__ . '/../../Fixtures/V2/tracing-http-router.php',
        ]);
        $server->setTimeout(5);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains(
                $output,
                'Development Server'
            )));
            $admitted = $this->runWorker($directory, 'admit', $address);
            $workflow = $this->runWorker($directory, 'work', $address);
            $activity = $this->runWorker($directory, 'work', $address);
            $completion = $this->runWorker($directory, 'work', $address);
            $finished = $this->runWorker($directory, 'inspect', $address);
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(3, $finished['tasks']);
            self::assertCount(3, array_unique([$workflow['pid'], $activity['pid'], $completion['pid']]));
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            $attempts = array_values(array_filter(
                $activity['spans'],
                static fn (array $span): bool => $span['name'] === 'durable.activity.attempt'
            ));
            $clients = array_values(array_filter(
                $activity['spans'],
                static fn (array $span): bool => $span['kind'] === SpanKind::KIND_CLIENT
            ));
            self::assertCount(1, $attempts);
            self::assertCount(2, $clients);
            self::assertSame($attempts[0]['span_id'], $clients[0]['parent_id']);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $clients[0]['trace_id']);
            self::assertSame(
                '00-' . $clients[0]['trace_id'] . '-' . $clients[0]['span_id'] . '-01',
                $activity['http_response']['traceparent']
            );
            self::assertSame('dwtest=one', $activity['http_response']['tracestate']);
            self::assertSame('approved=tenant-a', $activity['http_response']['baggage']);
            self::assertSame('activity-http-request', $activity['http_response']['request_id']);
            self::assertSame('11111111111111111111111111111111', $clients[1]['trace_id']);
            self::assertSame('2222222222222222', $clients[1]['parent_id']);
            self::assertSame(
                '00-' . $clients[1]['trace_id'] . '-' . $clients[1]['span_id'] . '-01',
                $activity['outside_http']['traceparent']
            );
            self::assertNull($activity['outside_http']['baggage']);
            self::assertSame('outside-http-request', $activity['outside_http']['request_id']);
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $transfers = array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['message'] === 'http transfer'
            ));
            self::assertCount(1, $transfers);
            $fields = $transfers[0]['context'];
            self::assertSame($admitted['run_id'], $fields['workflow_run_id']);
            self::assertSame($attempts[0]['fields']['activity_attempt_id'], $fields['activity_attempt_id']);
            self::assertSame('activity-http-request', $fields['request_id']);
            self::assertSame($fields['active_trace_id'], $fields['trace_id']);
            self::assertSame($fields['active_span_id'], $fields['span_id']);
            $telemetry = json_encode($activity['spans'], JSON_THROW_ON_ERROR);
            foreach (['test-auth-sentinel', 'payload-private-sentinel', 'must-not-propagate'] as $excluded) {
                self::assertStringNotContainsString($excluded, $telemetry);
            }
        } finally {
            $server->stop();
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private function runWorker(string $directory, string $mode, string $address): array
    {
        $packageVendor = dirname(__DIR__, 3) . '/vendor';
        $otherVendors = array_filter(
            array_keys(ClassLoader::getRegisteredLoaders()),
            static fn (string $vendor): bool => $vendor !== $packageVendor
        );
        $process = new Process([
            PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
            ...array_map(
                static fn (string $vendor): string => $vendor . '/autoload.php',
                [...$otherVendors, $packageVendor]
            ),
        ], env: [
            'DW_TEST_TRACING_HTTP_URL' => 'http://' . $address . '/headers',
        ]);
        $process->setTimeout(30);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $result['outside_context']);
        self::assertTrue($result['scope_restored']);

        return $result;
    }
}
