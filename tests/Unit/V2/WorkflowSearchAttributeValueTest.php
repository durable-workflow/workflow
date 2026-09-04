<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\SearchAttributeUpsertService;
use Workflow\V2\Support\UpsertSearchAttributesCall;

final class WorkflowSearchAttributeValueTest extends TestCase
{
    #[DataProvider('inferredTypes')]
    public function testItInfersThePortableStorageType(mixed $value, string $expected): void
    {
        $this->assertSame($expected, WorkflowSearchAttribute::inferType($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function inferredTypes(): iterable
    {
        yield 'boolean' => [true, WorkflowSearchAttribute::TYPE_BOOL];
        yield 'integer' => [42, WorkflowSearchAttribute::TYPE_INT];
        yield 'float' => [4.2, WorkflowSearchAttribute::TYPE_FLOAT];
        yield 'carbon datetime' => [Carbon::parse('2026-09-04T12:00:00Z'), WorkflowSearchAttribute::TYPE_DATETIME];
        yield 'native datetime' => [
            new DateTimeImmutable('2026-09-04T12:00:00Z'),
            WorkflowSearchAttribute::TYPE_DATETIME,
        ];
        yield 'short identifier' => ['pending', WorkflowSearchAttribute::TYPE_KEYWORD];
        yield 'prose' => ['waiting for review', WorkflowSearchAttribute::TYPE_STRING];
        yield 'long text' => [
            str_repeat('x', WorkflowSearchAttribute::MAX_KEYWORD_LENGTH + 1),
            WorkflowSearchAttribute::TYPE_STRING,
        ];
        yield 'keyword list' => [['php', 'rust'], WorkflowSearchAttribute::TYPE_KEYWORD_LIST];
        yield 'null deletion marker' => [null, WorkflowSearchAttribute::TYPE_KEYWORD];
    }

    public function testItRejectsValuesWithoutAPortableStorageType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot infer search attribute type from value: object');

        WorkflowSearchAttribute::inferType(new \stdClass());
    }

    #[DataProvider('coercibleValues')]
    public function testItCoercesDeclaredScalarValues(string $type, mixed $value, mixed $expected): void
    {
        $attribute = new WorkflowSearchAttribute();
        $attribute->setTypedValue($value, $type);

        $this->assertSame($expected, $attribute->getValue());
    }

    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function coercibleValues(): iterable
    {
        yield 'null to text' => [WorkflowSearchAttribute::TYPE_STRING, null, ''];
        yield 'integer to text' => [WorkflowSearchAttribute::TYPE_STRING, 42, '42'];
        yield 'stringable to keyword' => [
            WorkflowSearchAttribute::TYPE_KEYWORD,
            new SearchAttributeStringableValue('customer-42'),
            'customer-42',
        ];
        yield 'numeric string to integer' => [WorkflowSearchAttribute::TYPE_INT, '42', 42];
        yield 'numeric string to float' => [WorkflowSearchAttribute::TYPE_FLOAT, '4.25', 4.25];
    }

    #[DataProvider('booleanValues')]
    public function testItCoercesDocumentedBooleanRepresentations(mixed $value, bool $expected): void
    {
        $attribute = new WorkflowSearchAttribute();
        $attribute->setTypedValue($value, WorkflowSearchAttribute::TYPE_BOOL);

        $this->assertSame($expected, $attribute->getValue());
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function booleanValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'boolean false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer non-zero' => [-1, true];
        yield 'integer zero' => [0, false];
        yield 'string true' => ['true', true];
        yield 'string one' => ['1', true];
        yield 'string yes' => ['yes', true];
        yield 'string on' => ['on', true];
        yield 'string false' => ['false', false];
        yield 'string zero' => ['0', false];
        yield 'string no' => ['no', false];
        yield 'string off' => ['off', false];
        yield 'empty string' => ['', false];
    }

    public function testItCoercesCarbonAndNativeDatetimes(): void
    {
        $carbon = Carbon::parse('2026-09-04T12:00:00.123456Z');
        $carbonAttribute = new WorkflowSearchAttribute();
        $carbonAttribute->setTypedValue($carbon, WorkflowSearchAttribute::TYPE_DATETIME);

        $nativeAttribute = new WorkflowSearchAttribute();
        $nativeAttribute->setTypedValue(
            new DateTimeImmutable('2026-09-04T13:00:00.654321Z'),
            WorkflowSearchAttribute::TYPE_DATETIME,
        );

        $this->assertSame('2026-09-04 12:00:00.123456', $carbonAttribute->getValue()?->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-09-04 13:00:00.654321', $nativeAttribute->getValue()?->format('Y-m-d H:i:s.u'));
    }

    public function testItRejectsAnInvalidDeclaredType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid search attribute type: uuid');

        (new WorkflowSearchAttribute())->setTypedValue('customer-42', 'uuid');
    }

    #[DataProvider('unsupportedDeclaredTypes')]
    public function testItRejectsUnsupportedDeclaredStorageTypes(mixed $type, string $description): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Search attribute [customer] declares unsupported type [{$description}]");

        SearchAttributeUpsertService::assertDeclaredTypesCompatible(
            new UpsertSearchAttributesCall([
                'customer' => 'customer-42',
            ]),
            [
                'customer' => $type,
            ],
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unsupportedDeclaredTypes(): iterable
    {
        yield 'unknown scalar type' => ['uuid', 'uuid'];
        yield 'non-scalar type' => [['keyword'], 'array'];
    }

    #[DataProvider('invalidTypedValues')]
    public function testItRejectsInvalidTypedValuesWithoutPhpConversionWarnings(
        string $type,
        mixed $value,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new WorkflowSearchAttribute())->setTypedValue($value, $type);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidTypedValues(): iterable
    {
        yield 'keyword list member' => [
            WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
            ['php', 42],
            'keyword_list entries must be strings',
        ];
        yield 'keyword list member length' => [
            WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
            [str_repeat('x', WorkflowSearchAttribute::MAX_KEYWORD_LENGTH + 1)],
            'keyword_list entry exceeds maximum length',
        ];
        yield 'integer array' => [WorkflowSearchAttribute::TYPE_INT, [], 'Cannot coerce value to int: array'];
        yield 'float array' => [WorkflowSearchAttribute::TYPE_FLOAT, [], 'Cannot coerce value to float: array'];
        yield 'boolean array' => [WorkflowSearchAttribute::TYPE_BOOL, [], 'Cannot coerce value to bool: array'];
        yield 'invalid datetime text' => [
            WorkflowSearchAttribute::TYPE_DATETIME,
            'not-a-datetime',
            'Cannot parse datetime value: not-a-datetime',
        ];
        yield 'datetime array' => [
            WorkflowSearchAttribute::TYPE_DATETIME,
            [],
            'Cannot coerce value to datetime: array',
        ];
        yield 'string array' => [WorkflowSearchAttribute::TYPE_STRING, [], 'Cannot coerce value to string: array'];
    }
}

final readonly class SearchAttributeStringableValue implements Stringable
{
    public function __construct(
        private string $value
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
