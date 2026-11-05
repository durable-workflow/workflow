<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-inbox-after-parallel-workflow')]
#[Signal('go')]
#[Signal('done')]
final class TestInboxAfterParallelWorkflow extends Workflow
{
    private ?string $received = null;

    public function handle(bool $parallel): array
    {
        await('go');
        if ($parallel) {
            $results = all([
                static fn () => activity(TestGreetingActivity::class, 'first'),
                static fn () => activity(TestGreetingActivity::class, 'second'),
            ]);
        } else {
            $results = [
                activity(TestGreetingActivity::class, 'first'),
                activity(TestGreetingActivity::class, 'second'),
            ];
        }
        $this->received = $this->inbox('chat')
            ->receiveOne()?->payload_reference;
        await('done');

        return [
            'activities' => $results,
            'received' => $this->received,
        ];
    }

    #[QueryMethod]
    public function received(): ?string
    {
        return $this->received;
    }
}
