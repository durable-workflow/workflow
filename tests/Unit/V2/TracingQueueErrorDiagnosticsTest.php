<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testOnlyKnownFailedJobLoggerContentionIsClassified(): void
    {
        $error = self::failedJobLogRefusal();
        self::assertTrue(TracingQueueErrorDiagnostics::isFailedJobLogContention($error));
        $error['native_code'] = 6;
        self::assertTrue(TracingQueueErrorDiagnostics::isFailedJobLogContention($error));
    }

    #[DataProvider('unsafeRefusals')]
    public function testOtherInsertErrorsRemainRefusals(array $changes): void
    {
        self::assertFalse(TracingQueueErrorDiagnostics::isFailedJobLogContention(
            array_replace(self::failedJobLogRefusal(), $changes),
        ));
    }

    public static function unsafeRefusals(): array
    {
        return [
            'workflow write' => [[
                'statement' => 'insert into "workflow_history_events" ("payload") values (?)',
            ]],
            'uncertain statement' => [[
                'statement_truncated' => true,
            ]],
            'other database failure' => [[
                'native_code' => 19,
            ]],
            'active root' => [[
                'transaction_level' => 1,
            ]],
            'pdo still active' => [[
                'pdo_in_transaction' => true,
            ]],
            'unknown pdo' => [[
                'pdo_in_transaction' => null,
            ]],
            'unknown caller' => [[
                'stage_frames' => [],
            ]],
            'application insert' => [[
                'stage_frames' => [[
                    'file' => 'Application.php',
                    'function' => 'insert',
                ]],
            ]],
            'malformed frames' => [[
                'stage_frames' => 'not-frames',
            ]],
        ];
    }

    public function testOnlyKnownQueueDispatchContentionIsClassified(): void
    {
        $error = self::queueDispatchRefusal();
        foreach ([5, 6, 517] as $code) {
            $error['native_code'] = $code;
            self::assertTrue(TracingQueueErrorDiagnostics::isQueueDispatchContention($error));
        }
        unset($error['ack_fence']);
        self::assertFalse(TracingQueueErrorDiagnostics::isQueueDispatchContention($error));
    }

    #[DataProvider('unsafeQueueDispatchReports')]
    public function testOtherQueueDispatchErrorsRemainRefusals(array $changes): void
    {
        self::assertFalse(TracingQueueErrorDiagnostics::isQueueDispatchContention(
            array_replace(self::queueDispatchRefusal(), $changes),
        ));
    }

    public static function unsafeQueueDispatchReports(): array
    {
        return self::unsafeRefusals() + [
            'other exception' => [[
                'error_class' => RuntimeException::class,
            ]],
            'untyped sqlite code' => [[
                'native_code' => '5',
            ]],
            'invalid negative sqlite code' => [[
                'native_code' => -251,
            ]],
            'acknowledgment instead of dispatch' => [[
                'operation' => 'delete',
            ]],
            'unexpected acknowledgment fence' => [[
                'ack_fence' => [1, 'default', 10, 1],
            ]],
            'missing producer frame' => [[
                'stage_frames' => [[
                    'file' => 'DatabaseQueue.php',
                    'function' => 'insertGetId',
                ]],
            ]],
            'missing insert frame' => [[
                'stage_frames' => [[
                    'file' => 'DatabaseQueue.php',
                    'function' => 'pushToDatabase',
                ]],
            ]],
        ];
    }

    private static function queueDispatchRefusal(): array
    {
        return [
            'error_class' => QueryException::class,
            'native_code' => 5,
            'operation' => 'insert',
            'transaction_level' => 0,
            'pdo_in_transaction' => false,
            'ack_fence' => null,
            'statement_truncated' => false,
            'statement' => 'insert into "jobs" '
                . '("queue", "attempts", "reserved_at", "available_at", "created_at", "payload") values (?, ?, ?, ?, ?, ?)',
            'stage_frames' => [[
                'file' => 'DatabaseQueue.php',
                'function' => 'insertGetId',
            ], [
                'file' => 'DatabaseQueue.php',
                'function' => 'pushToDatabase',
            ]],
        ];
    }

    private static function failedJobLogRefusal(): array
    {
        return [
            'error_class' => QueryException::class,
            'native_code' => 5,
            'operation' => 'insert',
            'transaction_level' => 0,
            'pdo_in_transaction' => false,
            'statement_truncated' => false,
            'statement' => 'insert into "failed_jobs" '
                . '("uuid", "connection", "queue", "payload", "exception", "failed_at") values (?, ?, ?, ?, ?, ?)',
            'stage_frames' => [[
                'file' => 'DatabaseUuidFailedJobProvider.php',
                'function' => 'insert',
            ], [
                'file' => 'WorkCommand.php',
                'function' => 'logFailedJob',
            ]],
        ];
    }

    private static function nestedError(int $depth, string $argument): Throwable
    {
        return $depth === 0 ? new RuntimeException('private-message-sentinel') : self::nestedError(
            $depth - 1,
            $argument
        );
    }
}
