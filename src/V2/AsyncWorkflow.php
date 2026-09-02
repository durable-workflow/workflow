<?php

declare(strict_types=1);

namespace Workflow\V2;

use ReflectionFunction;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Support\InternalAsyncClosurePayload;

#[Type('durable-workflow.async')]
final class AsyncWorkflow extends Workflow
{
    public function handle(AvroBinaryValue $payload): mixed
    {
        $callback = InternalAsyncClosurePayload::decode($payload);
        $callable = $callback->getClosure();
        $result = $callable(...$this->resolveMethodDependencies([], new ReflectionFunction($callable)));

        if ($result instanceof \Generator) {
            throw StraightLineWorkflowRequiredException::forAsyncCallback();
        }

        return $result;
    }
}
