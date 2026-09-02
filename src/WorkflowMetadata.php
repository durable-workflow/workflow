<?php

declare(strict_types=1);

namespace Workflow;

final class WorkflowMetadata
{
    /**
     * @param array<int, mixed> $arguments
     */
    public function __construct(
        public array $arguments,
        public WorkflowOptions $options = new WorkflowOptions(),
    ) {
    }

    /**
     * @return array{
     *     arguments: array<int, mixed>,
     *     options: array{connection: ?string, queue: ?string}
     * }
     */
    public function toArray(): array
    {
        return [
            'arguments' => $this->arguments,
            'options' => [
                'connection' => $this->options->connection,
                'queue' => $this->options->queue,
            ],
        ];
    }

    public static function fromSerializedArguments(mixed $serialized): self
    {
        if ($serialized instanceof self) {
            return $serialized;
        }

        if (is_array($serialized)) {
            if (array_key_exists('arguments', $serialized) || array_key_exists('options', $serialized)) {
                $arguments = $serialized['arguments'] ?? [];
                $options = $serialized['options'] ?? [];

                return new self(
                    is_array($arguments) ? array_values($arguments) : [],
                    is_array($options) ? WorkflowOptions::set($options) : new WorkflowOptions(),
                );
            }

            return new self(array_values($serialized));
        }

        return new self([]);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public static function fromStartArguments(array $arguments, ?WorkflowOptions $fallback = null): self
    {
        $options = $fallback;

        foreach ($arguments as $index => $argument) {
            if ($argument instanceof WorkflowOptions) {
                $options = $argument;
                unset($arguments[$index]);
            }
        }

        return new self(array_values($arguments), $options ?? new WorkflowOptions());
    }
}
