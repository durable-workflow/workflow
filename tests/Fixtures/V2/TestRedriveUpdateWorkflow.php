<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-redrive-update-workflow')]
#[Signal('go')]
final class TestRedriveUpdateWorkflow extends Workflow
{
    private string $note = 'none';

    /**
     * @return array{result: string, note: string}
     */
    public function handle(string $name): array
    {
        $greeting = activity(TestRedriveFirstActivity::class, $name);
        await('go');

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'note' => $this->note,
        ];
    }

    #[UpdateMethod('set-note')]
    public function setNote(string $note): string
    {
        $this->note = $note;

        return "noted {$note}";
    }
}
