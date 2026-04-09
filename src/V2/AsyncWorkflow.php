<?php

declare(strict_types=1);

namespace Workflow\V2;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use Workflow\V2\Attributes\Type;

#[Type('durable-workflow.async')]
final class AsyncWorkflow extends Workflow
{
    protected Container $container;

    public function execute(SerializableClosure $callback): mixed
    {
        $this->container = App::make(Container::class);
        $callable = $callback->getClosure();
        $result = $callable(...$this->resolveMethodDependencies([], new ReflectionFunction($callable)));

        return $result instanceof Generator ? yield from $result : $result;
    }
}
