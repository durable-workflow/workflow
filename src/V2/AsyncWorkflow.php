<?php

declare(strict_types=1);

namespace Workflow\V2;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use Throwable;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\WorkflowFiberContext;

#[Type('durable-workflow.async')]
final class AsyncWorkflow extends Workflow
{
    protected Container $container;

    public function execute(SerializableClosure $callback): mixed
    {
        $this->container = App::make(Container::class);
        $callable = $callback->getClosure();
        $result = $callable(...$this->resolveMethodDependencies([], new ReflectionFunction($callable)));

        if ($result instanceof Generator) {
            return $this->runGeneratorCallback($result);
        }

        return $result;
    }

    private function runGeneratorCallback(Generator $generator): mixed
    {
        $current = WorkflowFiberContext::whileInactive(static fn (): mixed => $generator->current());

        while ($generator->valid()) {
            try {
                $result = WorkflowFiberContext::suspend($current);
            } catch (Throwable $throwable) {
                $current = WorkflowFiberContext::whileInactive(
                    static fn (): mixed => $generator->throw($throwable),
                );

                continue;
            }

            $current = WorkflowFiberContext::whileInactive(static fn (): mixed => $generator->send($result));
        }

        return $generator->getReturn();
    }
}
