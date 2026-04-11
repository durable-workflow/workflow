<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Fiber;
use Generator;
use Throwable;
use Workflow\V2\Workflow;

final class WorkflowExecution
{
    private function __construct(
        private readonly ?Generator $generator = null,
        private readonly ?Fiber $fiber = null,
        private mixed $current = null,
        private mixed $return = null,
    ) {
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function start(Workflow $workflow, array $arguments): self
    {
        return self::startCallback(static fn (): mixed => $workflow->execute(...$arguments));
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function startCallback(callable $callback, array $arguments = []): self
    {
        $fiber = new Fiber(static function () use ($callback, $arguments): mixed {
            WorkflowFiberContext::enter();

            try {
                return $callback(...$arguments);
            } finally {
                WorkflowFiberContext::leave();
            }
        });

        $current = $fiber->start();

        if ($fiber->isSuspended()) {
            return new self(fiber: $fiber, current: $current);
        }

        $result = $fiber->getReturn();

        if ($result instanceof Generator) {
            $current = $result->current();

            if (! $result->valid()) {
                return new self(return: $result->getReturn());
            }

            return new self(generator: $result, current: $current);
        }

        return new self(return: $result);
    }

    public function current(): mixed
    {
        return $this->current;
    }

    public function valid(): bool
    {
        if ($this->generator instanceof Generator) {
            return $this->generator->valid();
        }

        if ($this->fiber instanceof Fiber) {
            return ! $this->fiber->isTerminated();
        }

        return false;
    }

    public function send(mixed $value): mixed
    {
        if ($this->generator instanceof Generator) {
            $this->current = $this->generator->send($value);

            if (! $this->generator->valid()) {
                $this->return = $this->generator->getReturn();
            }

            return $this->current;
        }

        if (! $this->fiber instanceof Fiber) {
            return null;
        }

        $result = $this->fiber->resume($value);

        if ($this->fiber->isSuspended()) {
            $this->current = $result;

            return $this->current;
        }

        $this->current = null;
        $this->return = $this->fiber->getReturn();

        return null;
    }

    public function throw(Throwable $throwable): mixed
    {
        if ($this->generator instanceof Generator) {
            $this->current = $this->generator->throw($throwable);

            if (! $this->generator->valid()) {
                $this->return = $this->generator->getReturn();
            }

            return $this->current;
        }

        if (! $this->fiber instanceof Fiber) {
            throw $throwable;
        }

        $result = $this->fiber->throw($throwable);

        if ($this->fiber->isSuspended()) {
            $this->current = $result;

            return $this->current;
        }

        $this->current = null;
        $this->return = $this->fiber->getReturn();

        return null;
    }

    public function getReturn(): mixed
    {
        return $this->return;
    }
}
