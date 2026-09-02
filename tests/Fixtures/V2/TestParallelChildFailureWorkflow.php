<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parallel-child-failure-workflow')]
final class TestParallelChildFailureWorkflow extends Workflow
{
    private string $stage = 'booting';

    private string $message = '';

    public function handle(int $slowChildSeconds): array
    {
        $this->stage = 'waiting-for-children';

        try {
            all([
                static fn () => child(TestFailingChildWorkflow::class),
                static fn () => child(TestTimerWorkflow::class, $slowChildSeconds),
            ]);

            $this->stage = 'unexpected-success';
        } catch (Throwable $throwable) {
            $this->stage = 'caught-child-failure';
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
