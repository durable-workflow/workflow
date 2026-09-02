<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use UnderflowException;
use UnexpectedValueException;

/**
 * Enforces the fixed Value policy where Apache Avro PHP decodes permissively.
 */
final class ValueDatumDecoder extends AvroIOBinaryDecoder
{
    public function read($length): string
    {
        $bytes = parent::read($length);
        if (strlen($bytes) !== $length) {
            throw new UnderflowException('Truncated Avro Value datum.');
        }

        return $bytes;
    }

    public function readBoolean(): bool
    {
        $byte = ord($this->read(1));
        if ($byte !== 0 && $byte !== 1) {
            throw new UnexpectedValueException('Invalid Avro boolean encoding.');
        }

        return $byte === 1;
    }

    public function readLong(): int
    {
        $bytes = [];
        for ($index = 0; $index < 10; $index++) {
            $byte = ord($this->read(1));
            $bytes[] = $byte;
            if (($byte & 0x80) === 0) {
                if ($index === 9 && $byte > 1) {
                    throw new UnexpectedValueException('Avro long exceeds signed 64-bit range.');
                }

                return self::decodeLongFromArray($bytes);
            }
        }

        throw new UnexpectedValueException('Avro long exceeds ten-byte encoding limit.');
    }

    public function readDouble(): float
    {
        $value = self::longBitsToDouble($this->read(8));
        if (! is_finite($value)) {
            throw new UnexpectedValueException('Avro Value doubles must be finite.');
        }

        return $value;
    }

    public function readString(): string
    {
        $value = $this->readBytes();
        if (preg_match('//u', $value) !== 1) {
            throw new UnexpectedValueException('Avro Value strings must contain valid UTF-8.');
        }

        return $value;
    }
}
