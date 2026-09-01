<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use ArrayObject;
use Illuminate\Support\Facades\Queue;
use stdClass;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowQueryContract;
use Workflow\V2\WorkflowStub;

final class WorkflowQueryContractTest extends TestCase
{
    public function testItNormalizesPositionalArgumentsFromTheDurableContract(): void
    {
        $run = $this->runWithQueryContract('positional', [
            $this->parameter('name', 'string', required: true),
            $this->parameter('limit', 'int', defaultAvailable: true, default: 10),
            $this->parameter('tags', 'string', variadic: true),
        ]);

        $this->assertSame([
            'arguments' => ['Taylor', 10],
            'validation_errors' => [],
        ], WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', ['Taylor']));

        $this->assertSame([
            'arguments' => ['Taylor', 3, 'one', 'two'],
            'validation_errors' => [],
        ], WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', ['Taylor', 3, 'one', 'two']));

        $invalid = WorkflowQueryContract::validatedArgumentsForRun(
            $run,
            'typed-query',
            [123, 'three', 'valid', 4],
        );

        $this->assertSame([123, 'three', 'valid', 4], $invalid['arguments']);
        $this->assertSame([
            'name' => ['The name argument must be of type string.'],
            'limit' => ['The limit argument must be of type int.'],
            'tags' => ['The tags argument must be of type string.'],
        ], $invalid['validation_errors']);

        $missing = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', []);

        $this->assertSame(['The name argument is required.'], $missing['validation_errors']['name']);
    }

    public function testItRejectsExcessPositionalArguments(): void
    {
        $run = $this->runWithQueryContract('excess', [$this->parameter('name', 'string', required: true)]);

        $result = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', ['Taylor', 'unexpected']);

        $this->assertSame(['Taylor'], $result['arguments']);
        $this->assertSame([
            'arguments' => ['Too many arguments were provided for query [typed-query].'],
        ], $result['validation_errors']);
    }

    public function testItNormalizesNamedDefaultsVariadicsAndUnknownArguments(): void
    {
        $run = $this->runWithQueryContract('named', [
            $this->parameter('name', 'string', required: true),
            $this->parameter('limit', 'int', defaultAvailable: true, default: 10),
            $this->parameter('tags', 'string', variadic: true),
        ]);

        $result = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', [
            'tags' => ['one', 2],
            'name' => 'Taylor',
            'extra' => true,
        ]);

        $this->assertSame(['Taylor', 10, 'one', 2], $result['arguments']);
        $this->assertSame([
            'tags' => ['The tags argument must be of type string.'],
            'extra' => ['Unknown argument [extra].'],
        ], $result['validation_errors']);

        $scalarVariadic = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', [
            'name' => 'Taylor',
            'tags' => 'one',
        ]);

        $this->assertSame(['Taylor', 10, 'one'], $scalarVariadic['arguments']);
        $this->assertSame([], $scalarVariadic['validation_errors']);

        $missing = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', [
            'limit' => 4,
        ]);

        $this->assertSame(['The name argument is required.'], $missing['validation_errors']['name']);
    }

    public function testItValidatesPortableScalarObjectUnionAndIntersectionTypes(): void
    {
        $run = $this->runWithQueryContract('types', [
            $this->parameter('integer', 'int', required: true),
            $this->parameter('floating', 'float', required: true),
            $this->parameter('text', 'string', required: true),
            $this->parameter('flag', 'bool', required: true),
            $this->parameter('items', 'array', required: true),
            $this->parameter('object', 'object', required: true),
            $this->parameter('callback', 'callable', required: true),
            $this->parameter('iterable', 'iterable', required: true),
            $this->parameter('scalar', 'scalar', required: true),
            $this->parameter('truth', 'true', required: true),
            $this->parameter('lie', 'false', required: true),
            $this->parameter('nullable', '?string', required: true, allowsNull: true),
            $this->parameter('union', 'int|string', required: true),
            $this->parameter('intersection', 'Countable&IteratorAggregate', required: true),
            $this->parameter('grouped', '(Countable&IteratorAggregate)|string', required: true),
            $this->parameter('instance', ArrayObject::class, required: true),
            $this->parameter('self', 'self', required: true),
            $this->parameter('static', 'static', required: true),
            $this->parameter('parent', 'parent', required: true),
            $this->parameter('mixed', '(mixed)', required: true),
        ]);
        $object = new stdClass();
        $collection = new ArrayObject(['one']);
        $callback = static fn (): string => 'ok';

        $valid = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', [
            'integer' => 1,
            'floating' => 1,
            'text' => 'value',
            'flag' => true,
            'items' => [],
            'object' => $object,
            'callback' => $callback,
            'iterable' => [],
            'scalar' => 1,
            'truth' => true,
            'lie' => false,
            'nullable' => null,
            'union' => 'value',
            'intersection' => $collection,
            'grouped' => $collection,
            'instance' => $collection,
            'self' => $object,
            'static' => $object,
            'parent' => $object,
            'mixed' => ['value'],
        ]);

        $this->assertSame([], $valid['validation_errors']);

        $invalid = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', [
            'integer' => 1.5,
            'floating' => '1.5',
            'text' => 1,
            'flag' => 1,
            'items' => $object,
            'object' => [],
            'callback' => 'not-callable',
            'iterable' => 1,
            'scalar' => [],
            'truth' => false,
            'lie' => true,
            'nullable' => 1,
            'union' => 1.5,
            'intersection' => $object,
            'grouped' => 1,
            'instance' => $object,
            'self' => [],
            'static' => [],
            'parent' => [],
            'mixed' => $object,
        ]);

        foreach (array_keys($invalid['validation_errors']) as $name) {
            $this->assertSame(
                [sprintf('The %s argument must be of type %s.', $name, $this->declaredTypeFor($name))],
                $invalid['validation_errors'][$name],
            );
        }

        $this->assertSame([
            'integer',
            'floating',
            'text',
            'flag',
            'items',
            'object',
            'callback',
            'iterable',
            'scalar',
            'truth',
            'lie',
            'nullable',
            'union',
            'intersection',
            'grouped',
            'instance',
            'self',
            'static',
            'parent',
        ], array_keys($invalid['validation_errors']));
    }

    public function testItRejectsNullAndImpossibleDeclaredTypes(): void
    {
        $run = $this->runWithQueryContract('null-and-impossible', [
            $this->parameter('required', 'string', required: true),
            $this->parameter('null_only', 'null', required: true, allowsNull: true),
            $this->parameter('never', 'never', required: true),
            $this->parameter('void', 'void', required: true),
        ]);

        $result = WorkflowQueryContract::validatedArgumentsForRun($run, 'typed-query', [
            'required' => null,
            'null_only' => 'not-null',
            'never' => 'value',
            'void' => 'value',
        ]);

        $this->assertSame([
            'required' => ['The required argument cannot be null.'],
            'null_only' => ['The null_only argument must be of type null.'],
            'never' => ['The never argument must be of type never.'],
            'void' => ['The void argument must be of type void.'],
        ], $result['validation_errors']);
    }

    public function testItUsesSafeFallbacksWhenNoDurableOrLoadableContractExists(): void
    {
        $run = $this->runWithQueryContract('fallback', []);

        $this->assertSame([
            'arguments' => ['Taylor'],
            'validation_errors' => [],
        ], WorkflowQueryContract::validatedArgumentsForRun($run, 'unknown-query', ['Taylor']));
        $this->assertSame([
            'arguments' => [],
            'validation_errors' => [
                'arguments' => ['Named arguments require a durable or loadable workflow query contract.'],
            ],
        ], WorkflowQueryContract::validatedArgumentsForRun($run, 'unknown-query', [
            'name' => 'Taylor',
        ]));

        $run->forceFill([
            'workflow_class' => 'Missing\\Workflow\\Definition',
            'workflow_type' => 'missing-workflow-definition',
        ])->save();
        $coldRun = WorkflowRun::query()->findOrFail($run->id);

        $this->assertSame([
            'arguments' => ['Taylor'],
            'validation_errors' => [],
        ], WorkflowQueryContract::validatedArgumentsForRun($coldRun, 'unknown-query', ['Taylor']));
        $this->assertSame([
            'arguments' => [],
            'validation_errors' => [
                'arguments' => ['Named arguments require a durable or loadable workflow query contract.'],
            ],
        ], WorkflowQueryContract::validatedArgumentsForRun($coldRun, 'unknown-query', [
            'name' => 'Taylor',
        ]));
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    private function runWithQueryContract(string $suffix, array $parameters): WorkflowRun
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-contract-' . $suffix);
        $workflow->start();
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();
        $payload = $started->payload;
        $payload['declared_queries'] = ['typed-query'];
        $payload['declared_query_contracts'] = [[
            'name' => 'typed-query',
            'parameters' => $parameters,
        ]];
        $started->forceFill([
            'payload' => $payload,
        ])->save();

        return WorkflowRun::query()->findOrFail($run->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(
        string $name,
        string $type,
        bool $required = false,
        bool $defaultAvailable = false,
        mixed $default = null,
        bool $variadic = false,
        bool $allowsNull = false,
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'default_available' => $defaultAvailable,
            'default' => $default,
            'variadic' => $variadic,
            'allows_null' => $allowsNull,
        ];
    }

    private function declaredTypeFor(string $name): string
    {
        return match ($name) {
            'integer' => 'int',
            'floating' => 'float',
            'text' => 'string',
            'flag' => 'bool',
            'items' => 'array',
            'object' => 'object',
            'callback' => 'callable',
            'iterable' => 'iterable',
            'scalar' => 'scalar',
            'truth' => 'true',
            'lie' => 'false',
            'nullable' => '?string',
            'union' => 'int|string',
            'intersection' => 'Countable&IteratorAggregate',
            'grouped' => '(Countable&IteratorAggregate)|string',
            'instance' => ArrayObject::class,
            'self' => 'self',
            'static' => 'static',
            'parent' => 'parent',
        };
    }
}
