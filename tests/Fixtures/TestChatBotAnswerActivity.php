<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestChatBotAnswerActivity extends Activity
{
    public function execute(?string $message = null): bool
    {
        if ($message !== null && str_contains($message, 'User')) {
            return true;
        }

        return false;
    }
}
