<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

/**
 * An update handler receives from a stream while the run waits at a step
 * after the one the update is recorded at.
 */
#[Type('test-inbox-update-receive-workflow')]
#[Signal('go')]
#[Signal('done')]
final class TestInboxUpdateReceiveWorkflow extends Workflow
{
    /**
     * @var list<string|null>
     */
    private array $taken = [];

    /**
     * @return list<string|null>
     */
    public function handle(bool $receiveAfterGo = false): array
    {
        await('go');
        if ($receiveAfterGo) {
            $this->inbox('chat')->receiveOne();
        }
        activity(TestRedriveFirstActivity::class, 'step');
        await('done');

        return $this->taken;
    }

    #[UpdateMethod]
    public function take(): ?string
    {
        $message = $this->inbox('chat')
            ->receiveOne();
        $reference = $message === null ? null : (string) $message->payload_reference;
        $this->taken[] = $reference;

        return $reference;
    }

    /**
     * @return list<string|null>
     */
    #[QueryMethod]
    public function taken(): array
    {
        return $this->taken;
    }
}
