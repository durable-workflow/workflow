<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum MessageConsumeState: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Consumed, self::Failed, self::Expired => true,
            self::Pending => false,
        };
    }
}
