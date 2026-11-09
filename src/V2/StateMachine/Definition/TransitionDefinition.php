<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;

final readonly class TransitionDefinition
{
    /**
     * @param non-empty-list<string> $from
     */
    public function __construct(
        public string $key,
        public string $event,
        public array $from,
        public string $to,
        public string $handler,
        public string $handlerVersion,
        public bool $hasWork = true,
    ) {
        foreach ([$key, $event, $to, $handler, $handlerVersion] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Transition identifiers must not be empty.');
            }
        }
        if ($from === [] || ! array_is_list($from)) {
            throw new InvalidArgumentException('A transition requires a non-empty source-state list.');
        }
        foreach ($from as $state) {
            if (! is_string($state) || trim($state) === '') {
                throw new InvalidArgumentException('Source states must be non-empty strings.');
            }
        }
        if (count(array_unique($from)) !== count($from)) {
            throw new InvalidArgumentException('Source states must be unique.');
        }
    }

    public function handlerKey(): string
    {
        return json_encode([$this->handler, $this->handlerVersion], JSON_THROW_ON_ERROR);
    }
}
