<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Activity;

final class TestAvroAdapterCodecActivity extends Activity
{
    public function handle(
        mixed $nullable,
        bool $boolean,
        int $integer,
        float $double,
        AvroBinaryValue $bytes,
        string $text,
    ): AvroMapValue {
        if (
            $nullable !== null
            || ! $boolean
            || $integer !== 7
            || $double !== 7.0
            || $bytes->bytes !== "\x00\xFF"
            || $text !== 'text'
        ) {
            throw new LogicException('The typed Avro activity arguments did not round-trip.');
        }

        return AvroMapValue::fromPairs([['0', 'zero']]);
    }
}
