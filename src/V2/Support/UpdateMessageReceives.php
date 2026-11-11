<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

/**
 * The inbound messages each stream receive in one update handler returned.
 *
 * A live handler records the message ids of every receive call, including
 * empty ones, and they are stored on its `UpdateApplied` event. A replay of
 * that handler returns exactly those messages, in call order, instead of
 * looking them up by workflow step. An update handler and workflow code can
 * consume at the same step, so the step alone cannot tell their rows apart.
 *
 * @internal
 */
final class UpdateMessageReceives
{
    public const PAYLOAD_KEY = 'message_receives';

    private int $next = 0;

    /**
     * @param list<list<int>> $receives
     */
    private function __construct(
        private array $receives,
        private readonly bool $replaying,
    ) {
    }

    public static function recording(): self
    {
        return new self([], false);
    }

    /**
     * The receives an `UpdateApplied` payload recorded, or null for history
     * written before they were recorded.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        if (! array_key_exists(self::PAYLOAD_KEY, $payload)) {
            return null;
        }

        $receives = $payload[self::PAYLOAD_KEY];

        if (! is_array($receives) || ! array_is_list($receives)) {
            throw new LogicException('Update history has malformed message receives.');
        }

        foreach ($receives as $ids) {
            if (! is_array($ids) || ! array_is_list($ids)) {
                throw new LogicException('Update history has malformed message receives.');
            }

            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new LogicException('Update history has malformed message receives.');
                }
            }
        }

        /** @var list<list<int>> $receives */
        return new self($receives, true);
    }

    public function replaying(): bool
    {
        return $this->replaying;
    }

    /**
     * The message ids the next receive call returned when the handler ran
     * live. A call past the recorded ones returns nothing.
     *
     * @return list<int>
     */
    public function next(): array
    {
        return $this->receives[$this->next++] ?? [];
    }

    /**
     * @param list<int> $ids
     */
    public function record(array $ids): void
    {
        $this->receives[] = $ids;
    }

    /**
     * @return list<list<int>>
     */
    public function all(): array
    {
        return $this->receives;
    }
}
