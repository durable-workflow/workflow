<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use ArgumentCountError;
use Error;
use Exception;
use RuntimeException;
use Tests\TestCase;
use TypeError;
use Workflow\V2\Support\FailureFactory;

final class FailureFactoryRestoreTest extends TestCase
{
    /**
     * Regression for #436. PHP's Throwable interface is implemented independently
     * by Exception and Error (siblings, not parent/child). The restorer used
     * is_subclass_of($class, Error::class) to decide which base-class reflection
     * surface owns the protected message/code/file/line/trace properties — but
     * is_subclass_of returns false when $class IS Error, so an activity that
     * threw a bare Error fell through to Exception's reflection target and
     * raised "Cannot access protected property Error::$message" during replay,
     * stranding the run in waiting.
     *
     * @dataProvider errorSubclassesProvider
     */
    public function testRestoresErrorSubclassesWithoutFallingBackToExceptionBaseClass(string $class, string $message): void
    {
        $payload = FailureFactory::payload(new $class($message));

        $restored = FailureFactory::restoreForReplay($payload);

        $this->assertInstanceOf($class, $restored);
        $this->assertSame($message, $restored->getMessage());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function errorSubclassesProvider(): iterable
    {
        yield 'bare Error' => [Error::class, 'static call against instance method'];
        yield 'TypeError' => [TypeError::class, 'argument 1 must be of type string'];
        yield 'ArgumentCountError' => [ArgumentCountError::class, 'too few arguments to function'];
    }

    public function testRestoresExceptionSubclassesUnchanged(): void
    {
        $original = new RuntimeException('still works for Exception side', 42);

        $restored = FailureFactory::restoreForReplay(FailureFactory::payload($original));

        $this->assertInstanceOf(RuntimeException::class, $restored);
        $this->assertSame('still works for Exception side', $restored->getMessage());
        $this->assertSame(42, $restored->getCode());
    }

    public function testRestoresBaseException(): void
    {
        $original = new Exception('base exception sanity check');

        $restored = FailureFactory::restoreForReplay(FailureFactory::payload($original));

        $this->assertInstanceOf(Exception::class, $restored);
        $this->assertSame('base exception sanity check', $restored->getMessage());
    }
}
