<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TracingQueueErrorDiagnostics;
use Throwable;

final class TracingQueueErrorDiagnosticsTest extends TestCase
{
    public function testStatementDoesNotInterpolateBindingsOrExposeExceptionText(): void
    {
        $error = new QueryException('tracing', 'insert into "jobs" ("payload") values (?)', [
            'private-binding-sentinel',
        ], new PDOException('private-exception-sentinel'));
        $result = TracingQueueErrorDiagnostics::context($error);

        self::assertSame('insert into "jobs" ("payload") values (?)', $result['statement']);
        self::assertFalse($result['statement_truncated']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-binding-sentinel', $encoded);
        self::assertStringNotContainsString('private-exception-sentinel', $encoded);
    }

    public function testStatementTruncationPreservesUtf8WithinItsByteBound(): void
    {
        $result = TracingQueueErrorDiagnostics::context(new QueryException(
            'tracing',
            'insert ' . str_repeat('雪', 600),
            [],
            new PDOException(),
        ));

        self::assertTrue($result['statement_truncated']);
        self::assertLessThanOrEqual(1024, strlen($result['statement']));
        self::assertTrue(mb_check_encoding($result['statement'], 'UTF-8'));
    }

    public function testDeepFramesExcludeArgumentsAndAbsolutePaths(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $error = self::nestedError(12, 'private-argument-sentinel');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
        self::assertArrayHasKey('args', $error->getTrace()[0]);
        $result = TracingQueueErrorDiagnostics::context($error);
        self::assertCount(8, $result['stage_frames']);
        foreach ($result['stage_frames'] as $frame) {
            self::assertSame(['file', 'line', 'function'], array_keys($frame));
            self::assertSame('TracingQueueErrorDiagnosticsTest.php', $frame['file']);
        }
        self::assertStringNotContainsString('private-argument-sentinel', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNull($result['statement']);
        self::assertFalse($result['statement_truncated']);
    }

    private static function nestedError(int $depth, string $argument): Throwable
    {
        return $depth === 0 ? new RuntimeException('private-message-sentinel') : self::nestedError(
            $depth - 1,
            $argument
        );
    }
}
