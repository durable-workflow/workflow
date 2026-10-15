<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Behavior\Internal\LogWriter\Psr3LogWriter;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TracingExporter;
use Workflow\V2\Support\ExecutionTracing;

final class ExporterExecutionTracingTest extends TestCase
{
    public static function outages(): array
    {
        return [
            'unavailable collector' => ['unavailable'],
            'stalled collector' => ['stall'],
            'connection refused' => ['refused'],
        ];
    }

    #[DataProvider('outages')]
    public function testCollectorFailureDoesNotChangeColdWorkflowCompletion(string $mode): void
    {
        $this->requireExporter();
        $directory = $this->directory();
        [$server, $address] = $this->collector($directory, $mode);
        if ($mode === 'refused') {
            $server->stop();
        }
        try {
            $admitted = $this->worker($directory, 'admit', $address);
            $workers = [];
            for ($i = 0; $i < 3; ++$i) {
                $workers[] = $this->worker($directory, 'work', $address);
            }
            $finished = $this->worker($directory, 'inspect', $address);
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(3, $finished['tasks']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertCount(3, array_unique(array_column($workers, 'pid')));
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            $spans = array_merge(...array_column($workers, 'spans'));
            self::assertCount(3, $spans);
            foreach ($spans as $span) {
                self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                self::assertSame($admitted['run_id'], $span['fields']['workflow_run_id']);
            }
            $requests = $this->requests($directory);
            self::assertCount($mode === 'refused' ? 0 : 4, $requests);
            foreach ($requests as $request) {
                self::assertSame('POST', $request['method']);
                self::assertSame('/v1/traces', $request['path']);
                self::assertSame('application/x-protobuf', $request['content_type']);
                self::assertCount(1, $request['spans']);
            }
            if ($mode !== 'refused') {
                self::assertSame(['durable.workflow.admission', 'durable.workflow.task',
                    'durable.activity.attempt', 'durable.workflow.task'], array_map(
                        static fn (array $request): string => $request['spans'][0]['name'],
                        $requests
                    ));
            }
        } finally {
            $server->stop();
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    public function testBoundedQueueDropsOverflowAndRecoversWithoutReplayingBusinessWork(): void
    {
        $this->requireExporter();
        $directory = $this->directory();
        [$server, $address] = $this->collector($directory, 'unavailable');
        $initial = Context::getCurrent();
        $provider = new TracerProvider(TracingExporter::processor('http://' . $address . '/v1/traces'));
        self::assertSame($initial, Context::getCurrent());
        $previousWriter = Logging::logWriter();
        $logs = new TestHandler();
        Logging::setLogWriter(new Psr3LogWriter(new Logger('telemetry-test', [$logs])));
        $configuration = Configurator::create()->withTracerProvider($provider)->activate();
        $calls = 0;
        try {
            for ($i = 0; $i < 10; ++$i) {
                self::assertSame(7, ExecutionTracing::activity([
                    'task_id' => 'task-' . $i,
                    'arguments' => 'payload-private-sentinel',
                ], null, static function () use (&$calls): int {
                    ++$calls;

                    return 7;
                }, enabled: true));
            }
            $failure = new RuntimeException('business-private-sentinel');
            try {
                ExecutionTracing::activity([], null, static function () use ($failure): void {
                    throw $failure;
                }, enabled: true);
                self::fail('The original business exception must escape.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame(10, $calls);
            self::assertSame([], ExecutionTracing::current());
            self::assertSame([], $this->requests($directory));
            $provider->forceFlush();
            $failed = $this->requests($directory);
            self::assertCount(2, $failed);
            self::assertCount(4, array_merge(...array_column($failed, 'spans')));
            self::assertCount(2, $logs->getRecords());
            foreach ($logs->getRecords() as $record) {
                self::assertSame('Export failure', $record['message']);
            }
            file_put_contents($directory . '/mode', 'healthy');
            ExecutionTracing::activity([
                'task_id' => 'recovered',
            ], null, static function () use (&$calls): void {
                ++$calls;
            }, enabled: true);
            self::assertCount(2, $this->requests($directory));
            $provider->forceFlush();
            $requests = $this->requests($directory);
            self::assertCount(3, $requests);
            self::assertSame('healthy', $requests[2]['mode']);
            self::assertCount(1, $requests[2]['spans']);
            self::assertStringContainsString('recovered', $requests[2]['request']);
            try {
                ExecutionTracing::activity([], null, static function () use ($failure): void {
                    throw $failure;
                }, enabled: true);
                self::fail('Exporter recovery must preserve the original business exception.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            $provider->forceFlush();
            $requests = $this->requests($directory);
            self::assertCount(4, $requests);
            self::assertStringContainsString('error.type', $requests[3]['request']);
            self::assertStringContainsString(RuntimeException::class, $requests[3]['request']);
            foreach (['payload-private-sentinel', 'business-private-sentinel'] as $private) {
                self::assertStringNotContainsString($private, json_encode($requests, JSON_THROW_ON_ERROR));
            }
            self::assertSame(11, $calls);
            $provider->shutdown();
            $provider->shutdown();
            self::assertCount(4, $this->requests($directory));
            self::assertSame([], ExecutionTracing::current());
        } finally {
            $configuration->detach();
            $provider->shutdown();
            Logging::setLogWriter($previousWriter);
            $server->stop();
            (new Filesystem())->deleteDirectory($directory);
        }
        self::assertSame($initial, Context::getCurrent());
    }

    private function requireExporter(): void
    {
        if (! class_exists(SpanExporter::class)) {
            self::markTestSkipped('Requires the optional official OTLP exporter.');
        }
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-exporter-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        return $directory;
    }

    private function collector(string $directory, string $mode): array
    {
        file_put_contents($directory . '/mode', $mode);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = new Process([
            PHP_BINARY, '-S', $address, __DIR__ . '/../../Fixtures/V2/tracing-collector-router.php',
        ], env: [
            'DW_TEST_COLLECTOR_DIRECTORY' => $directory,
            'DW_TEST_COLLECTOR_AUTOLOADS' => json_encode($this->autoloads(), JSON_THROW_ON_ERROR),
        ]);
        $server->setTimeout(15);
        $server->start();
        self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains(
            $output,
            'Development Server'
        )));

        return [$server, $address];
    }

    private function autoloads(): array
    {
        return array_map(
            static fn (string $vendor): string => $vendor . '/autoload.php',
            array_keys(ClassLoader::getRegisteredLoaders())
        );
    }

    private function requests(string $directory): array
    {
        $journal = $directory . '/requests.jsonl';

        return is_file($journal) ? array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        ) : [];
    }

    private function worker(string $directory, string $mode, string $address): array
    {
        $process = new Process([
            PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
            ...$this->autoloads(),
        ], env: [
            'DW_TEST_TRACING_EXPORTER_ENDPOINT' => 'http://' . $address . '/v1/traces',
            'DW_TEST_TRACING_EXPORTER_JOURNAL' => $directory . '/requests.jsonl',
        ]);
        $process->setTimeout(15);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $result['outside_context']);
        self::assertTrue($result['scope_restored']);
        self::assertSame(0, $result['exports_before_shutdown']);
        self::assertLessThan(2, $result['export_shutdown_seconds']);
        if ($mode === 'work') {
            self::assertStringContainsString('Export failure', $process->getErrorOutput());
        }

        return $result;
    }
}
