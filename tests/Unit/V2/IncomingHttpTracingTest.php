<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Client;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class IncomingHttpTracingTest extends TestCase
{
    public function testRequestContextSurvivesServerExitAndTwoIndependentActivityWorkers(): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-admission-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $autoloads = $this->autoloads();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = new Process([
            PHP_BINARY, '-S', $address, __DIR__ . '/../../Fixtures/V2/tracing-process.php',
        ], env: [
            'DW_TEST_TRACING_DIRECTORY' => $directory,
            'DW_TEST_TRACING_AUTOLOADS' => json_encode($autoloads, JSON_THROW_ON_ERROR),
        ]);
        $server->setTimeout(10);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains(
                $output,
                'Development Server'
            )));
            $response = (new Client())->post('http://' . $address . '/start', [
                'timeout' => 10,
                'connect_timeout' => 1,
                'headers' => [
                    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                    'tracestate' => 'dwtest=one',
                    'baggage' => 'approved=tenant-a,private=must-not-propagate',
                    'X-Request-ID' => 'incoming-http-request',
                ],
            ]);
            self::assertSame(201, $response->getStatusCode());
            $admitted = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $server->stop();
            self::assertFalse($server->isRunning());
            self::assertSame([], $admitted['outside_context']);
            self::assertTrue($admitted['scope_restored']);
            self::assertCount(1, $admitted['spans']);
            $requestSpan = $admitted['spans'][0];
            self::assertSame(SpanKind::KIND_SERVER, $requestSpan['kind']);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $requestSpan['trace_id']);
            self::assertSame('00f067aa0ba902b7', $requestSpan['parent_id']);
            $carrier = json_decode($admitted['carrier'], true, flags: JSON_THROW_ON_ERROR)['carrier'];
            self::assertSame(
                '00-' . $requestSpan['trace_id'] . '-' . $requestSpan['span_id'] . '-01',
                $carrier['traceparent']
            );
            self::assertSame('dwtest=one', $carrier['tracestate']);
            self::assertSame('approved=tenant-a', $carrier['baggage']);
            $workers = [];
            for ($remaining = 5; $remaining > 0; --$remaining) {
                $workers[] = $this->worker($directory, 'work', $autoloads);
            }
            self::assertCount(5, array_unique(array_column($workers, 'pid')));
            $activityIds = [];
            $activitySpans = [];
            foreach ($workers as $worker) {
                self::assertSame([], $worker['outside_context']);
                self::assertTrue($worker['scope_restored']);
                self::assertCount(1, $worker['spans']);
                $span = $worker['spans'][0];
                self::assertSame($requestSpan['trace_id'], $span['trace_id']);
                self::assertSame($requestSpan['span_id'], $span['parent_id']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
                if ($span['name'] === 'durable.activity.attempt') {
                    self::assertSame(1, $span['fields']['activity_attempt_number']);
                    $activityIds[] = $span['fields']['activity_id'];
                    $activitySpans[$span['fields']['activity_id']] = $span;
                }
            }
            self::assertCount(2, array_unique($activityIds));
            $finished = $this->worker($directory, 'inspect', $autoloads);
            self::assertSame('completed', $finished['status']);
            self::assertSame([
                [9007199254740993, null, '雪'], [9007199254740993, null, '雪'],
            ], $finished['output']);
            self::assertSame(5, $finished['tasks']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['message'] === 'callback'
            ));
            self::assertCount(2, $callbacks);
            foreach ($callbacks as $callback) {
                $fields = $callback['context'];
                self::assertSame($admitted['run_id'], $fields['workflow_run_id']);
                self::assertArrayHasKey($fields['activity_id'], $activitySpans);
                $span = $activitySpans[$fields['activity_id']];
                self::assertSame($span['fields']['activity_attempt_id'], $fields['activity_attempt_id']);
                self::assertSame($requestSpan['trace_id'], $fields['trace_id']);
                self::assertSame($span['span_id'], $fields['span_id']);
            }
        } finally {
            $server->stop();
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, string $mode, array $autoloads): array
    {
        $process = new Process([
            PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once', ...$autoloads,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function autoloads(): array
    {
        $packageVendor = dirname(__DIR__, 3) . '/vendor';
        $otherVendors = array_filter(
            array_keys(ClassLoader::getRegisteredLoaders()),
            static fn (string $vendor): bool => $vendor !== $packageVendor
        );

        return array_map(
            static fn (string $vendor): string => $vendor . '/autoload.php',
            [...$otherVendors, $packageVendor]
        );
    }
}
