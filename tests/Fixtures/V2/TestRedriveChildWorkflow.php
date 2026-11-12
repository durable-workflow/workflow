<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use function Workflow\V2\now;
use Workflow\V2\Workflow;

#[Type('test-redrive-child-workflow')]
final class TestRedriveChildWorkflow extends Workflow
{
    private ?string $timeAfterChild = null;

    /**
     * @return array{result: string, time_after_child: string}
     */
    public function handle(string $name): array
    {
        $greeting = child(TestRedriveGreetingChildWorkflow::class, $name);
        $this->timeAfterChild = now()
            ->toIso8601String();

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'time_after_child' => $this->timeAfterChild,
        ];
    }

    /**
     * @return array{time_after_child: string|null}
     */
    #[QueryMethod]
    public function replayTimes(): array
    {
        return [
            'time_after_child' => $this->timeAfterChild,
        ];
    }
}
