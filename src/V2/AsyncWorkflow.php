<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;

#[Type('durable-workflow.async')]
final class AsyncWorkflow extends Workflow
{
    protected Container $container;

    public function handle(SerializableClosure $callback): mixed
    {
        $this->container = App::make(Container::class);
        $callable = $callback->getClosure();
        $result = $callable(...$this->resolveMethodDependencies([], new ReflectionFunction($callable)));

        if ($result instanceof \Generator) {
            throw StraightLineWorkflowRequiredException::forAsyncCallback();
        }

        return $result;
    }
}
