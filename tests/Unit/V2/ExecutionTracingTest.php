<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Fiber;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Tracing\LogContext;

final class ExecutionTracingTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const PARENT_ID = '00f067aa0ba902b7';

    private InMemoryExporter $exporter;

    private TracerProvider $provider;

    private ScopeInterface $configuration;

    private ContextInterface $initial;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->configuration = Configurator::create()->withTracerProvider($this->provider)->activate();
        $this->initial = Context::getCurrent();
    }

    protected function tearDown(): void
    {
        self::assertSame($this->initial, Context::getCurrent());
        self::assertSame([], ExecutionTracing::current());
        $this->configuration->detach();
        $this->provider->shutdown();
    }

    public function testRealProviderAndLoggingKeepDurableRequestAndTraceIdentitiesSeparate(): void
    {
        $handler = new TestHandler();
        $logger = new LaravelLogger(new Logger('application', [$handler]));
        (new LogContext())($logger);
        $foreign = Context::createKey('unrelated-worker-state');
        $ambient = Context::getCurrent()->with($foreign, 'private')->activate();
        try {
            $result = ExecutionTracing::activity(self::fields(), self::carrier(), function () use (
                $logger,
                $foreign
            ): int {
                self::assertSame($this->provider, Globals::tracerProvider());
                self::assertNull(Context::getCurrent()->get($foreign));
                $fields = ExecutionTracing::current();
                self::assertSame(self::TRACE_ID, $fields['trace_id']);
                self::assertNotSame(self::PARENT_ID, $fields['span_id']);
                self::assertSame('0', $fields['namespace']);
                $logger->info('activity executing', [
                    'request_id' => 'application-request',
                ]);
                $client = Globals::tracerProvider()->getTracer('application')->spanBuilder('outbound')->startSpan();
                $clientScope = $client->activate();
                try {
                    $logger->info('outbound executing');
                    self::assertSame($fields['workflow_run_id'], ExecutionTracing::current()['workflow_run_id']);
                    self::assertSame($client->getContext()->getSpanId(), ExecutionTracing::current()['span_id']);
                } finally {
                    $clientScope->detach();
                }
                self::assertSame($fields, ExecutionTracing::current());
                $client->end();

                return 7;
            }, enabled: true);
            self::assertSame(7, $result);
            self::assertSame('private', Context::getCurrent()->get($foreign));
        } finally {
            $ambient->detach();
        }
        $logger->info('after activity', [
            'request_id' => 'outside',
        ]);
        $records = $handler->getRecords();
        self::assertSame('run-1', $records[0]['context']['workflow_run_id']);
        self::assertSame('application-request', $records[0]['context']['request_id']);
        self::assertArrayNotHasKey('arguments', $records[0]['context']);
        self::assertSame([
            'request_id' => 'outside',
        ], $records[2]['context']);
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame($spans[0]->getContext()->getSpanId(), $records[1]['context']['span_id']);
        self::assertSame($spans[1]->getContext()->getSpanId(), $spans[0]->getParentContext()->getSpanId());
        self::assertSame(self::PARENT_ID, $spans[1]->getParentContext()->getSpanId());
        self::assertSame('origin-request', $spans[1]->getAttributes()->get('request_id'));
        self::assertNull($spans[1]->getAttributes()->get('arguments'));
    }

    public function testAttemptsHaveDistinctSpansAndNestedScopesRestoreTheirCaller(): void
    {
        ExecutionTracing::activity(self::fields(), self::carrier(), static function (): void {
            $outer = ExecutionTracing::current();
            ExecutionTracing::activity(self::fields('run-2', 'attempt-2'), self::carrier(), static function (): void {
                self::assertSame('run-2', ExecutionTracing::current()['workflow_run_id']);
            }, enabled: true);
            self::assertSame($outer, ExecutionTracing::current());
        }, enabled: true);
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertNotSame($spans[0]->getContext()->getSpanId(), $spans[1]->getContext()->getSpanId());
        self::assertSame('activity-1', $spans[0]->getAttributes()->get('activity_id'));
        self::assertSame('activity-1', $spans[1]->getAttributes()->get('activity_id'));
        self::assertSame('attempt-2', $spans[0]->getAttributes()->get('activity_attempt_id'));
        self::assertSame('attempt-1', $spans[1]->getAttributes()->get('activity_attempt_id'));
    }

    public function testConcurrentFibersKeepIndependentExecutionContexts(): void
    {
        $handler = new TestHandler();
        $logger = new LaravelLogger(new Logger('fibers', [$handler]));
        (new LogContext())($logger);
        $operation = function (string $run) use ($logger): void {
            // Configuration is execution-local too; each Fiber owns its provider scope.
            $configured = Configurator::create()->withTracerProvider($this->provider)
                ->storeInContext(Context::getRoot())->activate();
            try {
                ExecutionTracing::activity(self::fields($run), self::carrier(), static function () use (
                    $run,
                    $logger
                ): void {
                    self::assertSame($run, ExecutionTracing::current()['workflow_run_id']);
                    $logger->info('before suspension');
                    Fiber::suspend();
                    self::assertSame($run, ExecutionTracing::current()['workflow_run_id']);
                    $logger->info('after resumption');
                }, enabled: true);
                self::assertSame([], ExecutionTracing::current());
            } finally {
                $configured->detach();
            }
        };
        $first = new Fiber(static fn () => $operation('run-a'));
        $second = new Fiber(static fn () => $operation('run-b'));
        $first->start();
        self::assertSame([], ExecutionTracing::current());
        $second->start();
        self::assertSame([], ExecutionTracing::current());
        $first->resume();
        $second->resume();
        self::assertCount(2, $this->exporter->getSpans());
        self::assertSame(['run-a', 'run-b', 'run-a', 'run-b'], array_map(
            static fn ($record) => $record['context']['workflow_run_id'],
            $handler->getRecords(),
        ));
    }

    public function testLegacyAndMalformedCarriersStartSeparateSegmentsWithoutWorkerParentage(): void
    {
        foreach ([
            null, [
                'schema' => 'invalid',
            ]] as $carrier) {
            ExecutionTracing::activity(self::fields(), $carrier, static function (): void {
                self::assertNotSame(self::TRACE_ID, Span::getCurrent()->getContext()->getTraceId());
            }, enabled: true);
        }
        foreach ($this->exporter->getSpans() as $span) {
            self::assertFalse($span->getParentContext()->isValid());
        }
    }

    public function testWorkflowFibersInheritTheirCreatorWithoutReplacingApplicationStorage(): void
    {
        $storage = Context::storage();
        $executions = [];
        $observed = [];
        foreach (['run-a', 'run-b'] as $run) {
            ExecutionTracing::activity(self::fields($run), self::carrier(), function () use (
                $run,
                &$executions,
                &$observed
            ): void {
                $parent = ExecutionTracing::current();
                $executions[] = WorkflowExecution::startCallback(function () use ($run, $parent, &$observed): int {
                    self::assertSame($this->provider, Globals::tracerProvider());
                    self::assertSame($parent, ExecutionTracing::current());
                    $observed[] = ExecutionTracing::current()['workflow_run_id'];
                    Fiber::suspend();
                    self::assertSame($parent, ExecutionTracing::current());
                    $observed[] = ExecutionTracing::current()['workflow_run_id'];

                    return $run === 'run-a' ? 7 : 9;
                });
                self::assertSame($parent, ExecutionTracing::current());
            }, enabled: true);
            self::assertSame($this->initial, Context::getCurrent());
        }
        foreach ($executions as $execution) {
            $execution->send(null);
            self::assertSame($this->initial, Context::getCurrent());
        }
        self::assertSame([7, 9], array_map(static fn ($execution) => $execution->getReturn(), $executions));
        self::assertSame(['run-a', 'run-b', 'run-a', 'run-b'], $observed);
        self::assertSame($storage, Context::storage());
    }

    public function testWorkflowFiberFailureAndDiscardUnwindTheBoundScope(): void
    {
        $failure = new RuntimeException('application failure');
        $unwound = [];
        $first = null;
        $second = null;
        ExecutionTracing::activity(self::fields(), self::carrier(), static function () use (
            &$first,
            &$second,
            &$unwound
        ): void {
            $callback = static function (string $id) use (&$unwound): void {
                try {
                    Fiber::suspend();
                } finally {
                    $unwound[$id] = ExecutionTracing::current();
                }
            };
            $first = WorkflowExecution::startCallback($callback, ['failure']);
            $second = WorkflowExecution::startCallback($callback, ['discard']);
        }, enabled: true);
        try {
            $first->throw($failure);
            self::fail('The workflow exception must survive context cleanup.');
        } catch (RuntimeException $observed) {
            self::assertSame($failure, $observed);
        }
        self::assertSame($this->initial, Context::getCurrent());
        unset($second);
        self::assertSame($this->initial, Context::getCurrent());
        self::assertSame(['failure', 'discard'], array_keys($unwound));
        foreach ($unwound as $fields) {
            self::assertSame('run-1', $fields['workflow_run_id']);
            self::assertSame(self::TRACE_ID, $fields['trace_id']);
        }
    }

    public function testUnboundFiberDoesNotReadTheMainFibersDurableContext(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message);
        });
        try {
            ExecutionTracing::activity(self::fields(), self::carrier(), static function (): void {
                $fields = ExecutionTracing::current();
                $fiber = new Fiber(static function (): void {
                    self::assertSame([], ExecutionTracing::current());
                    Fiber::suspend();
                    self::assertSame([], ExecutionTracing::current());
                });
                $fiber->start();
                self::assertSame($fields, ExecutionTracing::current());
                $fiber->resume();
                self::assertSame($fields, ExecutionTracing::current());
            }, enabled: true);
        } finally {
            restore_error_handler();
        }
    }

    public function testDisabledInstrumentationLeavesTheApplicationContextUntouched(): void
    {
        $calls = 0;
        $result = ExecutionTracing::activity(self::fields(), self::carrier(), function () use (&$calls): int {
            ++$calls;
            self::assertSame($this->initial, Context::getCurrent());

            return 9;
        });
        self::assertSame(9, $result);
        self::assertSame(1, $calls);
        self::assertSame([], $this->exporter->getSpans());
    }

    public function testOptionalApiAbsenceStillExecutesTheCallbackExactlyOnce(): void
    {
        $script = <<<'PHP'
        require $argv[1];
        $calls = 0;
        $result = \Workflow\V2\Support\ExecutionTracing::activity([], null, function () use (&$calls) {
            ++$calls;
            return [7, null, '雪'];
        }, enabled: true);
        echo json_encode([$calls, $result, \Workflow\V2\Support\ExecutionTracing::current()]);
        PHP;
        $process = new Process([
            PHP_BINARY,
            '-n',
            '-r',
            $script,
            __DIR__ . '/../../../src/V2/Support/ExecutionTracing.php',
        ]);
        $process->setTimeout(10);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        self::assertSame(
            [1, [7, null, '雪'], []],
            json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testBusinessFailureKeepsItsIdentityAndDoesNotExportItsMessage(): void
    {
        $error = new RuntimeException('sensitive application detail');
        try {
            ExecutionTracing::activity(self::fields(), self::carrier(), static function () use ($error): never {
                throw $error;
            }, enabled: true);
            self::fail('The application exception must survive tracing.');
        } catch (RuntimeException $observed) {
            self::assertSame($error, $observed);
        }
        $span = $this->exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(RuntimeException::class, $span->getAttributes()->get('error.type'));
        self::assertSame([], $span->getEvents());
        self::assertStringNotContainsString('sensitive', $span->getStatus()->getDescription());
    }

    public function testProcessorFailureCannotReplaceTheBusinessResult(): void
    {
        $processor = new class(new InMemoryExporter()) extends SimpleSpanProcessor {
            public int $calls = 0;

            public function onEnd(ReadableSpanInterface $span): void
            {
                ++$this->calls;
                throw new RuntimeException('collector unavailable');
            }
        };
        $provider = new TracerProvider($processor);
        $configuration = Configurator::create()->withTracerProvider($provider)->activate();
        try {
            self::assertSame(
                7,
                ExecutionTracing::activity(self::fields(), self::carrier(), static fn (): int => 7, enabled: true)
            );
            self::assertSame(1, $processor->calls);
            self::assertSame([], ExecutionTracing::current());
        } finally {
            $configuration->detach();
            $provider->shutdown();
        }
    }

    public function testSignalLinksStayOnTheProcessingSpanAndHaveABoundedPerSegmentLifetime(): void
    {
        $run = self::signalRun();
        $first = null;
        $result = ExecutionTracing::workflow(self::fields(), self::carrier(), function () use ($run, &$first): int {
            $nested = $this->provider->getTracer('application')
                ->spanBuilder('application child')
                ->startSpan();
            $scope = $nested->activate();
            try {
                $original = ExecutionTracing::current();
                for ($index = 0; $index < 36; ++$index) {
                    $carrier = self::carrier();
                    $carrier['carrier']['traceparent'] = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-'
                        . str_pad(dechex($index + 1), 16, '0', STR_PAD_LEFT) . '-01';
                    $command = self::signalCommand($carrier, 'signal-' . $index);
                    $first ??= $command;
                    ExecutionTracing::linkSignal($run, $command);
                    self::assertSame($original, ExecutionTracing::current());
                }
                $duplicate = clone $first;
                $duplicate->id = 'another-command-from-the-first-caller';
                ExecutionTracing::linkSignal($run, $duplicate);
            } finally {
                $scope->detach();
                $nested->end();
            }

            return 7;
        }, enabled: true);
        self::assertSame(7, $result);
        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertSame([], $spans[0]->getLinks());
        self::assertCount(32, $spans[1]->getLinks());
        self::assertSame(4, $spans[1]->getAttributes()->get('durable.links.dropped'));
        self::assertSame('signal-0', $spans[1]->getLinks()[0]->getAttributes()->get('workflow_command_id'));
        self::assertSame(self::TRACE_ID, $spans[1]->getContext()->getTraceId());
        self::assertSame(self::PARENT_ID, $spans[1]->getParentContext()->getSpanId());
        ExecutionTracing::linkSignal($run, $first);
        self::assertCount(32, $spans[1]->getLinks());
        ExecutionTracing::workflow(self::fields(), self::carrier(), static function () use ($run, $first): void {
            ExecutionTracing::linkSignal($run, $first);
        }, enabled: true);
        $next = $this->exporter->getSpans()[2];
        self::assertCount(1, $next->getLinks());
        self::assertNull($next->getAttributes()->get('durable.links.dropped'));
    }

    public function testInvalidForeignAndUnboundSignalMetadataCannotLinkOrReplaceTheBusinessResult(): void
    {
        $run = self::signalRun();
        $valid = self::carrier();
        $valid['carrier']['traceparent'] = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';
        $valid['carrier']['baggage'] = 'approved=caller';
        $command = self::signalCommand($valid);
        $result = ExecutionTracing::workflow(self::fields(), self::carrier(), static function () use (
            $run,
            $command,
            $valid
        ): int {
            $original = ExecutionTracing::current();
            $invalid = [null, 'invalid', [
                'schema' => 'unknown',
                'version' => 1,
                'carrier' => [],
            ],
                array_replace($valid, [
                    'version' => 2,
                ]),
                array_replace($valid, [
                    'carrier' => [
                        'traceparent' => str_repeat('a', 257),
                    ],
                ]),
                array_replace($valid, [
                    'carrier' => [
                        'traceparent' => $valid['carrier']['traceparent'],
                        'baggage' => str_repeat('a', 2049),
                    ],
                ]),
                array_replace($valid, [
                    'carrier' => [
                        'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-0000000000000000-01',
                    ],
                ]),
            ];
            foreach ($invalid as $carrier) {
                ExecutionTracing::linkSignal($run, self::signalCommand($carrier));
            }
            foreach ([
                'workflow_run_id' => 'other-run',
                'workflow_instance_id' => 'other-instance',
                'status' => 'rejected',
                'command_type' => 'update',
            ] as $key => $value) {
                $foreign = clone $command;
                $foreign->{$key} = $value;
                ExecutionTracing::linkSignal($run, $foreign);
            }
            $foreignRun = clone $run;
            $foreignRun->namespace = 'other';
            ExecutionTracing::linkSignal($foreignRun, $command);
            $unreadable = new class() extends WorkflowCommand {
                public function getAttribute($key): mixed
                {
                    if ($key === 'observability_context') {
                        throw new RuntimeException('private-telemetry-cast');
                    }

                    return parent::getAttribute($key);
                }
            };
            $unreadable->setRawAttributes($command->getRawOriginal());
            ExecutionTracing::linkSignal($run, $unreadable);
            (new Fiber(static function () use ($run, $command): void {
                ExecutionTracing::linkSignal($run, $command);
            }))->start();
            self::assertSame($original, ExecutionTracing::current());
            ExecutionTracing::linkSignal($run, $command);
            self::assertSame($original, ExecutionTracing::current());

            return 9;
        }, enabled: true);
        self::assertSame(9, $result);
        $span = $this->exporter->getSpans()[0];
        self::assertCount(1, $span->getLinks());
        self::assertSame('signal-1', $span->getLinks()[0]->getAttributes()->get('workflow_command_id'));
        self::assertSame(self::TRACE_ID, $span->getContext()->getTraceId());
        self::assertSame(self::PARENT_ID, $span->getParentContext()->getSpanId());
        self::assertStringNotContainsString(
            'private-telemetry-cast',
            json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR)
        );
        self::assertSame(11, ExecutionTracing::workflow(self::fields(), self::carrier(), static function () use (
            $run,
            $command
        ): int {
            ExecutionTracing::linkSignal($run, $command);

            return 11;
        }, enabled: false));
        self::assertCount(1, $this->exporter->getSpans());
    }

    private static function signalRun(): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->setRawAttributes([
            'id' => 'run-1',
            'workflow_instance_id' => 'instance-1',
            'namespace' => '0',
        ]);

        return $run;
    }

    private static function signalCommand(mixed $carrier, string $id = 'signal-1'): WorkflowCommand
    {
        $command = new WorkflowCommand();
        $command->setRawAttributes([
            'id' => $id,
            'workflow_instance_id' => 'instance-1',
            'workflow_run_id' => 'run-1',
            'command_type' => 'signal',
            'status' => 'accepted',
            'observability_context' => json_encode($carrier, JSON_THROW_ON_ERROR),
        ]);

        return $command;
    }

    private static function fields(string $run = 'run-1', string $attempt = 'attempt-1'): array
    {
        return [
            'workflow_instance_id' => 'instance-1',
            'workflow_run_id' => $run,
            'workflow_type' => 'test.workflow',
            'namespace' => '0',
            'task_id' => 'task-1',
            'activity_id' => 'activity-1',
            'activity_type' => 'test.activity',
            'activity_attempt_id' => $attempt,
            'activity_attempt_number' => 1,
            'request_id' => 'origin-request',
            'arguments' => 'must not be captured',
        ];
    }

    private static function carrier(): array
    {
        return [
            'schema' => 'durable-workflow.trace-context',
            'version' => 1,
            'carrier' => [
                'traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01',
            ],
        ];
    }
}
