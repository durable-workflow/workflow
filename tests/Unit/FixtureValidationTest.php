<?php

declare(strict_types=1);

namespace Tests\Unit;

use Generator;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestBadConnectionWorkflow;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestWebhookWorkflow;
use Tests\Fixtures\TestWorkflow;

final class FixtureValidationTest extends TestCase
{
    #[DataProvider('invalidConsoleFixtureProvider')]
    public function testFixturesThrowRuntimeExceptionsWhenNotRunningInConsole(
        object $fixture,
        string $expectedMessage,
        mixed ...$arguments
    ): void {
        $app = $this->createMock(Application::class);
        $app->method('runningInConsole')
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $result = $fixture->execute($app, ...$arguments);

        if ($result instanceof Generator) {
            $result->current();
        }
    }

    public static function invalidConsoleFixtureProvider(): iterable
    {
        yield 'activity' => [
            'fixture' => self::instantiateWithoutConstructor(TestActivity::class),
            'expectedMessage' => 'Test activities must run in console.',
        ];

        yield 'other activity' => [
            'fixture' => self::instantiateWithoutConstructor(TestOtherActivity::class),
            'expectedMessage' => 'Test activities must run in console.',
            'other',
        ];

        yield 'workflow' => [
            'fixture' => self::instantiateWithoutConstructor(TestWorkflow::class),
            'expectedMessage' => 'Test workflows must run in console.',
        ];

        yield 'webhook workflow' => [
            'fixture' => self::instantiateWithoutConstructor(TestWebhookWorkflow::class),
            'expectedMessage' => 'Test workflows must run in console.',
        ];

        yield 'bad connection workflow' => [
            'fixture' => self::instantiateWithoutConstructor(TestBadConnectionWorkflow::class),
            'expectedMessage' => 'Test workflows must run in console.',
        ];
    }

    private static function instantiateWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
