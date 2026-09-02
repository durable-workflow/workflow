<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Workflow;

final class TestPortableMemoWorkflow extends Workflow
{
    public function handle(): void
    {
        Workflow::upsertMemo(self::entries(reordered: true));
    }

    /**
     * @return array<string, mixed>
     */
    public static function entries(bool $reordered = false): array
    {
        $nested = $reordered
            ? AvroMapValue::fromPairs([
                ['first', true],
                ['second', AvroMapValue::fromPairs([
                    ['left', 1],
                    ['right', AvroBinaryValue::fromBytes('nested-bytes')],
                ])],
            ])
            : AvroMapValue::fromPairs([
                ['second', AvroMapValue::fromPairs([
                    ['right', AvroBinaryValue::fromBytes('nested-bytes')],
                    ['left', 1],
                ])],
                ['first', true],
            ]);

        return [
            'adapter_map' => AvroMapValue::fromPairs([['0', 'numeric-string-key'], ['word', 'ordinary-key']]),
            'binary_text_value' => AvroBinaryValue::fromBytes('same-bytes'),
            'binary_value' => AvroBinaryValue::fromBytes("\x00\xFF"),
            'double_value' => 7.0,
            'long_value' => 7,
            'nested' => $nested,
            'text_value' => 'same-bytes',
        ];
    }
}
