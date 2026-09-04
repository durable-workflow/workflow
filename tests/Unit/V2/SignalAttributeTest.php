<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Attributes\Signal;

final class SignalAttributeTest extends TestCase
{
    public function testNormalizesPortableSignalContractParameters(): void
    {
        $signal = new Signal(' approved-by ', [
            'actor',
            [
                'name' => ' context ',
                'type' => ' array ',
                'allows_null' => false,
            ],
            [
                'name' => 'reason',
                'default' => null,
                'type' => '?string',
            ],
            [
                'name' => 'tags',
                'variadic' => true,
                'type' => 'string',
                'allows_null' => false,
            ],
        ]);

        $this->assertSame('approved-by', $signal->name);
        $this->assertSame([
            [
                'name' => 'actor',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => null,
                'allows_null' => true,
            ],
            [
                'name' => 'context',
                'position' => 1,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'array',
                'allows_null' => false,
            ],
            [
                'name' => 'reason',
                'position' => 2,
                'required' => false,
                'variadic' => false,
                'default_available' => true,
                'default' => null,
                'type' => '?string',
                'allows_null' => true,
            ],
            [
                'name' => 'tags',
                'position' => 3,
                'required' => false,
                'variadic' => true,
                'default_available' => false,
                'default' => null,
                'type' => 'string',
                'allows_null' => false,
            ],
        ], $signal->parameters);
    }

    /**
     * @param array<mixed> $parameters
     */
    #[DataProvider('invalidDefinitions')]
    public function testRejectsInvalidSignalDefinitions(string $name, array $parameters, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        new Signal($name, $parameters);
    }

    /**
     * @return iterable<string, array{string, array<mixed>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty signal name' => ['   ', [], 'signal names must be non-empty'];
        yield 'associative parameter collection' => [
            'approved-by',
            [
                'actor' => 'string',
            ],
            'parameters must be declared as a list',
        ];
        yield 'non-structured parameter' => [
            'approved-by',
            [42],
            'parameter definitions must be strings or arrays',
        ];
        yield 'empty parameter name' => [
            'approved-by',
            [[
                'name' => '   ',
            ]],
            'parameter names must be non-empty',
        ];
        yield 'duplicate parameter name' => [
            'approved-by',
            [
                'actor', [
                    'name' => 'actor',
                ]],
            'declares duplicate parameter [actor]',
        ];
        yield 'variadic parameter with default' => [
            'approved-by',
            [[
                'name' => 'tags',
                'variadic' => true,
                'default' => [],
            ]],
            'variadic parameter [tags] cannot declare a default value',
        ];
        yield 'empty declared type' => [
            'approved-by',
            [[
                'name' => 'actor',
                'type' => '   ',
            ]],
            'type must be a non-empty string when provided',
        ];
        yield 'non-boolean nullable flag' => [
            'approved-by',
            [[
                'name' => 'actor',
                'allows_null' => 1,
            ]],
            'allows_null must be a boolean',
        ];
    }
}
