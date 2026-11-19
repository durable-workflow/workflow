<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\NonDatabaseTestCase;
use UnexpectedValueException;
use Workflow\Support\WorkflowMigration;

final class TimerDeadlineMetadataMigrationTest extends NonDatabaseTestCase
{
    private DatabaseManager $databaseManager;

    protected function setUp(): void
    {
        parent::setUp();
        $manager = DB::getFacadeRoot();
        $this->assertInstanceOf(DatabaseManager::class, $manager);
        $this->databaseManager = $manager;
        config([
            'workflows.storage.connection' => 'timer_metadata',
        ]);
    }

    protected function tearDown(): void
    {
        DB::swap($this->databaseManager);
        parent::tearDown();
    }

    public function testOtherDriversAndMysqlDoNotInspectOrChangeTheSchema(): void
    {
        foreach ([$this->createMock(Connection::class), $this->createMock(MySqlConnection::class)] as $connection) {
            if ($connection instanceof MySqlConnection) {
                $connection->method('isMaria')
                    ->willReturn(false);
            }
            $connection->expects($this->never())
                ->method('selectOne');
            $connection->expects($this->never())
                ->method('statement');
            DB::shouldReceive('connection')->with('timer_metadata')->twice()->andReturn($connection);
            $this->migration()
                ->up();
            $this->migration(true)
                ->up();
        }
    }

    #[DataProvider('alreadySafeMetadata')]
    public function testMissingOrNonautomaticColumnsRemainUntouched(?object $column): void
    {
        $connection = $this->mariaConnection();
        $connection->expects($this->exactly(2))
            ->method('selectOne')
            ->willReturn($column);
        $connection->expects($this->never())
            ->method('statement');
        $this->migration()
            ->up();
        $this->migration()
            ->down();
        $this->migration(true)
            ->up();
        $this->migration(true)
            ->down();
    }

    public static function alreadySafeMetadata(): iterable
    {
        yield 'missing column' => [null];
        yield 'corrected timestamp' => [
            (object) [
                'extra' => '',
            ]];
        yield 'unrelated metadata' => [
            (object) [
                'extra' => 'DEFAULT_GENERATED',
            ]];
    }

    #[DataProvider('unsupportedMetadata')]
    /**
     * @param array<string, int|string> $change
     */
    public function testUnsupportedAutomaticMetadataCannotChangeRowsSchemaOrSession(
        array $change,
        bool $serviceDispatch
    ): void {
        $connection = $this->mariaConnection();
        $connection->expects($this->once())
            ->method('selectOne')
            ->willReturn((object) array_replace(self::legacyMetadata(), $change));
        $connection->expects($this->never())
            ->method('statement');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($serviceDispatch
            ? 'workflow_service_dispatch_available_at_legacy_shape_unavailable'
            : 'workflow_timer_fire_at_legacy_shape_unavailable');
        $this->migration($serviceDispatch)
            ->up();
    }

    public static function unsupportedMetadata(): iterable
    {
        $changes = [
            'type' => [
                'column_type' => 'datetime(6)',
            ],
            'nullable' => [
                'is_nullable' => 'YES',
            ],
            'custom default' => [
                'default_value' => '2026-11-08 04:00:20.234567',
            ],
            'precision' => [
                'datetime_precision' => 3,
            ],
            'additional automatic behavior' => [
                'extra' => 'on update current_timestamp(6) DEFAULT_GENERATED',
            ],
        ];
        foreach ($changes as $name => $change) {
            yield 'timer ' . $name => [$change, false];
            yield 'service dispatch ' . $name => [$change, true];
        }
    }

    #[DataProvider('schemaOutcomes')]
    public function testKnownShapeUsesConfiguredPrefixAndRestoresTheLockBudget(
        bool $ddlFails,
        bool $serviceDispatch
    ): void {
        $table = $serviceDispatch ? 'workflow_service_call_dispatches' : 'workflow_run_timers';
        $column = $serviceDispatch ? 'available_at' : 'fire_at';
        $connection = $this->mariaConnection();
        $connection->expects($this->exactly(2))
            ->method('selectOne')
            ->willReturnCallback(function (string $sql, array $bindings = []) use ($table, $column): object {
                if (str_contains($sql, 'information_schema.columns')) {
                    $this->assertSame(['tenant_' . $table, $column], $bindings);

                    return (object) self::legacyMetadata();
                }
                $this->assertSame('SELECT @@SESSION.lock_wait_timeout AS lock_wait_seconds', $sql);

                return (object) [
                    'lock_wait_seconds' => 37,
                ];
            });
        $statements = [];
        $connection->expects($this->exactly(3))
            ->method('statement')
            ->willReturnCallback(static function (string $sql) use (&$statements, $ddlFails): bool {
                $statements[] = $sql;
                if ($ddlFails && str_starts_with($sql, 'ALTER TABLE')) {
                    throw new PDOException('schema lock refused');
                }

                return true;
            });
        try {
            $this->migration($serviceDispatch)
                ->up();
            $this->assertFalse($ddlFails);
        } catch (PDOException $exception) {
            $this->assertTrue($ddlFails);
            $this->assertSame('schema lock refused', $exception->getMessage());
        }
        $this->assertSame([
            'SET SESSION lock_wait_timeout = 1',
            "ALTER TABLE `tenant_{$table}` MODIFY `{$column}` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ALGORITHM=INPLACE, LOCK=NONE",
            'SET SESSION lock_wait_timeout = 37',
        ], $statements);
        $this->migration($serviceDispatch)
            ->down();
    }

    public static function schemaOutcomes(): iterable
    {
        yield 'timer corrected' => [false, false];
        yield 'timer schema lock refused' => [true, false];
        yield 'service dispatch corrected' => [false, true];
        yield 'service dispatch schema lock refused' => [true, true];
    }

    private function mariaConnection(): MySqlConnection&MockObject
    {
        $connection = $this->createMock(MySqlConnection::class);
        $connection->method('isMaria')
            ->willReturn(true);
        $connection->method('getDriverName')
            ->willReturn('mysql');
        $connection->method('getTablePrefix')
            ->willReturn('tenant_');
        $connection->method('getQueryGrammar')
            ->willReturn(new MySqlGrammar($connection));
        $connection->expects($this->never())
            ->method('getPdo');
        DB::shouldReceive('connection')->with('timer_metadata')->andReturn($connection);

        return $connection;
    }

    /**
     * @return array{column_type: string, is_nullable: string, default_value: string, datetime_precision: int, extra: string}
     */
    private static function legacyMetadata(): array
    {
        return [
            'column_type' => 'timestamp(6)',
            'is_nullable' => 'NO',
            'default_value' => 'current_timestamp(6)',
            'datetime_precision' => 6,
            'extra' => 'on update current_timestamp(6)',
        ];
    }

    private function migration(bool $serviceDispatch = false): WorkflowMigration
    {
        $file = $serviceDispatch
            ? '2026_11_14_000100_preserve_service_dispatch_available_at_on_mariadb.php'
            : '2026_11_13_000200_preserve_workflow_timer_fire_at_on_mariadb.php';

        return require dirname(__DIR__, 3) . '/src/migrations/' . $file;
    }
}
