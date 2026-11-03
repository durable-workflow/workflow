<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Casts\Json;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use WeakReference;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowRunWait;

final class RunWaitPayloadEquivalenceTest extends TestCase
{
    public static function payloads(): iterable
    {
        yield 'nested object order' => ['{"a":1,"b":{"x":2,"y":3}}', '{"b":{"y":3,"x":2},"a":1}', true];
        yield 'list order' => ['{"a":[1,2]}', '{"a":[2,1]}', false];
        yield 'scalar type' => ['{"a":1}', '{"a":"1"}', false];
        yield 'float type' => ['{"a":1}', '{"a":1.0}', false];
        yield 'state change' => ['{"state":"open","a":1}', '{"a":1,"state":"cancelled"}', false];
        yield 'empty array cast' => ['{}', '[]', true];
        yield 'stored null' => [null, 'null', true];
        yield 'current null' => ['null', null, true];
        yield 'invalid JSON casts' => ['{', '}', true];
    }

    #[DataProvider('payloads')]
    public function testPayloadComparisonPreservesLaravelAndCanonicalSemantics(
        ?string $original,
        ?string $current,
        bool $expected,
    ): void {
        $row = $this->row($original, $current);
        $this->assertSame($expected, $row->originalIsEquivalent('payload'));
        $this->assertSame($expected, $row->originalIsEquivalent('payload'));
        $this->assertSame($original, $row->getRawOriginal('payload'));
        $this->assertSame($current, $row->getAttributes()['payload']);
    }

    public function testComparisonRechecksBothRawValuesAndSyncedOriginals(): void
    {
        $row = $this->row('{"a":1,"b":2}', '{"b":2,"a":1}');
        $this->assertTrue($row->originalIsEquivalent('payload'));
        $row->setRawAttributes([
            'payload' => '{"b":3,"a":1}',
        ]);
        $this->assertFalse($row->originalIsEquivalent('payload'));
        $row->syncOriginal();
        $this->assertTrue($row->originalIsEquivalent('payload'));
        $row->setRawAttributes([
            'payload' => '{"a":1,"b":4}',
        ], true);
        $row->setRawAttributes([
            'payload' => '{"b":3,"a":1}',
        ]);
        $this->assertFalse($row->originalIsEquivalent('payload'));
    }

    public function testConfiguredDecoderKeepsItsReadsAfterAnEquivalentDefaultComparison(): void
    {
        if (! class_exists(Json::class)) {
            $this->markTestSkipped('This Laravel version does not expose a JSON decoder callback.');
        }
        $row = $this->row('{"a":1,"b":2}', '{"b":2,"a":1}');
        $this->assertTrue($row->originalIsEquivalent('payload'));
        $reads = 0;
        Json::decodeUsing(static function ($value, $associative = true) use (&$reads): array {
            return [
                'read' => ++$reads,
            ];
        });
        try {
            $this->assertFalse($row->originalIsEquivalent('payload'));
            $this->assertSame(4, $reads);
        } finally {
            Json::decodeUsing(null);
        }
        $this->assertTrue($row->originalIsEquivalent('payload'));
    }

    public function testConfiguredDecoderFailureIsNotHiddenByAnEarlierComparison(): void
    {
        if (! class_exists(Json::class)) {
            $this->markTestSkipped('This Laravel version does not expose a JSON decoder callback.');
        }
        $row = $this->row('{"a":1,"b":2}', '{"b":2,"a":1}');
        $this->assertTrue($row->originalIsEquivalent('payload'));
        Json::decodeUsing(static function (): never {
            throw new RuntimeException('decoder refused');
        });
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('decoder refused');
            $row->originalIsEquivalent('payload');
        } finally {
            Json::decodeUsing(null);
        }
    }

    public function testConfiguredModelKeepsItsOriginalAccessor(): void
    {
        $row = new class() extends WorkflowRunWait {
            public int $originalReads = 0;

            public function getOriginal($key = null, $default = null)
            {
                if ($key === 'payload') {
                    ++$this->originalReads;
                    return [
                        'override' => true,
                    ];
                }
                return parent::getOriginal($key, $default);
            }
        };
        $row->setRawAttributes([
            'payload' => '{"a":1,"b":2}',
        ], true);
        $row->setRawAttributes([
            'payload' => '{"b":2,"a":1}',
        ]);
        $this->assertFalse($row->originalIsEquivalent('payload'));
        $this->assertSame(1, $row->originalReads);
    }

    public function testChangedCastAndLargePayloadsDoNotReuseAnEarlierResult(): void
    {
        $row = $this->row('{"a":1,"b":2}', '{"b":2,"a":1}');
        $this->assertTrue($row->originalIsEquivalent('payload'));
        $row->mergeCasts([
            'payload' => 'json',
        ]);
        $this->assertFalse($row->originalIsEquivalent('payload'));
        $row->mergeCasts([
            'payload' => 'array',
        ]);
        $this->assertTrue($row->originalIsEquivalent('payload'));
        $row->payload = [
            'large' => str_repeat('x', 8192),
        ];
        $this->assertFalse($row->originalIsEquivalent('payload'));
        $row->syncOriginal();
        $this->assertTrue($row->originalIsEquivalent('payload'));
    }

    public function testComparisonDoesNotKeepItsModelAlive(): void
    {
        $row = $this->row('{"a":1,"b":2}', '{"b":2,"a":1}');
        $this->assertTrue($row->originalIsEquivalent('payload'));
        $reference = WeakReference::create($row);
        unset($row);
        gc_collect_cycles();
        $this->assertNull($reference->get());
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    private function row(?string $original, ?string $current): WorkflowRunWait
    {
        $row = new WorkflowRunWait();
        $row->setRawAttributes([
            'payload' => $original,
        ], true);
        $row->setRawAttributes([
            'payload' => $current,
        ]);
        return $row;
    }
}
