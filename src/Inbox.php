<?php

declare(strict_types=1);

namespace Workflow;

final class Inbox
{
    public array $values = [];

    public int $received = 0;

    public int $read = 0;

    public function receive(mixed $value): void
    {
        $this->values[] = $value;
        $this->received++;
    }

    public function hasUnread(): bool
    {
        return $this->received > $this->read;
    }

    public function nextUnread(): mixed
    {
        if (! $this->hasUnread()) {
            return null;
        }

        $value = $this->values[$this->read];
        $this->read++;

        return $value;
    }
}
