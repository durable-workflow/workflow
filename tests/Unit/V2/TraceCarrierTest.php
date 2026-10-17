<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Fiber;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\TraceCarrier;

final class TraceCarrierTest extends TestCase
{
    private const PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function testPreparedAdmissionCannotLeakIntoAnUnboundFiber(): void
    {
        $initial = Context::getCurrent();
        TraceCarrier::withPreparedAdmission(static function (): void {
            self::assertTrue(TraceCarrier::hasPreparedAdmission());
            $fiber = new Fiber(static fn (): bool => TraceCarrier::hasPreparedAdmission());
            $fiber->start();
            self::assertFalse($fiber->getReturn());
            self::assertTrue(TraceCarrier::hasPreparedAdmission());
        });
        self::assertFalse(TraceCarrier::hasPreparedAdmission());
        self::assertSame($initial, Context::getCurrent());
    }

    public function testPreparedAdmissionCanNestWithinAnExplicitlyBoundFiber(): void
    {
        $initial = Context::getCurrent();
        $fiber = new Fiber(static function () use ($initial): void {
            $bound = $initial->activate();
            try {
                TraceCarrier::withPreparedAdmission(static function (): void {
                    $outer = Context::getCurrent();
                    self::assertTrue(TraceCarrier::hasPreparedAdmission());
                    TraceCarrier::withPreparedAdmission(static function (): void {
                        self::assertTrue(TraceCarrier::hasPreparedAdmission());
                    });
                    self::assertTrue(TraceCarrier::hasPreparedAdmission());
                    self::assertSame($outer, Context::getCurrent());
                });
                self::assertFalse(TraceCarrier::hasPreparedAdmission());
            } finally {
                $bound->detach();
            }
        });
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
        self::assertSame($initial, Context::getCurrent());
    }

    public function testPreparedAdmissionRestoresItsScopeAfterTheOriginalFailure(): void
    {
        $initial = Context::getCurrent();
        $failure = new RuntimeException('original admission failure');
        $calls = 0;
        try {
            TraceCarrier::withPreparedAdmission(static function () use ($failure, &$calls): never {
                ++$calls;
                throw $failure;
            });
            self::fail('The original business failure must be preserved.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $calls);
        self::assertFalse(TraceCarrier::hasPreparedAdmission());
        self::assertSame($initial, Context::getCurrent());
    }

    public function testValidCarrierPreservesTraceIdentityAndState(): void
    {
        $input = self::envelope([
            'traceparent' => self::PARENT,
            'tracestate' => 'vendor=opaque',
        ]);
        $this->assertSame($input, TraceCarrier::normalize($input));
    }

    public function testAbsentStoredMetadataDoesNotRequireApplicationInstrumentation(): void
    {
        $run = new WorkflowRun();
        $this->assertNull(TraceCarrier::fromRun($run));
        $run->setRawAttributes([
            'observability_context' => null,
        ]);
        $this->assertNull(TraceCarrier::fromRun($run));
    }

    public function testMetadataReadFailureIsDiscardedWithoutInterruptingExecution(): void
    {
        $run = new class() extends WorkflowRun {
            public function getAttributes(): array
            {
                return [
                    'observability_context' => 'unreadable',
                ];
            }

            public function getAttribute($key): mixed
            {
                throw new RuntimeException('optional telemetry cast failed');
            }
        };
        $this->assertNull(TraceCarrier::fromRun($run));
    }

    public function testBaggageIsAbsentUnlessExplicitlyAllowlisted(): void
    {
        $input = self::envelope([
            'traceparent' => self::PARENT,
            'baggage' => 'approved=hello%20world;property=yes,private=secret',
        ]);
        $this->assertSame(self::envelope([
            'traceparent' => self::PARENT,
        ]), TraceCarrier::normalize($input));
        $result = TraceCarrier::normalize($input, ['approved']);
        $this->assertNotNull($result);
        $context = BaggagePropagator::getInstance()->extract($result['carrier'], context: Context::getRoot());
        $baggage = Baggage::fromContext($context);
        $this->assertSame('hello world', $baggage->getValue('approved'));
        $this->assertSame('property=yes', $baggage->getEntry('approved')?->getMetadata()->getValue());
        $this->assertNull($baggage->getEntry('private'));
        $this->assertSame($result, TraceCarrier::normalize($result, ['approved']));
    }

    public function testExactlyThirtyTwoBaggageEntriesAreAllowed(): void
    {
        $keys = array_map(static fn (int $i): string => 'key' . $i, range(1, 33));
        $header = implode(',', array_map(static fn (string $key): string => $key . '=value', $keys));
        $input = self::envelope([
            'traceparent' => self::PARENT,
            'baggage' => $header,
        ]);
        $this->assertNotNull(TraceCarrier::normalize($input, array_slice($keys, 0, 32)));
        $this->assertNull(TraceCarrier::normalize($input, $keys));
    }

    public function testCaptureUsesTheApplicationPropagatorAndRestoresItsScope(): void
    {
        $initial = Context::getCurrent();
        $configured = Configurator::create()->withPropagator(new MultiTextMapPropagator([
            TraceContextPropagator::getInstance(),
            BaggagePropagator::getInstance(),
        ]))->activate();
        $parent = TraceContextPropagator::getInstance()->extract([
            'traceparent' => self::PARENT,
        ]);
        $parent = Baggage::getBuilder()->set('approved', 'value')->set('private', 'secret')->build()->storeInContext(
            $parent
        );
        $scope = $parent->activate();
        try {
            $result = TraceCarrier::capture(['approved']);
            $this->assertNotNull($result);
            $this->assertSame(self::PARENT, $result['carrier']['traceparent']);
            $this->assertSame('approved=value', $result['carrier']['baggage']);
            $this->assertSame($parent, Context::getCurrent());
            $this->assertNull(TraceCarrier::normalize(self::envelope([
                'traceparent' => 'invalid',
            ])));
            $this->assertSame($parent, Context::getCurrent());
            $this->assertTrue(Span::getCurrent()->getContext()->isValid());
        } finally {
            $scope->detach();
            $configured->detach();
        }
        $this->assertSame($initial, Context::getCurrent());
    }

    #[DataProvider('invalidCarriers')]
    public function testInvalidTelemetryIsDiscarded(mixed $input): void
    {
        $this->assertNull(TraceCarrier::normalize($input, ['approved']));
    }

    public static function invalidCarriers(): array
    {
        return [
            'absent' => [null],
            'string' => ['invalid'],
            'unknown schema' => [[
                'schema' => 'other',
                'version' => 1,
                'carrier' => [
                    'traceparent' => self::PARENT,
                ],
            ]],
            'future version' => [[
                'schema' => 'durable-workflow.trace-context',
                'version' => 2,
                'carrier' => [
                    'traceparent' => self::PARENT,
                ],
            ]],
            'string version' => [[
                'schema' => 'durable-workflow.trace-context',
                'version' => '1',
                'carrier' => [
                    'traceparent' => self::PARENT,
                ],
            ]],
            'unknown metadata' => [
                self::envelope([
                    'traceparent' => self::PARENT,
                ]) + [
                    'input' => 'private',
                ]],
            'missing traceparent' => [
                self::envelope([
                    'tracestate' => 'vendor=value',
                ])],
            'malformed traceparent' => [
                self::envelope([
                    'traceparent' => 'invalid',
                ])],
            'zero trace identity' => [
                self::envelope([
                    'traceparent' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
                ])],
            'zero span identity' => [
                self::envelope([
                    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
                ])],
            'oversized traceparent' => [
                self::envelope([
                    'traceparent' => str_repeat('a', 257),
                ])],
            'oversized tracestate' => [
                self::envelope([
                    'traceparent' => self::PARENT,
                    'tracestate' => str_repeat('a', 513),
                ])],
            'oversized baggage' => [
                self::envelope([
                    'traceparent' => self::PARENT,
                    'baggage' => str_repeat('a', 2049),
                ])],
            'array header' => [
                self::envelope([
                    'traceparent' => [self::PARENT],
                ])],
            'unknown header' => [
                self::envelope([
                    'traceparent' => self::PARENT,
                    'authorization' => 'private',
                ])],
            'invalid utf8' => [
                self::envelope([
                    'traceparent' => self::PARENT,
                    'baggage' => "approved=\xff",
                ])],
        ];
    }

    private static function envelope(array $carrier): array
    {
        return [
            'schema' => 'durable-workflow.trace-context',
            'version' => 1,
            'carrier' => $carrier,
        ];
    }
}
