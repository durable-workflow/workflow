<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Support\RuntimeProfileSqlDefinition;

final class RuntimeProfileSqlDefinitionTest extends TestCase
{
    #[DataProvider('quotedDefinitions')]
    public function testOnlyWhitespaceOutsideQuotedTokensIsNormalized(string $token): void
    {
        $expected = 'SELECT ' . $token . ' FROM source';
        $this->assertSame(
            $expected,
            RuntimeProfileSqlDefinition::normalize(" \nSELECT\t" . $token . "\r\n FROM  source\t")
        );
        $this->assertNotSame(
            RuntimeProfileSqlDefinition::normalize(str_replace('  ', ' ', $expected)),
            RuntimeProfileSqlDefinition::normalize($expected),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotedDefinitions(): iterable
    {
        yield 'SQL literal' => ["'a  b'"];
        yield 'escaped SQL literal' => ["'a''  b'"];
        yield 'ANSI identifier' => ['"a  b"'];
        yield 'escaped ANSI identifier' => ['"a""  b"'];
        yield 'MySQL identifier' => ['`a``  b`'];
        yield 'SQL Server identifier' => ['[a]]  b]'];
        yield 'literal backslash' => ['"a\\  b"'];
    }

    public function testUnterminatedQuotedMetadataCannotBeAdopted(): void
    {
        $this->expectException(RuntimeProfileRefusal::class);
        RuntimeProfileSqlDefinition::normalize('SELECT "unclosed  identifier');
    }
}
