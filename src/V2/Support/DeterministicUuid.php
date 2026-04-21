<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use RuntimeException;

final class DeterministicUuid
{
    private static int $lastUuid7Milliseconds = -1;

    private static int $uuid7Sequence = 0;

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    public static function uuid7(CarbonInterface $time): string
    {
        $milliseconds = ((int) $time->format('U')) * 1000 + (int) floor(((int) $time->format('u')) / 1000);

        if ($milliseconds === self::$lastUuid7Milliseconds) {
            self::$uuid7Sequence = (self::$uuid7Sequence + 1) & 0x0fff;
        } else {
            self::$lastUuid7Milliseconds = $milliseconds;
            self::$uuid7Sequence = 0;
        }

        $random = random_bytes(8);
        $randomA = self::$uuid7Sequence;

        $bytes = '';
        for ($shift = 40; $shift >= 0; $shift -= 8) {
            $bytes .= chr(($milliseconds >> $shift) & 0xff);
        }

        $bytes .= chr(0x70 | (($randomA >> 8) & 0x0f));
        $bytes .= chr($randomA & 0xff);
        $bytes .= chr((ord($random[0]) & 0x3f) | 0x80);
        $bytes .= substr($random, 1, 7);

        return self::format($bytes);
    }

    private static function format(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new RuntimeException('UUID formatting requires exactly 16 bytes.');
        }

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
