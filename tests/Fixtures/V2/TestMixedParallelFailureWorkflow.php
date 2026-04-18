<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-mixed-parallel-failure-workflow')]
final class TestMixedParallelFailureWorkflow extends Workflow
{
    private string $stage = 'booting';

    private string $message = '';

    public function handle(int $slowChildSeconds): array
    {
        $this->stage = 'waiting-for-mixed-group';

        try {
            all([
                static fn () => activity(TestFailingActivity::class),
                static fn () => child(TestTimerWorkflow::class, $slowChildSeconds),
            ]);

            $this->stage = 'unexpected-success';
        } catch (Throwable $throwable) {
            $this->stage = 'caught-mixed-failure';
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
