<?php

declare(strict_types=1);

namespace Workflow\Exceptions;

use RuntimeException;

final class TransitionNotFound extends RuntimeException
{
    private string $from;

    private string $to;

    private string $modelClass;

    public static function make(string $from, string $to, string $modelClass): self
    {
        $exception = new self("Transition from `{$from}` to `{$to}` on model `{$modelClass}` was not found.");

        $exception->from = $from;
        $exception->to = $to;
        $exception->modelClass = $modelClass;

        return $exception;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }
}
