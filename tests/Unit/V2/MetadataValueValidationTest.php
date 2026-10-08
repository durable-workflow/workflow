<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class MetadataValueValidationTest extends NonDatabaseTestCase
{
    /**
     * @return iterable<string, array{array<string|int, mixed>, string}>
     */
    public static function invalidSearchAttributeValues(): iterable
    {
        $keyMessage = 'Workflow v2 search attribute keys must be 1-64 URL-safe characters using letters, numbers, ".", "_", "-", and ":".';
        yield 'integer key' => [[7 => 'value'], $keyMessage];
        yield 'empty key' => [['' => 'value'], $keyMessage];
        yield 'space in key' => [['order status' => 'value'], $keyMessage];
        yield 'oversized key' => [[str_repeat('k', 65) => 'value'], $keyMessage];
        yield 'associative list' => [['tags' => ['first' => 'alpha']],
            'Workflow v2 search attribute [tags] list value must be a JSON array.'];
        yield 'integer list entry' => [['tags' => ['alpha', 0]],
            'Workflow v2 search attribute [tags] list values must contain only strings.'];
        yield 'null list entry' => [['tags' => ['alpha', null]],
            'Workflow v2 search attribute [tags] list values must contain only strings.'];
        yield 'oversized keyword' => [['tags' => [str_repeat('x', WorkflowSearchAttribute::MAX_KEYWORD_LENGTH + 1)]],
            sprintf('Workflow v2 search attribute [tags] list values must be up to %d characters.', WorkflowSearchAttribute::MAX_KEYWORD_LENGTH)];
        yield 'oversized string' => [['status' => str_repeat('x', WorkflowSearchAttribute::MAX_STRING_LENGTH + 1)],
            sprintf('Workflow v2 search attribute [status] must be up to %d characters.', WorkflowSearchAttribute::MAX_STRING_LENGTH)];
    }

    /**
     * @param array<string|int, mixed> $attributes
     */
    #[DataProvider('invalidSearchAttributeValues')]
    public function testMalformedSearchAttributeValuesRetainCompleteIndexedDiagnostics(array $attributes, string $message): void
    {
        $commands = [[
            'type' => 'upsert_search_attributes',
            'attributes' => $attributes,
        ]];
        $before = $commands;
        $expected = ['commands.0.attributes' => [$message]];

        $this->assertSame($expected, $this->errors($commands));
        $this->assertSame($expected, $this->errors($commands));
        $this->assertSame($before, $commands);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function absentMemoEnvelopes(): iterable
    {
        yield 'omitted entries' => [[]];
        yield 'null entries' => [['entries' => null]];
        yield 'empty entries' => [['entries' => []]];
        yield 'string entries' => [['entries' => 'raw-data']];
        yield 'boolean entries' => [['entries' => false]];
        yield 'integer entries' => [['entries' => 0]];
    }

    #[DataProvider('absentMemoEnvelopes')]
    public function testMissingMemoEnvelopeHasTheSameFieldSpecificFailureOnRepeat(array $options): void
    {
        $commands = [['type' => 'upsert_memo'] + $options];
        $before = $commands;
        $expected = [
            'commands.0.entries' => ['Upsert memo commands require an Avro entries payload envelope.'],
        ];

        $this->assertSame($expected, $this->errors($commands));
        $this->assertSame($expected, $this->errors($commands));
        $this->assertSame($before, $commands);
    }

    public function testIndependentMetadataFailuresKeepTheirOriginalBatchIndexes(): void
    {
        $commands = [[
            'type' => 'upsert_memo',
            'entries' => MemoPayload::mapEnvelope(['status' => 'waiting']),
        ], [
            'type' => 'upsert_search_attributes',
            'attributes' => ['tags' => ['alpha', false]],
        ], [
            'type' => 'upsert_memo',
            'entries' => null,
        ], [
            'type' => 'upsert_search_attributes',
            'attributes' => ['attempt' => 0],
        ]];
        $before = $commands;
        $expected = [
            'commands.1.attributes' => ['Workflow v2 search attribute [tags] list values must contain only strings.'],
            'commands.2.entries' => ['Upsert memo commands require an Avro entries payload envelope.'],
        ];

        $this->assertSame($expected, $this->errors($commands));
        $this->assertSame($expected, $this->errors($commands));
        $this->assertSame($before, $commands);
    }

    public function testAcceptedMetadataPreservesFalseZeroAndDeletionValuesAcrossNormalization(): void
    {
        $memo = MemoPayload::mapEnvelope([
            'attempt' => 0,
            'enabled' => false,
            'remove' => null,
        ]);
        $commands = [[
            'type' => 'upsert_search_attributes',
            'attributes' => [
                'tags' => [' alpha ', 'beta'],
                'remove' => null,
                'enabled' => false,
                'attempt' => 0,
            ],
            'attribute_types' => [
                'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
                'enabled' => WorkflowSearchAttribute::TYPE_BOOL,
                'attempt' => WorkflowSearchAttribute::TYPE_INT,
            ],
        ], [
            'type' => 'upsert_memo',
            'entries' => $memo,
        ]];
        $before = $commands;
        $expected = [[
            'type' => 'upsert_search_attributes',
            'attributes' => [
                'attempt' => 0,
                'enabled' => false,
                'remove' => null,
                'tags' => ['alpha', 'beta'],
            ],
            'attribute_types' => [
                'attempt' => WorkflowSearchAttribute::TYPE_INT,
                'enabled' => WorkflowSearchAttribute::TYPE_BOOL,
                'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
            ],
        ], [
            'type' => 'upsert_memo',
            'entries' => $memo,
        ]];
        $normalized = WorkflowCommandNormalizer::normalize($commands);
        $this->assertSame($expected, $normalized);
        $this->assertSame($expected, WorkflowCommandNormalizer::normalize($normalized));
        $this->assertSame($before, $commands);
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

        $this->fail('Malformed metadata must be rejected before the batch can execute.');
    }
}
