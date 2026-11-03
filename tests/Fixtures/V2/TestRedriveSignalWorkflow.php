<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\now;
use Workflow\V2\Workflow;

#[Type('test-redrive-signal-workflow')]
#[Signal('approve')]
final class TestRedriveSignalWorkflow extends Workflow
{
    private ?string $timeAfterSignal = null;

    /**
     * @return array{result: string, approved_by: mixed, time_after_signal: string}
     */
    public function handle(string $name): array
    {
        $approvedBy = await('approve');
        $this->timeAfterSignal = now()
            ->toIso8601String();
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'approved_by' => $approvedBy,
            'time_after_signal' => $this->timeAfterSignal,
        ];
    }

    #[QueryMethod]
    public function timeAfterSignal(): ?string
    {
        return $this->timeAfterSignal;
    }
}
