<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class ServiceCommandOptionValidationTest extends NonDatabaseTestCase
{
    public function testServiceMetadataAndPrincipalOptionsRetainTheirTypedValues(): void
    {
        $base = $this->command();
        $input = array_replace($base, [
            'mode_override' => ' ASYNC ',
            'wait_for' => ' COMPLETED ',
            'wait_timeout_seconds' => 0,
            'namespace' => ' tenant-a ',
            'caller_namespace' => ' caller-a ',
            'labels' => [
                'region' => 'west',
            ],
            'memo' => [
                'reviewed' => false,
            ],
            'search_attributes' => [
                'attempt' => 0,
            ],
            'metadata' => [
                'trace' => 'trace-a',
            ],
            'principal_subject' => ' worker-a ',
            'principal_method' => ' service-key ',
            'principal_roles' => [' reader ', 'writer'],
            'principal_tenant' => ' tenant-a ',
            'principal_claims' => [
                'delegated' => false,
            ],
        ]);
        $expected = array_replace($base, [
            'namespace' => 'tenant-a',
            'caller_namespace' => 'caller-a',
            'mode_override' => 'async',
            'wait_for' => 'completed',
            'wait_timeout_seconds' => 0,
            'labels' => [
                'region' => 'west',
            ],
            'memo' => [
                'reviewed' => false,
            ],
            'search_attributes' => [
                'attempt' => 0,
            ],
            'metadata' => [
                'trace' => 'trace-a',
            ],
            'principal_subject' => 'worker-a',
            'principal_method' => 'service-key',
            'principal_roles' => ['reader', 'writer'],
            'principal_tenant' => 'tenant-a',
            'principal_claims' => [
                'delegated' => false,
            ],
        ]);
        $before = $input;

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $normalized = WorkflowCommandNormalizer::normalize([$input]);
            $this->assertSame([$expected], $normalized);
            $this->assertSame($normalized, WorkflowCommandNormalizer::normalize($normalized));
            $this->assertSame($before, $input);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function optionalValues(): iterable
    {
        yield 'all omitted' => [[], []];
        yield 'all explicit nulls' => [[
            'wait_timeout_seconds' => null,
            'labels' => null,
            'memo' => null,
            'search_attributes' => null,
            'metadata' => null,
            'principal_roles' => null,
            'principal_claims' => null,
        ], []];
        yield 'zero wait' => [[
            'wait_timeout_seconds' => 0,
        ], [
            'wait_timeout_seconds' => 0,
        ]];
        yield 'positive wait' => [[
            'wait_timeout_seconds' => 7,
        ], [
            'wait_timeout_seconds' => 7,
        ]];
        yield 'empty metadata and roles remain explicit' => [[
            'labels' => [],
            'memo' => [],
            'search_attributes' => [],
            'metadata' => [],
            'principal_roles' => [],
            'principal_claims' => [],
        ], [
            'labels' => [],
            'memo' => [],
            'search_attributes' => [],
            'metadata' => [],
            'principal_roles' => [],
            'principal_claims' => [],
        ]];
    }

    #[DataProvider('optionalValues')]
    public function testOptionalNullAndEmptyValuesHavePredictableCommandShapes(array $options, array $expected): void
    {
        $input = array_replace($this->command(), $options);
        $before = $input;
        $output = WorkflowCommandNormalizer::normalize([$input]);
        $this->assertSame([array_replace($this->command(), $expected)], $output);
        $this->assertSame($output, WorkflowCommandNormalizer::normalize($output));
        $this->assertSame($before, $input);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, list<string>>}>
     */
    public static function invalidOptions(): iterable
    {
        foreach (['labels', 'memo', 'search_attributes', 'metadata', 'principal_claims'] as $field) {
            foreach ([
                'string' => 'not-an-object',
                'boolean' => false,
            ] as $kind => $value) {
                yield $field . ' ' . $kind => [[
                    $field => $value,
                ], [
                    'commands.1.' . $field => [
                        'Workflow task command field [' . $field . '] must be an object when provided.',
                    ],
                ]];
            }
        }
        foreach ([
            'negative' => -1,
            'numeric string' => '1',
            'boolean' => false,
            'fraction' => 1.5,
            'array' => [],
        ] as $kind => $value) {
            yield 'wait ' . $kind => [[
                'wait_timeout_seconds' => $value,
            ], [
                'commands.1.wait_timeout_seconds' => [
                    'Workflow task command field [wait_timeout_seconds] must be a non-negative integer when provided.',
                ],
            ]];
        }
        foreach ([
            'string' => 'reader',
            'boolean' => false,
            'integer' => 1,
        ] as $kind => $value) {
            yield 'roles ' . $kind => [[
                'principal_roles' => $value,
            ], [
                'commands.1.principal_roles' => [
                    'Workflow task command field [principal_roles] must be a list of strings when provided.',
                ],
            ]];
        }
        foreach ([
            'empty' => '',
            'blank' => '  ',
            'non-string' => false,
        ] as $kind => $value) {
            yield 'role entry ' . $kind => [[
                'principal_roles' => ['reader', $value],
            ], [
                'commands.1.principal_roles.1' => [
                    'Workflow task command field [principal_roles] entries must be non-empty strings.',
                ],
            ]];
        }
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsRejectTheEntireIndexedBatchWithoutChangingInput(
        array $options,
        array $expected
    ): void {
        $commands = [$this->command(), array_replace($this->command(), $options)];
        $before = $commands;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->assertSame($expected, $this->errors($commands));
            $this->assertSame($before, $commands);
        }
    }

    public function testIndependentInvalidMetadataFieldsReportAllTheirErrors(): void
    {
        $commands = [$this->command(), array_replace($this->command(), [
            'wait_timeout_seconds' => -1,
            'labels' => false,
            'memo' => 'not-an-object',
            'search_attributes' => false,
            'metadata' => 'not-an-object',
            'principal_roles' => ['reader', ' ', 3],
            'principal_claims' => false,
        ])];
        $expected = [
            'commands.1.wait_timeout_seconds' => [
                'Workflow task command field [wait_timeout_seconds] must be a non-negative integer when provided.',
            ],
            'commands.1.labels' => ['Workflow task command field [labels] must be an object when provided.'],
            'commands.1.memo' => ['Workflow task command field [memo] must be an object when provided.'],
            'commands.1.search_attributes' => [
                'Workflow task command field [search_attributes] must be an object when provided.',
            ],
            'commands.1.metadata' => ['Workflow task command field [metadata] must be an object when provided.'],
            'commands.1.principal_roles.1' => [
                'Workflow task command field [principal_roles] entries must be non-empty strings.',
            ],
            'commands.1.principal_roles.2' => [
                'Workflow task command field [principal_roles] entries must be non-empty strings.',
            ],
            'commands.1.principal_claims' => [
                'Workflow task command field [principal_claims] must be an object when provided.',
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
        $this->fail('Invalid service options must reject the complete command batch.');
    }

    /**
     * @return array<string, mixed>
     */
    private function command(): array
    {
        return [
            'type' => 'start_service_operation',
            'endpoint_name' => 'orders',
            'service_name' => 'OrderService',
            'operation_name' => 'reserve',
            'request_payload' => Serializer::serializeWithCodec('avro', ['order-a']),
            'payload_codec' => 'avro',
        ];
    }
}
