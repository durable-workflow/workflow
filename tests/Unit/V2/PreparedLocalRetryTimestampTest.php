<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PreparedLocalRetryTimestamp;

final class PreparedLocalRetryTimestampTest extends TestCase
{
    public function testDocumentedBackendAndContractBoundsAreSelectedWithoutOpeningAConnection(): void
    {
        foreach (['sqlite', 'pgsql', 'sqlsrv'] as $driver) {
            self::assertSame(
                '0001-01-01T00:00:00.000000Z',
                PreparedLocalRetryTimestamp::earliestForDriver($driver)->format('Y-m-d\TH:i:s.u\Z')
            );
            self::assertSame(
                '9999-12-31T23:59:59.999999Z',
                PreparedLocalRetryTimestamp::latestForDriver($driver)->format('Y-m-d\TH:i:s.u\Z')
            );
        }
        self::assertSame(
            '2038-01-19T03:14:07.499999Z',
            PreparedLocalRetryTimestamp::latestForDriver('mysql')->format('Y-m-d\TH:i:s.u\Z')
        );
        foreach (['10.11.14', '11.4.0-MariaDB', '5.5.5-11.4.8-MariaDB'] as $version) {
            self::assertSame(
                '2038-01-19T03:14:07.999999Z',
                PreparedLocalRetryTimestamp::latestForDriver('mariadb', $version)->format('Y-m-d\TH:i:s.u\Z')
            );
        }
        foreach (['11.5.1', '11.8.2-MariaDB', '12.0.0'] as $version) {
            self::assertSame(
                '2106-02-07T06:28:15.999999Z',
                PreparedLocalRetryTimestamp::latestForDriver('mariadb', $version)->format('Y-m-d\TH:i:s.u\Z')
            );
        }
    }

    public function testMariaDbThroughTheMysqlConnectionUsesItsActualVendorVersion(): void
    {
        $connection = $this->createMock(MySqlConnection::class);
        $connection->method('getDriverName')
            ->willReturn('mysql');
        $connection->method('isMaria')
            ->willReturn(true);
        $connection->method('getServerVersion')
            ->willReturn('11.8.2');
        $connection->expects(self::never())->method('getPdo');
        self::assertSame('2106', PreparedLocalRetryTimestamp::latestForConnection($connection)->format('Y'));

        $mysql = $this->createMock(MySqlConnection::class);
        $mysql->method('getDriverName')
            ->willReturn('mysql');
        $mysql->method('isMaria')
            ->willReturn(false);
        $mysql->expects(self::never())->method('getServerVersion');
        self::assertSame('2038', PreparedLocalRetryTimestamp::latestForConnection($mysql)->format('Y'));
    }

    public function testAdditionPreservesTheSampleAndChecksBeforeAnyLargeIntervalConstruction(): void
    {
        $sample = new DateTimeImmutable('2026-11-05T01:00:00.123456+01:00');
        $latest = new DateTimeImmutable('9999-12-31T23:59:59.999999Z');
        self::assertSame(
            '2026-11-05T00:00:02.123456Z',
            PreparedLocalRetryTimestamp::add($sample, 2, $latest)->format('Y-m-d\TH:i:s.u\Z')
        );
        self::assertSame('2026-11-05T01:00:00.123456+01:00', $sample->format('Y-m-d\TH:i:s.uP'));
        $this->expectException(InvalidArgumentException::class);
        PreparedLocalRetryTimestamp::add($sample, 9007199254740991, $latest);
    }

    public function testUpperBoundaryRetainsMicrosecondsAndRefusesTheOverflowingSuccessor(): void
    {
        $latest = new DateTimeImmutable('2038-01-19T03:14:07.499999Z');
        self::assertEquals(
            $latest,
            PreparedLocalRetryTimestamp::add(new DateTimeImmutable('2038-01-19T03:14:06.499999Z'), 1, $latest)
        );
        self::assertEquals($latest, PreparedLocalRetryTimestamp::add($latest, 0, $latest));
        $this->expectException(InvalidArgumentException::class);
        PreparedLocalRetryTimestamp::add(new DateTimeImmutable('2038-01-19T03:14:06.500000Z'), 1, $latest);
    }

    public function testConnectionWrapperAlsoEnforcesTheTimestampLowerBound(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')
            ->willReturn('mysql');
        $connection->expects(self::never())->method('getPdo');
        $earliest = new DateTimeImmutable('1970-01-01T00:00:01.000000Z');
        self::assertEquals($earliest, PreparedLocalRetryTimestamp::addForConnection($earliest, 0, $connection));
        $this->expectException(InvalidArgumentException::class);
        PreparedLocalRetryTimestamp::addForConnection(
            new DateTimeImmutable('1970-01-01T00:00:00.999999Z'),
            1,
            $connection
        );
    }

    public function testUnknownBackendAndUnidentifiedMariaDbVersionAreRefused(): void
    {
        foreach ([['unknown', null],
            ['mariadb', null],
            ['mariadb', ''],
            ['mariadb', 'unidentified'],
        ] as [$driver, $version]) {
            try {
                PreparedLocalRetryTimestamp::latestForDriver($driver, $version);
                self::fail('Invented a timestamp bound for an unidentified backend.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        PreparedLocalRetryTimestamp::earliestForDriver('unknown');
    }

    public function testInvalidDurationAndOutOfDomainInstantsAreRefused(): void
    {
        foreach ([
            ['2026-11-05T00:00:00Z', -1, '9999-12-31T23:59:59.999999Z'],
            ['2026-11-05T00:00:00Z', 9007199254740992, '9999-12-31T23:59:59.999999Z'],
            ['2026-11-06T00:00:00Z', 0, '2026-11-05T00:00:00Z'],
            ['0000-01-01T00:00:00Z', 0, '9999-12-31T23:59:59.999999Z'],
            ['2026-11-05T00:00:00Z', 0, '0000-01-01T00:00:00Z'],
        ] as [$sample, $seconds, $latest]) {
            try {
                PreparedLocalRetryTimestamp::add(
                    new DateTimeImmutable($sample),
                    $seconds,
                    new DateTimeImmutable($latest)
                );
                self::fail('Invented a representable timestamp.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
