<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\all;
use function Workflow\V2\startActivity;
use function Workflow\V2\startChild;
use Workflow\V2\Workflow;

#[Type('test-mixed-parallel-failure-workflow')]
final class TestMixedParallelFailureWorkflow extends Workflow
{
    private string $stage = 'booting';

    private string $message = '';

    public function execute(int $slowChildSeconds): array
    {
        $this->stage = 'waiting-for-mixed-group';

        try {
            all([
                startActivity(TestFailingActivity::class),
                startChild(TestTimerWorkflow::class, $slowChildSeconds),
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
