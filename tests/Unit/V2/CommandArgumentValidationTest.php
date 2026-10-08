<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class CommandArgumentValidationTest extends NonDatabaseTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function commandFamilies(): iterable
    {
        yield 'activity' => [[
            'type' => 'schedule_activity',
            'activity_type' => 'SendOrder',
        ]];
        yield 'child workflow' => [[
            'type' => 'start_child_workflow',
            'workflow_type' => 'OrderWorkflow',
        ]];
        yield 'continue as new' => [[
            'type' => 'continue_as_new',
            'workflow_type' => 'OrderWorkflow',
        ]];
    }

    #[DataProvider('commandFamilies')]
    public function testRawAndEnvelopedArgumentsPreserveBytesAndTheirCanonicalCodec(array $base): void
    {
        $blob = Serializer::serializeWithCodec('avro', [false, 0, '', [
            'order' => 'order-a',
        ]]);
        foreach ([
            'raw with explicit codec' => [
                'arguments' => $blob,
                'payload_codec' => 'avro',
            ],
            'raw with padded codec' => [
                'arguments' => $blob,
                'payload_codec' => ' avro ',
            ],
            'envelope infers codec' => [
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => $blob,
                ],
            ],
            'envelope with explicit codec' => [
                'arguments' => [
                    'blob' => $blob,
                    'codec' => 'avro',
                ],
                'payload_codec' => 'avro',
            ],
        ] as $label => $options) {
            $commands = [array_replace($base, $options)];
            $before = $commands;
            $expected = [array_replace($base, [
                'arguments' => $blob,
                'payload_codec' => 'avro',
            ])];
            $normalized = WorkflowCommandNormalizer::normalize($commands);
            $expectedFields = $expected[0];
            $actualFields = $normalized[0];
            ksort($expectedFields);
            ksort($actualFields);
            $this->assertSame([$expectedFields], [$actualFields], $label);
            $this->assertSame($normalized, WorkflowCommandNormalizer::normalize($normalized), $label);
            $this->assertSame($before, $commands, $label);
        }
    }

    #[DataProvider('commandFamilies')]
    public function testAbsentArgumentsOmitPayloadAndCodecWithoutChangingInput(array $base): void
    {
        foreach ([
            'omitted' => [],
            'null' => [
                'arguments' => null,
            ],
            'empty raw string' => [
                'arguments' => '',
            ],
            'null with valid explicit codec' => [
                'arguments' => null,
                'payload_codec' => 'avro',
            ],
        ] as $label => $options) {
            $commands = [array_replace($base, $options)];
            $before = $commands;
            $normalized = WorkflowCommandNormalizer::normalize($commands);
            $this->assertSame([$base], $normalized, $label);
            $this->assertSame($normalized, WorkflowCommandNormalizer::normalize($normalized), $label);
            $this->assertSame($before, $commands, $label);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string, string}>
     */
    public static function malformedArguments(): iterable
    {
        $argumentMessage = 'Workflow task command field [arguments] must be a string or a payload envelope when provided.';
        $codecMessage = 'The payload envelope codec must be a non-empty string.';
        $tagMessage = 'Workflow task command field [payload_codec] must be a non-empty string when provided.';
        $cases = [];
        foreach ([
            'boolean arguments' => false,
            'integer arguments' => 7,
            'fractional arguments' => 1.5,
            'object arguments' => (object) [
                'marker' => 'unchanged',
            ],
        ] as $label => $value) {
            $cases[$label] = [[
                'arguments' => $value,
            ], 'arguments', $argumentMessage];
        }
        foreach ([
            'empty codec' => '',
            'null codec' => null,
            'integer codec' => 7,
        ] as $label => $value) {
            $cases[$label] = [[
                'arguments' => [
                    'codec' => $value,
                    'blob' => 'opaque',
                ],
            ], 'arguments.codec', $codecMessage];
        }
        foreach ([
            'null blob' => null,
            'integer blob' => 7,
            'array blob' => [],
        ] as $label => $value) {
            $cases[$label] = [[
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => $value,
                ],
            ], 'arguments.blob', 'The payload envelope blob must be a string.'];
        }
        $cases['external envelope without storage driver'] = [[
            'arguments' => [
                'codec' => 'avro',
                'external_storage' => [],
            ],
        ], 'arguments.external_storage', 'External payload references require an external storage driver.'];
        foreach ([
            'empty explicit codec' => '',
            'blank explicit codec' => ' ',
            'integer explicit codec' => 7,
            'boolean explicit codec' => false,
            'array explicit codec' => [],
        ] as $label => $value) {
            $cases[$label] = [[
                'payload_codec' => $value,
            ], 'payload_codec', $tagMessage];
        }
        foreach (self::commandFamilies() as $family => [$base]) {
            foreach ($cases as $label => [$options, $field, $message]) {
                yield $family . ': ' . $label => [$base, $options, $field, $message];
            }
        }
    }

    #[DataProvider('malformedArguments')]
    public function testMalformedArgumentsRejectTheWholeBatchWithIndexedDiagnostics(
        array $base,
        array $options,
        string $field,
        string $message
    ): void {
        $commands = [[
            'type' => 'start_timer',
            'delay_seconds' => 0,
        ], array_replace($base, [
            'arguments' => Serializer::serializeWithCodec('avro', ['order-a']),
            'payload_codec' => 'avro',
        ], $options)];
        $before = serialize($commands);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->assertSame([
                'commands.1.' . $field => [$message],
            ], $this->errors($commands));
            $this->assertSame($before, serialize($commands));
        }
    }

    public function testIndependentArgumentErrorsKeepTheirCommandAndEnvelopeAddresses(): void
    {
        $commands = [[
            'type' => 'start_timer',
            'delay_seconds' => 0,
        ], [
            'type' => 'schedule_activity',
            'activity_type' => 'SendOrder',
            'arguments' => false,
        ], [
            'type' => 'start_child_workflow',
            'workflow_type' => 'OrderWorkflow',
            'arguments' => [
                'codec' => 'avro',
                'blob' => 7,
            ],
        ], [
            'type' => 'continue_as_new',
            'workflow_type' => 'OrderWorkflow',
            'arguments' => [
                'codec' => '',
                'blob' => 'opaque',
            ],
            'payload_codec' => false,
        ]];
        $expected = [
            'commands.1.arguments' => [
                'Workflow task command field [arguments] must be a string or a payload envelope when provided.',
            ],
            'commands.2.arguments.blob' => ['The payload envelope blob must be a string.'],
            'commands.3.arguments.codec' => ['The payload envelope codec must be a non-empty string.'],
            'commands.3.payload_codec' => [
                'Workflow task command field [payload_codec] must be a non-empty string when provided.',
            ],
        ];
        $before = $commands;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->assertSame($expected, $this->errors($commands));
            $this->assertSame($before, $commands);
        }
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @return array<string, list<string>>
     */
    private function errors(array $commands): array
    {
        try {
            WorkflowCommandNormalizer::normalize($commands);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('Malformed arguments must reject the entire command batch.');
    }
}
