<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\UpsertMemoCall;
use Workflow\V2\Support\UpsertMemosCall;
use Workflow\V2\Support\UpsertSearchAttributesCall;

final class WorkflowMetadataCommandTest extends TestCase
{
    public function testSearchAttributeCommandNormalizesPortableValues(): void
    {
        $description = str_repeat("\u{00E9}", WorkflowSearchAttribute::MAX_STRING_LENGTH);
        $call = new UpsertSearchAttributesCall([
            'z_value' => '  ',
            'tags' => [' php ', 'rust'],
            'priority' => 3,
            'description' => $description,
        ]);

        $this->assertSame([
            'description' => $description,
            'priority' => 3,
            'tags' => ['php', 'rust'],
            'z_value' => null,
        ], $call->attributes);
    }

    /**
     * @param array<mixed> $attributes
     */
    #[DataProvider('invalidSearchAttributes')]
    public function testSearchAttributeCommandRejectsInvalidValues(array $attributes, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        new UpsertSearchAttributesCall($attributes);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidSearchAttributes(): iterable
    {
        yield 'empty update' => [[], 'requires at least one attribute'];
        yield 'numeric key' => [[
            0 => 'value',
        ], 'keys must be 1-64 URL-safe characters'];
        yield 'invalid key' => [[
            'not a key' => 'value',
        ], 'keys must be 1-64 URL-safe characters'];
        yield 'object value' => [[
            'owner' => new \stdClass(),
        ], 'must be a scalar value, string list, or null'];
        yield 'associative list' => [[
            'owners' => [
                'primary' => 'Taylor',
            ],
        ], 'list value must be a JSON array'];
        yield 'non-string list entry' => [[
            'owners' => ['Taylor', 42],
        ], 'list values must contain only strings'];
        yield 'overlong keyword-list entry' => [[
            'owners' => [str_repeat("\u{00E9}", WorkflowSearchAttribute::MAX_KEYWORD_LENGTH + 1)],
        ], 'list values must be up to 255 characters'];
        yield 'overlong scalar string' => [[
            'description' => str_repeat('a', WorkflowSearchAttribute::MAX_STRING_LENGTH + 1),
        ], 'must be up to 2048 characters'];
    }

    public function testMemoCommandsNormalizePortableEntries(): void
    {
        $value = AvroBinaryValue::fromBytes("\x00\xFF");

        $this->assertSame([
            'delete' => null,
            'payload' => $value,
        ], (new UpsertMemoCall([
            'payload' => $value,
            'delete' => null,
        ]))->entries);

        $this->assertSame([
            'delete' => null,
            'payload' => $value,
        ], (new UpsertMemosCall([
            'payload' => $value,
            'delete' => null,
        ]))->memos);
    }

    /**
     * @param class-string<UpsertMemoCall|UpsertMemosCall> $command
     */
    #[DataProvider('memoCommands')]
    public function testMemoCommandsRejectEmptyAndInvalidEntries(string $command): void
    {
        try {
            new $command([]);
            $this->fail('Empty memo updates must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('requires at least one', $exception->getMessage());
        }

        try {
            new $command([
                '123' => 'value',
            ]);
            $this->fail('Non-portable memo keys must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('memo keys must match', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be a portable Avro value');

        new $command([
            'payload' => new \stdClass(),
        ]);
    }

    /**
     * @return iterable<string, array{class-string<UpsertMemoCall|UpsertMemosCall>}>
     */
    public static function memoCommands(): iterable
    {
        yield 'singular command' => [UpsertMemoCall::class];
        yield 'legacy plural command' => [UpsertMemosCall::class];
    }
}
