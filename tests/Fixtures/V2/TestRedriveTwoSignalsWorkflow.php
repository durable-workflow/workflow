<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-redrive-two-signals-workflow')]
#[Signal('approve')]
final class TestRedriveTwoSignalsWorkflow extends Workflow
{
    /**
     * @return array{result: string, approvals: list<mixed>}
     */
    public function handle(string $name): array
    {
        $approvals = [await('approve'), await('approve')];
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'approvals' => $approvals,
        ];
    }
}
