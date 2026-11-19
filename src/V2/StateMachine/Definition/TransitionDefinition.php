<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;

/**
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class TransitionDefinition
{
    public string $key;

    public string $event;

    /**
     * @var non-empty-list<string>
     */
    public array $from;

    public string $to;

    public string $handler;

    public string $handlerVersion;

    public bool $hasWork;

    /**
     * @param non-empty-list<string> $from
     */
    public function __construct(
        string $key,
        string $event,
        array $from,
        string $to,
        string $handler,
        string $handlerVersion,
        bool $hasWork = true,
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
        $this->key = $key;
        $this->event = $event;
        $this->from = array_map(static fn (string $state): string => $state, $from);
        $this->to = $to;
        $this->handler = $handler;
        $this->handlerVersion = $handlerVersion;
        $this->hasWork = $hasWork;
    }

    public function handlerKey(): string
    {
        return json_encode([$this->handler, $this->handlerVersion], JSON_THROW_ON_ERROR);
    }
}
