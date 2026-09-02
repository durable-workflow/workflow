<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\SignalMethod;
use Workflow\UpdateMethod;
use Workflow\Workflow;
use function Workflow\{activity, await};

final class TestChatBotWorkflow extends Workflow
{
    #[SignalMethod]
    public function send(string $message): void
    {
        $this->inbox->receive($message);
    }

    #[UpdateMethod]
    public function receive()
    {
        return $this->outbox->nextUnsent();
    }

    public function execute()
    {
        $step = 'ask';
        $done = false;
        $message = null;
        while (! $done) {
            if ($step === 'ask') {
                $step = 'answer';
                yield activity(TestChatBotAskActivity::class);
                yield await(fn () => $this->inbox->hasUnread());
                $message = $this->inbox->nextUnread();
            } elseif ($step === 'answer') {
                $step = 'ask';
                $this->outbox->send("You said: {$message}");
                $done = yield activity(TestChatBotAnswerActivity::class, $message);
            }
        }

        return 'completed';
    }
}
