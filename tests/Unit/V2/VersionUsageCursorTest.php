<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\VersionUsageCursor;

final class VersionUsageCursorTest extends TestCase
{
    private const KEY = 'version-usage-test-signing-key';

    public function testCursorBindsTheQueryAndPreservesBothKeysetBoundaries(): void
    {
        $fingerprint = hash('sha256', 'namespace/type/change/minimum/state');
        $encoded = VersionUsageCursor::encode($fingerprint, 'run-9', 'run-3', self::KEY);
        $value = VersionUsageCursor::decode($encoded, $fingerprint, self::KEY);
        $this->assertSame('run-9', $value['through']);
        $this->assertSame('run-3', $value['after']);
        $this->expectException(InvalidArgumentException::class);
        VersionUsageCursor::decode($encoded, hash('sha256', 'another-namespace'), self::KEY);
    }

    public function testTamperedBoundariesQueryAndTagCannotAuthenticate(): void
    {
        $query = hash('sha256', 'query');
        $cursor = VersionUsageCursor::encode($query, 'run-9', 'run-3', self::KEY);
        [$body, $tag] = explode('.', $cursor);
        $value = json_decode(base64_decode(strtr($body, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            'after' => 'run-4',
            'through' => 'run-8',
            'query' => hash('sha256', 'other'),
        ] as $field => $replacement) {
            $mutated = $value;
            $mutated[$field] = $replacement;
            $transport = rtrim(strtr(base64_encode(json_encode($mutated, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            try {
                VersionUsageCursor::decode($transport . '.' . $tag, $query, self::KEY);
                $this->fail('Modified cursor body was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid version-usage cursor signature.', $exception->getMessage());
            }
        }
        foreach ([$body, $body . '.' . str_repeat('0', 64), 'bm90LWpzb24.' . $tag] as $mutated) {
            try {
                VersionUsageCursor::decode($mutated, $query, self::KEY);
                $this->fail('Unsigned or modified cursor was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRotatedKeyRefusesAnOldCursor(): void
    {
        $query = hash('sha256', 'query');
        $cursor = VersionUsageCursor::encode($query, 'run-9', 'run-3', self::KEY);
        $this->expectException(InvalidArgumentException::class);
        VersionUsageCursor::decode($cursor, $query, 'another-application-key');
    }

    public function testAnEmptySigningKeyIsRefused(): void
    {
        $this->expectException(LogicException::class);
        VersionUsageCursor::encode(hash('sha256', 'query'), 'run-9', 'run-3', '');
    }

    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'base64 punctuation' => ['%%'];
        yield 'oversized' => [str_repeat('A', 2049)];
        yield 'not json' => [self::signed('abc')];
        yield 'scalar' => [self::signed('1')];
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
            yield json_encode($mutation) => [self::signed(json_encode(array_replace([
                'version' => 1,
                'query' => $query,
                'through' => 'run-9',
                'after' => 'run-1',
            ], $mutation), JSON_THROW_ON_ERROR))];
        }
    }

    #[DataProvider('malformed')]
    public function testMalformedCursorsAreErrorsRatherThanFirstPages(string $cursor): void
    {
        $this->expectException(InvalidArgumentException::class);
        VersionUsageCursor::decode($cursor, hash('sha256', 'query'), self::KEY);
    }

    private static function signed(string $json): string
    {
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', "durable-workflow.version-usage-cursor.v1\0" . $body, self::KEY);
    }
}
