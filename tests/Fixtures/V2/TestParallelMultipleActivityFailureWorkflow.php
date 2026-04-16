<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-parallel-multiple-activity-failure-workflow')]
final class TestParallelMultipleActivityFailureWorkflow extends Workflow
{
    private string $stage = 'booting';

    private string $message = '';

    public function handle(): array
    {
        $this->stage = 'waiting-for-activities';

        try {
            all([
                fn () => activity(TestNamedFailingActivity::class, 'first failure'),
                fn () => activity(TestNamedFailingActivity::class, 'second failure'),
            ]);

            $this->stage = 'unexpected-success';
        } catch (Throwable $throwable) {
            $this->stage = 'caught-activity-failure';
            $this->message = $throwable->getMessage();
        }

        return [
            'stage' => $this->stage,
            'message' => $this->message,
        ];
    }

    /**
     * @return array{stage: string, message: string}
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
