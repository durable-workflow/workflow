<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use InvalidArgumentException;

/** @internal Bounds for the canonical six-digit UTC timestamp columns. */
final class PreparedLocalRetryTimestamp
{
    public static function addForConnection(
        DateTimeInterface $sample,
        int $seconds,
        Connection $connection
    ): DateTimeImmutable {
        return self::add(
            $sample,
            $seconds,
            self::latestForConnection($connection),
            self::earliestForDriver($connection->getDriverName())
        );
    }

    public static function earliestForDriver(string $driver): DateTimeImmutable
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return new DateTimeImmutable('1970-01-01T00:00:01.000000Z');
        }
        if (! in_array($driver, ['sqlite', 'pgsql', 'sqlsrv'], true)) {
            throw new InvalidArgumentException('Unsupported prepared retry timestamp backend.');
        }

        return new DateTimeImmutable('0001-01-01T00:00:00.000000Z');
    }

    public static function latestForConnection(Connection $connection): DateTimeImmutable
    {
        $driver = $connection->getDriverName();
        if ($connection instanceof MySqlConnection && $connection->isMaria()) {
            $driver = 'mariadb';
        }

        return self::latestForDriver($driver, $driver === 'mariadb' ? $connection->getServerVersion() : null);
    }

    public static function latestForDriver(string $driver, ?string $serverVersion = null): DateTimeImmutable
    {
        // MySQL TIMESTAMP(6), rather than DATETIME's broader year range:
        // https://dev.mysql.com/doc/refman/8.4/en/datetime.html
        if ($driver === 'mysql') {
            return new DateTimeImmutable('2038-01-19T03:14:07.499999Z');
        }
        if ($driver === 'mariadb') {
            if (! is_string($serverVersion)
                || preg_match('/^(?:5\.5\.5-)?([0-9]+\.[0-9]+(?:\.[0-9]+)?)/D', $serverVersion, $match) !== 1) {
                throw new InvalidArgumentException(
                    'The MariaDB timestamp range requires an identified server version.'
                );
            }
            // The unsigned seconds range starts at MariaDB 11.5.
            // https://mariadb.com/docs/server/reference/data-types/date-and-time-data-types/timestamp
            return new DateTimeImmutable(version_compare($match[1], '11.5', '>=')
                ? '2106-02-07T06:28:15.999999Z' : '2038-01-19T03:14:07.999999Z');
        }
        if (! in_array($driver, ['sqlite', 'pgsql', 'sqlsrv'], true)) {
            throw new InvalidArgumentException('Unsupported prepared retry timestamp backend.');
        }

        // These stores admit the four-digit UTC domain used by this contract.
        // Laravel's timestamp(..., 6) maps to datetime2(6) on SQL Server.
        return new DateTimeImmutable('9999-12-31T23:59:59.999999Z');
    }

    public static function add(
        DateTimeInterface $sample,
        int $seconds,
        DateTimeInterface $latest,
        ?DateTimeInterface $earliest = null,
    ): DateTimeImmutable {
        $utc = DateTimeImmutable::createFromInterface($sample)->setTimezone(new DateTimeZone('UTC'));
        $latest = DateTimeImmutable::createFromInterface($latest)->setTimezone(new DateTimeZone('UTC'));
        $earliest ??= new DateTimeImmutable('0001-01-01T00:00:00.000000Z');
        if ($seconds < 0 || $seconds > PreparedLocalRetryPolicy::MAX_DELAY_SECONDS
            || strlen($utc->format('Y')) !== 4 || (int) $utc->format('Y') < 1
            || strlen($latest->format('Y')) !== 4 || (int) $latest->format('Y') < 1
            || $utc < $earliest || $utc > $latest) {
            throw new InvalidArgumentException('Invalid prepared retry timestamp input.');
        }
        $remaining = ($latest->getTimestamp() - $utc->getTimestamp()) * 1000000
            + (int) $latest->format('u') - (int) $utc->format('u');
        if ($seconds > intdiv($remaining, 1000000)) {
            throw new InvalidArgumentException('Prepared retry timestamp exceeds the backend range.');
        }

        return $utc->add(new DateInterval('PT' . $seconds . 'S'));
    }
}
