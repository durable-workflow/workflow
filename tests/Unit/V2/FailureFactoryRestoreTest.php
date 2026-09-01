<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use ArgumentCountError;
use Error;
use Exception;
use ReflectionProperty;
use RuntimeException;
use Tests\Fixtures\V2\TestAbstractReplayedException;
use Tests\NonDatabaseTestCase;
use TypeError;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\StructuralLimitKind;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Support\FailureFactory;

final class FailureFactoryRestoreTest extends NonDatabaseTestCase
{
    /**
     * Regression for #436. PHP's Throwable interface is implemented independently
     * by Exception and Error (siblings, not parent/child). The restorer used
     * is_subclass_of($class, Error::class) to decide which base-class reflection
     * surface owns the protected message/code/file/line/trace properties — but
     * is_subclass_of returns false when $class IS Error, so an activity that
     * threw a bare Error fell through to Exception's reflection target and
     * raised "Cannot access protected property Error::$message" during replay,
     * stranding the run in waiting.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errorSubclassesProvider')]
    public function testRestoresErrorSubclassesWithoutFallingBackToExceptionBaseClass(
        string $class,
        string $message
    ): void {
        $payload = FailureFactory::payload(new $class($message));

        $restored = FailureFactory::restoreForReplay($payload);

        $this->assertInstanceOf($class, $restored);
        $this->assertSame($message, $restored->getMessage());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function errorSubclassesProvider(): iterable
    {
        yield 'bare Error' => [Error::class, 'static call against instance method'];
        yield 'TypeError' => [TypeError::class, 'argument 1 must be of type string'];
        yield 'ArgumentCountError' => [ArgumentCountError::class, 'too few arguments to function'];
    }

    public function testRestoresExceptionSubclassesUnchanged(): void
    {
        $original = new RuntimeException('still works for Exception side', 42);

        $restored = FailureFactory::restoreForReplay(FailureFactory::payload($original));

        $this->assertInstanceOf(RuntimeException::class, $restored);
        $this->assertSame('still works for Exception side', $restored->getMessage());
        $this->assertSame(42, $restored->getCode());
    }

    public function testRestoresBaseException(): void
    {
        $original = new Exception('base exception sanity check');

        $restored = FailureFactory::restoreForReplay(FailureFactory::payload($original));

        $this->assertInstanceOf(Exception::class, $restored);
        $this->assertSame('base exception sanity check', $restored->getMessage());
    }

    public function testStructuralLimitPropertiesRoundTripThroughAvro(): void
    {
        $original = StructuralLimitExceededException::payloadSize(200, 64);
        $payload = FailureFactory::payload($original);
        $properties = array_column($payload['properties'], 'value', 'name');

        $this->assertSame(StructuralLimitKind::PayloadSize->value, $properties['limitKind']);
        $this->assertSame(200, $properties['currentValue']);
        $this->assertSame(64, $properties['configuredLimit']);

        $decoded = Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $payload));
        $restored = FailureFactory::restoreForReplay($decoded);

        $this->assertInstanceOf(StructuralLimitExceededException::class, $restored);
        $this->assertSame(StructuralLimitKind::PayloadSize, $restored->limitKind);
        $this->assertSame(200, $restored->currentValue);
        $this->assertSame(64, $restored->configuredLimit);
    }

    public function testReplayPreservesStructuredFailureMetadata(): void
    {
        $payload = [
            'class' => RuntimeException::class,
            'type' => 'planned.python.failure',
            'message' => 'planned python failure',
            'non_retryable' => true,
            'details_payload_codec' => 'avro',
            'details' => Serializer::serializeWithCodec('avro', [
                'label' => 'planned-python-failure',
            ]),
        ];

        $restored = FailureFactory::restoreForReplay($payload);

        $this->assertInstanceOf(RestoredWorkflowException::class, $restored);
        $this->assertSame('planned python failure', $restored->getMessage());
        $failurePayload = $restored->failurePayload();

        $this->assertSame(RuntimeException::class, $failurePayload['class'] ?? null);
        $this->assertSame('planned.python.failure', $failurePayload['type'] ?? null);
        $this->assertSame('planned python failure', $failurePayload['message'] ?? null);
        $this->assertSame(0, $failurePayload['code'] ?? null);
        $this->assertTrue($failurePayload['non_retryable'] ?? false);
        $this->assertSame('avro', $failurePayload['details_payload_codec'] ?? null);
        $this->assertSame($payload['details'], $failurePayload['details'] ?? null);
    }

    public function testReplayPreservesExternalLogicalTypeWhenRecordedClassIsGeneric(): void
    {
        $payload = [
            'class' => RuntimeException::class,
            'type' => 'TypedCancelFlightError',
            'message' => 'cancel_flight typed compensation failure',
        ];

        $restored = FailureFactory::restoreForReplay($payload);

        $this->assertInstanceOf(RuntimeException::class, $restored);
        $this->assertInstanceOf(RestoredWorkflowException::class, $restored);
        $this->assertSame('cancel_flight typed compensation failure', $restored->getMessage());
        $this->assertSame('TypedCancelFlightError', $restored->failurePayload()['type'] ?? null);
    }

    public function testRestoreRehydratesKnownThrowableAndFallsBackForUnrestorableClasses(): void
    {
        $restored = FailureFactory::restore([
            'class' => RuntimeException::class,
            'message' => 'known runtime failure',
            'code' => 17,
            'properties' => 'malformed legacy properties',
        ]);

        $this->assertInstanceOf(RuntimeException::class, $restored);
        $this->assertSame('known runtime failure', $restored->getMessage());
        $this->assertSame(17, $restored->getCode());

        $abstract = FailureFactory::restore([
            'class' => TestAbstractReplayedException::class,
            'message' => 'abstract failure',
            'code' => 0,
        ]);

        $this->assertInstanceOf(RestoredWorkflowException::class, $abstract);
        $this->assertSame(TestAbstractReplayedException::class, $abstract->failurePayload()['class']);

        $missing = FailureFactory::restore([
            'class' => 'App\\Exceptions\\RemovedWorkflowException',
            'message' => 'removed failure',
            'code' => 0,
        ]);

        $this->assertInstanceOf(RestoredWorkflowException::class, $missing);
        $this->assertSame('removed failure', $missing->getMessage());
    }

    public function testExternalWorkerStringFailureUsesFallbackMetadata(): void
    {
        $restored = FailureFactory::restoreExternalWorkerFailure(
            'worker failed before returning structured details',
            RuntimeException::class,
            'unused fallback message',
            23,
        );

        $payload = $restored->failurePayload();

        $this->assertSame(RuntimeException::class, $payload['class']);
        $this->assertSame('worker failed before returning structured details', $payload['message']);
        $this->assertSame(23, $payload['code']);
    }

    public function testRestoreAcceptsAvroEncodedFailurePayload(): void
    {
        $payload = FailureFactory::payload(new RuntimeException('encoded failure', 41));
        $blob = Serializer::serializeWithCodec('avro', $payload);

        $restored = FailureFactory::restoreForReplay($blob);

        $this->assertInstanceOf(RuntimeException::class, $restored);
        $this->assertSame('encoded failure', $restored->getMessage());
        $this->assertSame(41, $restored->getCode());
    }

    public function testFailurePayloadPreservesPortableCustomStateAndSkipsUnsupportedState(): void
    {
        $payload = FailureFactory::payload(new PortableFailureStateException());
        $properties = array_column($payload['properties'], 'value', 'name');

        $this->assertSame(PortableFailureState::Waiting->name, $properties['state']);
        $this->assertInstanceOf(AvroBinaryValue::class, $properties['binary']);
        $this->assertSame("\x00\xff", $properties['binary']->bytes);
        $this->assertInstanceOf(AvroMapValue::class, $properties['metadata']);
        $this->assertSame(7.0, $properties['ratio']);
        $this->assertSame('loose value', $properties['loose']);
        $this->assertNull($properties['nullable']);

        foreach ([
            'uninitialized',
            'unsupported',
            'unsupportedMap',
            'invalidKeyedArray',
            'invalidNestedArray',
            'nonFinite',
        ] as $omittedProperty) {
            $this->assertArrayNotHasKey($omittedProperty, $properties);
        }

        $decoded = Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $payload));
        $restored = FailureFactory::restoreForReplay($decoded);

        $this->assertInstanceOf(PortableFailureStateException::class, $restored);
        $this->assertSame(PortableFailureState::Waiting, $restored->state);
        $this->assertSame("\x00\xff", $restored->binary->bytes);
        $this->assertEquals(
            [
                'state' => PortableFailureState::Waiting->name,
                'binary' => AvroBinaryValue::fromBytes("\x10\xfe"),
            ],
            $restored->metadata,
        );
        $this->assertEquals(
            [PortableFailureState::Complete->name, AvroBinaryValue::fromBytes("\x20\xfd")],
            $restored->items,
        );
        $this->assertSame(7.0, $restored->ratio);
        $this->assertSame('loose value', $restored->loose);
        $this->assertNull($restored->nullable);

        foreach ([
            'uninitialized',
            'unsupported',
            'unsupportedMap',
            'invalidKeyedArray',
            'invalidNestedArray',
            'nonFinite',
        ] as $property) {
            $this->assertFalse((new ReflectionProperty($restored, $property))->isInitialized($restored));
        }
    }

    public function testRestoreToleratesStaleAndMalformedCustomPropertyFrames(): void
    {
        $restored = FailureFactory::restore([
            'class' => PortableFailureStateException::class,
            'message' => 'stale property metadata',
            'code' => 0,
            'properties' => [
                [
                    'declaring_class' => 123,
                    'name' => false,
                    'value' => 'ignored',
                ],
                [
                    'declaring_class' => \stdClass::class,
                    'name' => 'nullable',
                ],
                [
                    'declaring_class' => PortableFailureStateException::class,
                    'name' => 'renamedProperty',
                    'value' => 'old value',
                ],
                [
                    'declaring_class' => PortableFailureStateException::class,
                    'name' => 'state',
                    'value' => 'RemovedCase',
                ],
                [
                    'declaring_class' => PortableFailureStateException::class,
                    'name' => 'loose',
                    'value' => 'restored loose value',
                ],
            ],
        ]);

        $this->assertInstanceOf(PortableFailureStateException::class, $restored);
        $this->assertNull($restored->nullable);
        $this->assertSame('restored loose value', $restored->loose);
        $this->assertFalse((new ReflectionProperty($restored, 'state'))->isInitialized($restored));
    }

    public function testExternalWorkerFailurePreservesDiagnosticMaps(): void
    {
        $restored = FailureFactory::restoreExternalWorkerFailure([
            'class' => RuntimeException::class,
            'message' => 'diagnosed worker failure',
            'diagnostics' => [
                'attempt' => 3,
            ],
            'runtime_diagnostics' => [
                'worker_id' => 'worker-7',
            ],
        ]);

        $payload = $restored->failurePayload();

        $this->assertSame([
            'attempt' => 3,
        ], $payload['diagnostics']);
        $this->assertSame([
            'worker_id' => 'worker-7',
        ], $payload['runtime_diagnostics']);
    }
}

enum PortableFailureState
{
    case Waiting;
    case Complete;
}

final class PortableFailureStateException extends RuntimeException
{
    public string $uninitialized;

    public PortableFailureState $state;

    public AvroBinaryValue $binary;

    public mixed $metadata;

    /**
     * @var list<mixed>
     */
    public array $items;

    public float $ratio;

    public mixed $unsupported;

    public AvroMapValue $unsupportedMap;

    /**
     * @var array<int|string, string>
     */
    public array $invalidKeyedArray;

    /**
     * @var array<string, mixed>
     */
    public array $invalidNestedArray;

    public float $nonFinite;

    public $loose;

    public ?string $nullable;

    public function __construct()
    {
        parent::__construct('portable failure state', 29);

        $this->state = PortableFailureState::Waiting;
        $this->binary = AvroBinaryValue::fromBytes("\x00\xff");
        $this->metadata = AvroMapValue::fromPairs([
            ['state', PortableFailureState::Waiting],
            ['binary', AvroBinaryValue::fromBytes("\x10\xfe")],
        ]);
        $this->items = [PortableFailureState::Complete, AvroBinaryValue::fromBytes("\x20\xfd")];
        $this->ratio = 7.0;
        $this->unsupported = new \stdClass();
        $this->unsupportedMap = AvroMapValue::fromPairs([['unsupported', new \stdClass()]]);
        $this->invalidKeyedArray = [
            1 => 'integer key',
            'valid' => 'string key',
        ];
        $this->invalidNestedArray = [
            'unsupported' => new \stdClass(),
        ];
        $this->nonFinite = INF;
        $this->loose = 'loose value';
        $this->nullable = null;
    }
}
