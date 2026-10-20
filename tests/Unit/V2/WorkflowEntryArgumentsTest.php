<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use ArrayObject;
use Countable;
use Illuminate\Container\Container;
use IteratorAggregate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Workflow\V2\Exceptions\WorkflowEntryArgumentsMismatchException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowEntryArguments;
use Workflow\V2\Workflow;

final class WorkflowEntryArgumentsTest extends TestCase
{
    #[DataProvider('invocations')]
    public function testItChecksPhpEntryTypesAndArgumentPlacementBeforeCallingTheBody(
        string $method,
        array $arguments,
        bool $allowed
    ): void {
        $workflow = new EntryArgumentShapes();
        try {
            WorkflowEntryArguments::assertInvocation($workflow, new ReflectionMethod($workflow, $method), $arguments);
            self::assertTrue($allowed);
        } catch (WorkflowEntryArgumentsMismatchException $exception) {
            self::assertFalse($allowed, $exception->getMessage());
            self::assertSame(EntryArgumentShapes::class, $exception->workflowType);
            self::assertSame($method, $exception->entryMethod);
        }
    }

    public static function invocations(): iterable
    {
        yield 'integer' => ['integer', [7], true];
        yield 'numeric string is not strict integer' => ['integer', ['7'], false];
        yield 'float is not strict integer' => ['integer', [7.1], false];
        yield 'integer widens to float' => ['floating', [7], true];
        yield 'float' => ['floating', [7.1], true];
        yield 'string' => ['text', ['retained'], true];
        yield 'stringable is not strict string' => ['text', [new EntryStringable()], false];
        yield 'boolean' => ['boolean', [false], true];
        yield 'integer is not strict boolean' => ['boolean', [1], false];
        yield 'nullable' => ['nullable', [null], true];
        yield 'required null refuses' => ['integer', [null], false];
        yield 'array' => ['collection', [[
            'retained' => null,
        ]], true];
        yield 'array refuses object' => ['collection', [new stdClass()], false];
        yield 'object' => ['object', [new stdClass()], true];
        yield 'object refuses scalar' => ['object', [7], false];
        yield 'union integer' => ['union', [7], true];
        yield 'union array' => ['union', [[7]], true];
        yield 'union refuses boolean' => ['union', [true], false];
        yield 'intersection' => ['intersection', [new ArrayObject([7])], true];
        yield 'intersection refuses missing interfaces' => ['intersection', [new stdClass()], false];
        yield 'self type' => ['same', [new EntryArgumentShapes()], true];
        yield 'parent type' => ['base', [new EntryArgumentShapes()], true];
        yield 'parent refuses unrelated object' => ['base', [new stdClass()], false];
        yield 'named input' => ['integer', [
            'value' => 7,
        ], true];
        yield 'missing required' => ['integer', [], false];
        yield 'optional default' => ['optional', [], true];
        yield 'legacy trailing input is allowed' => ['integer', [7, 'removed trailing input'], true];
        yield 'unknown name' => ['integer', [
            'renamed' => 7,
        ], false];
        yield 'same position and name' => ['integer', [
            0 => 7,
            'value' => 8,
        ], false];
        yield 'position after name' => ['integer', [
            'value' => 7,
            0 => 8,
        ], false];
        yield 'variadic positions' => ['variadic', [7, 8], true];
        yield 'variadic named' => ['variadic', [
            'one' => 7,
            'two' => 8,
        ], true];
        yield 'variadic mismatched' => ['variadic', [7, '8'], false];
    }

    public function testItsDiagnosticDoesNotDiscloseTheRecordedPayload(): void
    {
        $workflow = new EntryArgumentShapes();
        try {
            WorkflowEntryArguments::assertInvocation(
                $workflow,
                new ReflectionMethod($workflow, 'integer'),
                ['private-payload-value']
            );
            self::fail('The mismatched input must refuse.');
        } catch (WorkflowEntryArgumentsMismatchException $exception) {
            self::assertStringNotContainsString('private-payload-value', $exception->getMessage());
            self::assertSame('argument_type_mismatch', $exception->bindingReason);
            self::assertSame('int', $exception->expectedType);
            self::assertSame('string', $exception->actualType);
        }
    }

    public function testNormalInjectionPreservesTheRecordedScalar(): void
    {
        $workflow = $this->boundWorkflow();
        $entry = new ReflectionMethod($workflow, 'injected');
        $resolved = WorkflowEntryArguments::resolve($workflow, [7], $entry);
        self::assertInstanceOf(stdClass::class, $resolved[0]);
        self::assertSame(7, $resolved[1]);
        WorkflowEntryArguments::assertInvocation($workflow, $entry, $resolved);
    }

    public function testDefaultedDtoCannotReplaceRecordedInput(): void
    {
        $workflow = $this->boundWorkflow();
        try {
            WorkflowEntryArguments::resolve($workflow, [7], new ReflectionMethod($workflow, 'dto'));
            self::fail('Injected defaults must not replace recorded input.');
        } catch (WorkflowEntryArgumentsMismatchException $exception) {
            self::assertSame('recorded_argument_would_be_discarded', $exception->bindingReason);
            self::assertSame('retained-entry-type', $exception->workflowType);
        }
    }

    public function testUnresolvableDtoProducesTheRecoverableBindingReason(): void
    {
        $workflow = $this->boundWorkflow();
        try {
            WorkflowEntryArguments::resolve($workflow, [7], new ReflectionMethod($workflow, 'requiredDto'));
            self::fail('An unresolvable constructor must not reach workflow code.');
        } catch (WorkflowEntryArgumentsMismatchException $exception) {
            self::assertSame('dependency_resolution_failed', $exception->bindingReason);
            self::assertNotNull($exception->getPrevious());
            self::assertSame('retained-entry-type', $exception->workflowType);
        }
    }

    private function boundWorkflow(): EntryArgumentShapes
    {
        $previous = Container::getInstance();
        try {
            Container::setInstance(new Container());
            $workflow = new EntryArgumentShapes();
            $workflow->bindRuntime(new WorkflowRun([
                'workflow_type' => 'retained-entry-type',
            ]));

            return $workflow;
        } finally {
            Container::setInstance($previous);
        }
    }
}

class EntryArgumentShapes extends Workflow
{
    public function integer(int $value): mixed
    {
        return $value;
    }

    public function floating(float $value): mixed
    {
        return $value;
    }

    public function text(string $value): mixed
    {
        return $value;
    }

    public function boolean(bool $value): mixed
    {
        return $value;
    }

    public function nullable(?int $value): mixed
    {
        return $value;
    }

    public function collection(array $value): mixed
    {
        return $value;
    }

    public function object(object $value): mixed
    {
        return $value;
    }

    public function union(int|array $value): mixed
    {
        return $value;
    }

    public function intersection(Countable&IteratorAggregate $value): mixed
    {
        return $value;
    }

    public function same(self $value): mixed
    {
        return $value;
    }

    public function base(parent $value): mixed
    {
        return $value;
    }

    public function optional(int $value = 7): mixed
    {
        return $value;
    }

    public function variadic(int ...$values): mixed
    {
        return $values;
    }

    public function injected(stdClass $service, int $value): mixed
    {
        return [$service, $value];
    }

    public function dto(DefaultArgumentData $data): mixed
    {
        return $data->amount;
    }

    public function requiredDto(RequiredArgumentData $data): mixed
    {
        return $data->amount;
    }
}

class DefaultArgumentData
{
    public function __construct(
        public int $amount = 99
    ) {
    }
}

class RequiredArgumentData
{
    public function __construct(
        public int $amount
    ) {
    }
}

class EntryStringable
{
    public function __toString(): string
    {
        return '7';
    }
}
