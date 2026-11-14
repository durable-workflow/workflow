<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\VersionUsageCursor;

final class VersionUsageCursorTest extends TestCase
{
    public function testCursorBindsTheQueryAndPreservesBothKeysetBoundaries(): void
    {
        $fingerprint = hash('sha256', 'namespace/type/change/minimum/state');
        $encoded = VersionUsageCursor::encode($fingerprint, 'run-9', 'run-3');
        $value = VersionUsageCursor::decode($encoded, $fingerprint);
        $this->assertSame('run-9', $value['through']);
        $this->assertSame('run-3', $value['after']);
        $this->expectException(InvalidArgumentException::class);
        VersionUsageCursor::decode($encoded, hash('sha256', 'another-namespace'));
    }

    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'base64 punctuation' => ['%%'];
        yield 'oversized' => [str_repeat('A', 2049)];
        yield 'not json' => ['YWJj'];
        yield 'scalar' => ['MQ'];
        $query = hash('sha256', 'query');
        foreach ([[
            'version' => 2,
        ], [
            'after' => [],
        ], [
            'through' => '',
        ], [
            'after' => 'run-z',
        ], [
            'extra' => true,
        ]] as $mutation) {
            yield json_encode($mutation) => [rtrim(strtr(base64_encode(json_encode(array_replace([
                'version' => 1,
                'query' => $query,
                'through' => 'run-9',
                'after' => 'run-1',
            ], $mutation), JSON_THROW_ON_ERROR)), '+/', '-_'), '=')];
        }
    }

    #[DataProvider('malformed')]
    public function testMalformedCursorsAreErrorsRatherThanFirstPages(string $cursor): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionUsageCursor::decode($cursor, hash('sha256', 'query'));
    }
}
