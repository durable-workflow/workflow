<?php

declare(strict_types=1);

namespace Workflow;

final class Outbox
{
    public array $values = [];

    public int $transmitted = 0;

    public int $sent = 0;

    public function send(mixed $value): void
    {
        $this->values[] = $value;
        $this->transmitted++;
    }

    public function nextUnsent(): mixed
    {
        if ($this->transmitted <= $this->sent) {
            return null;
        }

        $value = $this->values[$this->sent];
        $this->sent++;

        return $value;
    }
}
