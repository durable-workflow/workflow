<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-parallel-activity-failure-workflow')]
final class TestParallelActivityFailureWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?string $message = null;

    public function handle(string $name): array
    {
        $this->stage = 'waiting-for-activities';

        try {
            all([
                static fn () => activity(TestFailingActivity::class),
                static fn () => activity(TestGreetingActivity::class, $name),
            ]);
        } catch (\Throwable $throwable) {
            $this->stage = 'caught-activity-failure';
            $this->message = $throwable->getMessage();

            return [
                'stage' => $this->stage,
                'message' => $this->message,
            ];
        }

        $this->stage = 'completed';

        return [
            'stage' => $this->stage,
            'message' => $this->message,
        ];
    }

    /**
     * @return array{stage: string, message: string|null}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'message' => $this->message,
        ];
    }
}
