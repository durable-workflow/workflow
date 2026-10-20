<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Support\CommandIntent;

final class CommandIntentTest extends TestCase
{
    public function testDigestIsVersionedAndStable(): void
    {
        $digest = CommandIntent::digest(CommandType::Signal, 'changed', [
            1, 'Ünïcødé ☃ 日本', null, [
                'b' => 2,
                'a' => 1,
            ]]);

        $this->assertTrue(CommandIntent::isDigest($digest));
        $this->assertStringStartsWith(CommandIntent::VERSION . ':', $digest);
        $this->assertSame($digest, CommandIntent::digest(CommandType::Signal, 'changed', [
            1, 'Ünïcødé ☃ 日本', null, [
                'a' => 1,
                'b' => 2,
            ]]));
        $this->assertFalse(CommandIntent::isDigest('update-v1:' . hash('sha256', 'x')));
    }

    public static function differentIntents(): iterable
    {
        yield 'type' => [[CommandType::Signal, 'n', [1]], [CommandType::Update, 'n', [1]]];
        yield 'name' => [[CommandType::Signal, 'n', [1]], [CommandType::Signal, 'm', [1]]];
        yield 'int vs float' => [[CommandType::Signal, 'n', [1]], [CommandType::Signal, 'n', [1.0]]];
        yield 'int vs string' => [[CommandType::Signal, 'n', [1]], [CommandType::Signal, 'n', ['1']]];
        yield 'null vs false' => [[CommandType::Signal, 'n', [null]], [CommandType::Signal, 'n', [false]]];
        yield 'null vs empty list' => [[CommandType::Signal, 'n', [null]], [CommandType::Signal, 'n', [[]]]];
        yield 'unicode bytes' => [[CommandType::Signal, 'n', ["e\u{301}"]], [CommandType::Signal, 'n', ["\u{e9}"]]];
        yield 'list vs map' => [[CommandType::Signal, 'n', [[1, 2]]], [
            CommandType::Signal, 'n', [[
                '0' => 1,
                'x' => 2,
            ]]]];
        yield 'int64 edge' => [[CommandType::Signal, 'n', [PHP_INT_MAX]],
            [CommandType::Signal,
            'n',
            [PHP_INT_MAX - 1],
        ]];
        yield 'bytes vs string' => [[CommandType::Signal, 'n', [AvroBinaryValue::fromBytes('ab')]],
            [CommandType::Signal,
            'n',
            ['ab'],
        ]];
        yield 'run target' => [[CommandType::Signal, 'n', [1], 'instance', null],
            [CommandType::Signal, 'n', [1], 'run', 'run-a'],
        ];
        yield 'different run' => [[CommandType::Signal, 'n', [1], 'run', 'run-a'],
            [CommandType::Signal, 'n', [1], 'run', 'run-b'],
        ];
        yield 'nested map value' => [[
            CommandType::Signal, 'n', [[
                'k' => [1],
            ]]], [
                CommandType::Signal, 'n', [[
                    'k' => [2],
                ]]]];
    }

    #[DataProvider('differentIntents')]
    public function testDifferentIntentsDigestDifferently(array $left, array $right): void
    {
        $this->assertNotSame(CommandIntent::digest(...$left), CommandIntent::digest(...$right));
    }

    public function testMapOrderAndAvroMapWrappersDoNotChangeIntent(): void
    {
        $plain = CommandIntent::digest(CommandType::Update, 'rename', [[
            'z' => 1,
            'a' => [2, 3],
        ]]);
        $reordered = CommandIntent::digest(CommandType::Update, 'rename', [[
            'a' => [2, 3],
            'z' => 1,
        ]]);
        $wrapped = CommandIntent::digest(
            CommandType::Update,
            'rename',
            [AvroMapValue::fromPairs([['z', 1], ['a', [2, 3]]])]
        );

        $this->assertSame($plain, $reordered);
        $this->assertSame($plain, $wrapped);
    }

    public function testFloatsKeepTheirShortestRoundTripForm(): void
    {
        $this->assertSame('d0.1', CommandIntent::canonical(0.1));
        $this->assertSame('d1.0', CommandIntent::canonical(1.0));
        $this->assertSame('d-0.0', CommandIntent::canonical(-0.0));
        $this->assertSame('dnan', CommandIntent::canonical(NAN));
        $this->assertSame('d-inf', CommandIntent::canonical(-INF));
        $this->assertSame('i' . PHP_INT_MIN, CommandIntent::canonical(PHP_INT_MIN));
        $this->assertSame('s5:a' . "\x00" . 'b' . "\n" . 'c', CommandIntent::canonical("a\x00b\nc"));
    }

    public function testProtectedValuesContributeTheirDeclaredIntentInsteadOfBytes(): void
    {
        $protected = [
            CommandIntent::PROTECTED_INTENT_KEY => 'plain-digest',
        ];

        $this->assertSame('p12:plain-digest', CommandIntent::canonical($protected));
        $this->assertSame(
            CommandIntent::digest(CommandType::Signal, 'n', [$protected]),
            CommandIntent::digest(CommandType::Signal, 'n', [[
                CommandIntent::PROTECTED_INTENT_KEY => 'plain-digest',
            ]]),
        );
        $this->assertNotSame(
            CommandIntent::canonical($protected),
            CommandIntent::canonical([
                CommandIntent::PROTECTED_INTENT_KEY => 'plain-digest',
                'extra' => 1,
            ]),
        );
    }
}
