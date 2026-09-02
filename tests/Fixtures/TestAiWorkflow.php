<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\SignalMethod;
use Workflow\UpdateMethod;
use Workflow\Workflow;
use function Workflow\{activity, await};

final class TestAiWorkflow extends Workflow
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
        $count = 0;
        while ($count < 2) {
            yield await(fn () => $this->inbox->hasUnread());
            $message = $this->inbox->nextUnread();
            $response = yield activity(TestAiResponseActivity::class, $message);
            $this->outbox->send($response);
            $count++;
        }

        return 'completed';
    }
}
